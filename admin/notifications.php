<?php
/**
 * Notification log + push-token admin.
 *
 * One stop for "did this customer get the cyclone-warning SMS?":
 * filterable view of notification_log plus the device_tokens table
 * (FCM registrations from the native app).
 *
 * Read-mostly. The only mutation here is revoking a stale push token
 * — handy when a customer says "stop sending pushes to my old phone".
 */

declare(strict_types=1);

$page_title = 'Notifications';
$active_key = 'notifications';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/notifications.php';
require_once __DIR__ . '/../auth/csv.php';

$h = fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

$debug_mode = !empty($_GET['debug']) && acl_can($user, 'customers.write');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    acl_require('customers.write');
    $action = $_POST['action'] ?? '';
    if ($action === 'revoke_token') {
        $tid = (int)($_POST['token_id'] ?? 0);
        if ($tid > 0) {
            device_token_revoke($tid);
            audit_log('push.token_revoke', ['target_type' => 'device_token', 'target_id' => $tid]);
            flash('success', 'Token revoked. The next app launch on that device will register a fresh one.');
        }
        header('Location: /admin/notifications.php');
        exit;
    }
    if ($action === 'push_debug') {
        $target = (int)($_POST['target_user_id'] ?? 0);
        if ($target <= 0) $target = (int)($user['id'] ?? 0);
        $diag = notify_push_diag($target);
        audit_log('push.debug', [
            'target_type' => 'user',
            'target_id'   => $target,
            'meta'        => ['ok' => $diag['ok'], 'reason' => $diag['reason']],
        ]);
        $_SESSION['_push_debug_result'] = [
            'target' => $target,
            'diag'   => $diag,
            'at'     => time(),
        ];
        header('Location: /admin/notifications.php?debug=1#push-debug');
        exit;
    }
    elseif ($action === 'push_debug') {
        require_once __DIR__ . '/../auth/channels/push.php';
        $target_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_id <= 0) $target_id = (int)$user['id'];
        $debug = push_debug($target_id);
        $debug['target_user_id'] = $target_id;
        $_SESSION['_push_debug'] = $debug;
        audit_log('push.debug', [
            'target_type' => 'user', 'target_id' => $target_id,
            'meta' => ['ok' => $debug['ok'], 'sent' => $debug['sent'], 'failed' => $debug['failed']],
        ]);
        header('Location: /admin/notifications.php?debug=1#push-debug');
        exit;
    }
    header('Location: /admin/notifications.php');
    exit;
}

$filters = [
    'channel'  => trim((string)($_GET['channel']  ?? '')),
    'status'   => trim((string)($_GET['status']   ?? '')),
    'template' => trim((string)($_GET['template'] ?? '')),
    'user_id'  => (int)($_GET['user_id'] ?? 0),
    'search'   => trim((string)($_GET['search']   ?? '')),
    'from'     => trim((string)($_GET['from']     ?? '')),
    'to'       => trim((string)($_GET['to']       ?? '')),
];
$valid_date = fn ($s) => $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
if (!$valid_date($filters['from'])) $filters['from'] = '';
if (!$valid_date($filters['to']))   $filters['to']   = '';

if (($_GET['export'] ?? '') === 'csv') {
    $rows = notify_search($filters, 50000);
    audit_log('notifications.export', ['meta' => ['rows' => count($rows), 'filters' => $filters]]);
    csv_download('notifications', $rows, [
        'sent_at','channel','template','status','recipient',
        'subject','error','cost_zar','user_id','username','client_name',
    ]);
}

$rows  = notify_search($filters, 500);
$stats = notify_stats(30);

$pdo = pdo();

// Recently active push tokens — handy alongside the log for debugging.
$push_tokens = [];
try {
    $stmt = $pdo->query(
        "SELECT t.*, u.username, u.name AS client_name, u.role AS user_role
           FROM device_tokens t
           LEFT JOIN users u ON u.id = t.user_id
          ORDER BY t.is_active DESC, t.last_seen_at DESC
          LIMIT 200"
    );
    $push_tokens = $stmt->fetchAll();
} catch (Throwable $e) {
    // device_tokens table may not exist yet on a deployment that hasn't
    // run migration phase32 — fall back to an empty list.
    $push_tokens = [];
}

// Distinct templates and users seen recently for the filter dropdowns.
$templates_seen = $pdo->query(
    "SELECT template, COUNT(*) c FROM notification_log
      WHERE sent_at >= (NOW() - INTERVAL 90 DAY) AND template <> ''
      GROUP BY template ORDER BY template ASC"
)->fetchAll();
$users_seen = $pdo->query(
    "SELECT DISTINCT u.id, u.username, u.name
       FROM notification_log n JOIN users u ON u.id = n.user_id
      WHERE n.sent_at >= (NOW() - INTERVAL 90 DAY)
      ORDER BY u.username ASC LIMIT 500"
)->fetchAll();

$status_pill = [
    'queued'  => 'status-open',
    'sent'    => 'status-paid',
    'failed'  => 'status-overdue',
    'skipped' => 'status-cancelled',
];
$channels_used = ['email', 'sms', 'whatsapp', 'push', 'slack', 'webhook'];
$statuses_used = ['queued', 'sent', 'failed', 'skipped'];

$push_configured = !empty(notify_load_config()['push']['enabled']);

// "Debug push" card. Pulled from session so the redirect-after-POST
// pattern still gives the operator the trace from the previous run.
$push_debug_result = null;
if ($debug_mode && !empty($_SESSION['_push_debug_result'])) {
    $push_debug_result = $_SESSION['_push_debug_result'];
    unset($_SESSION['_push_debug_result']);
}

// Quick user picker for the debug send. Limit to a sensible set so the
// dropdown stays usable on a deployment with thousands of clients —
// staff first, then anyone with an active push token.
$debug_user_options = [];
if ($debug_mode) {
    try {
        $stmt = $pdo->query(
            "SELECT u.id, u.username, u.name, u.role,
                    EXISTS(SELECT 1 FROM device_tokens t
                            WHERE t.user_id = u.id AND t.is_active = 1) AS has_token
               FROM users u
              WHERE u.role IN ('super_admin','admin','technician','noc_readonly')
                 OR EXISTS(SELECT 1 FROM device_tokens t
                            WHERE t.user_id = u.id AND t.is_active = 1)
              ORDER BY has_token DESC, u.username ASC
              LIMIT 200"
        );
        $debug_user_options = $stmt->fetchAll();
    } catch (Throwable $e) {
        $debug_user_options = [];
    }
}
?>

<div class="portal-head">
  <h1>Notifications</h1>
  <p class="portal-sub">
    Delivery audit for every email, SMS, WhatsApp and push the system has sent.
    The in-app inbox lives at <a href="/admin/inbox.php">Inbox</a> — that's
    operator-facing pings (outage alerts, push-to-radio results, drift).
  </p>
</div>

<!-- Per-channel rollups (last 30 days) -->
<div class="card-grid">
  <?php foreach ($channels_used as $ch):
    $tot = (int)($stats[$ch]['total'] ?? 0);
    $sent = (int)($stats[$ch]['sent']    ?? 0);
    $fail = (int)($stats[$ch]['failed']  ?? 0);
  ?>
    <div class="portal-card">
      <span class="card-label"><?= $h(ucfirst($ch)) ?></span>
      <div class="card-num" style="font-size:1.6rem;color:<?= $tot > 0 ? 'var(--accent)' : 'var(--text-muted)' ?>;"><?= $tot ?></div>
      <p class="card-sub muted">
        <?= $sent ?> sent &middot; <?= $fail ?> failed
        <br><small>last 30 days</small>
      </p>
    </div>
  <?php endforeach; ?>
</div>

<div class="portal-card">
  <form method="get" class="form form-grid">
    <div class="field">
      <label>Channel</label>
      <select name="channel">
        <option value="">— any —</option>
        <?php foreach ($channels_used as $ch): ?>
          <option value="<?= $h($ch) ?>" <?= $filters['channel'] === $ch ? 'selected' : '' ?>><?= $h(ucfirst($ch)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Status</label>
      <select name="status">
        <option value="">— any —</option>
        <?php foreach ($statuses_used as $s): ?>
          <option value="<?= $h($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $h(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Template</label>
      <select name="template">
        <option value="">— any —</option>
        <?php foreach ($templates_seen as $t): ?>
          <option value="<?= $h($t['template']) ?>" <?= $filters['template'] === $t['template'] ? 'selected' : '' ?>>
            <?= $h($t['template']) ?> (<?= (int)$t['c'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>User</label>
      <select name="user_id">
        <option value="0">— anyone —</option>
        <?php foreach ($users_seen as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $filters['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
            <?= $h($u['username']) ?><?= $u['name'] ? ' — ' . $h($u['name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>From</label>
      <input type="date" name="from" value="<?= $h($filters['from']) ?>">
    </div>
    <div class="field">
      <label>To</label>
      <input type="date" name="to" value="<?= $h($filters['to']) ?>">
    </div>
    <div class="field" style="grid-column:1/-1;">
      <label>Search subject / recipient</label>
      <input type="text" name="search" value="<?= $h($filters['search']) ?>" placeholder="e.g. outage, +27815…">
    </div>
    <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/admin/notifications.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php $qs = http_build_query(array_filter($filters + ['export' => 'csv'], fn ($v) => $v !== '' && $v !== 0)); ?>
      <a href="/admin/notifications.php?<?= $h($qs) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Recent deliveries <span class="muted small">(<?= count($rows) ?> shown)</span></h2>
  <?php if (!$rows): ?>
    <div class="empty-state">
      <div class="empty-icon">✉</div>
      <h3>No matches</h3>
      <p>Nothing matches the current filters. Try widening the date range or clearing the channel.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Sent</th>
          <th>User</th>
          <th>Channel</th>
          <th>Template</th>
          <th>Subject / recipient</th>
          <th>Status</th>
          <th>Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $cls = $status_pill[$r['status']] ?? 'status-open';
        ?>
          <tr>
            <td class="muted small"><?= $h(substr((string)$r['sent_at'], 0, 16)) ?></td>
            <td>
              <?php if (!empty($r['user_id'])): ?>
                <a href="/admin/client-edit.php?id=<?= (int)$r['user_id'] ?>"><?= $h($r['username'] ?: '#' . $r['user_id']) ?></a>
                <?php if (!empty($r['client_name'])): ?>
                  <br><small class="muted"><?= $h($r['client_name']) ?></small>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted small">—</span>
              <?php endif; ?>
            </td>
            <td><?= $h(ucfirst((string)$r['channel'])) ?></td>
            <td><small class="muted"><?= $h($r['template'] ?: '—') ?></small></td>
            <td>
              <?= $h($r['subject'] ?: '—') ?>
              <br><small class="muted"><?= $h($r['recipient'] ?: '—') ?></small>
              <?php if (!empty($r['error'])): ?>
                <br><small style="color:var(--danger);"><?= $h(mb_strimwidth((string)$r['error'], 0, 80, '…')) ?></small>
              <?php endif; ?>
            </td>
            <td><span class="status-pill <?= $h($cls) ?>"><?= $h($r['status']) ?></span></td>
            <td class="muted small"><?= $r['cost_zar'] !== null ? 'R' . number_format((float)$r['cost_zar'], 4) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Push registrations <span class="muted small">(<?= count($push_tokens) ?>)</span></h2>
  <?php if (!$push_configured): ?>
    <div class="alert alert-warning" style="margin-bottom:14px;">
      <strong>Push channel not configured yet.</strong> Add a
      <code>notify_push</code> block to <code>data/db.local.php</code>
      with your Firebase project id and service-account JSON to start
      delivering. Tokens registered below will queue silently until then.
    </div>
  <?php endif; ?>
  <?php if (!$push_tokens): ?>
    <p class="muted">
      No device tokens registered yet. The native app posts to the API
      endpoint on launch / login to register; once that's wired up, every
      install will appear here with platform, app version and last-seen.
    </p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>User</th>
          <th>Platform</th>
          <th>App version</th>
          <th>Device label</th>
          <th>Registered</th>
          <th>Last seen</th>
          <th>State</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($push_tokens as $t):
          $active = (int)$t['is_active'] === 1;
        ?>
          <tr<?= $active ? '' : ' style="opacity:.55;"' ?>>
            <td>
              <?php if (!empty($t['user_id'])): ?>
                <a href="/admin/client-edit.php?id=<?= (int)$t['user_id'] ?>"><?= $h($t['username'] ?: '#' . $t['user_id']) ?></a>
                <?php if (!empty($t['client_name'])): ?>
                  <br><small class="muted"><?= $h($t['client_name']) ?></small>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted small">—</span>
              <?php endif; ?>
            </td>
            <td><?= $h($t['platform']) ?></td>
            <td><small class="muted"><?= $h($t['app_version'] ?: '—') ?></small></td>
            <td><small class="muted"><?= $h($t['device_label'] ?: '—') ?></small></td>
            <td class="muted small"><?= $h(substr((string)$t['registered_at'], 0, 16)) ?></td>
            <td class="muted small"><?= $h(substr((string)$t['last_seen_at'], 0, 16)) ?></td>
            <td>
              <?php if ($active): ?>
                <span class="status-pill status-paid">active</span>
              <?php else: ?>
                <span class="status-pill status-cancelled">revoked</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($active): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="revoke_token">
                  <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"
                          onclick="return confirm('Stop sending pushes to this device?');">Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <p class="muted small" style="margin-top:14px;">
    Token registration runs through the public API at
    <code>POST /api/v1/push/register</code> (auth: customer session or API token).
    Stale tokens auto-revoke when FCM returns <code>404 UNREGISTERED</code>.
  </p>
  <?php if (acl_can($user, 'customers.write') && !$debug_mode): ?>
    <p class="muted small" style="margin-top:6px;">
      Push not delivering? Open <a href="/admin/notifications.php?debug=1#push-debug">debug mode</a>
      to trace the FCM pipeline step-by-step.
    </p>
  <?php endif; ?>
</div>

<?php if ($debug_mode): ?>
<div class="portal-card" id="push-debug">
  <h2>Debug push</h2>
  <p class="muted">
    Step-by-step trace of the FCM pipeline plus a real test send to the
    target user's registered devices. Failures show the FCM HTTP code
    and raw response so you can read the reason directly.
  </p>

  <form method="post" class="form" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="push_debug">
    <div class="field" style="flex:1;min-width:260px;">
      <label>Target user</label>
      <select name="target_user_id">
        <option value="<?= (int)($user['id'] ?? 0) ?>">— me (<?= $h($user['name'] ?: $user['username']) ?>) —</option>
        <?php foreach ($debug_user_options as $u):
          if ((int)$u['id'] === (int)($user['id'] ?? 0)) continue;
          $label = $u['username'] . ($u['name'] ? ' — ' . $u['name'] : '')
                 . (!empty($u['has_token']) ? '' : ' (no active token)');
        ?>
          <option value="<?= (int)$u['id'] ?>"><?= $h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Run diagnostic &amp; send test</button>
    </div>
  </form>

  <?php if ($push_debug_result):
    $diag = $push_debug_result['diag'];
    $ok   = !empty($diag['ok']);
  ?>
    <div class="alert <?= $ok ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:14px;">
      <strong>Result:</strong>
      <span class="status-pill <?= $ok ? 'status-paid' : 'status-overdue' ?>">
        <?= $ok ? 'sent' : 'failed' ?>
      </span>
      <?php if (!$ok && !empty($diag['reason'])): ?>
        <div style="margin-top:8px;"><strong>Stopped at:</strong> <?= $h($diag['reason']) ?></div>
      <?php endif; ?>
    </div>

    <h3 style="margin-top:0;">Pipeline trace</h3>
    <table class="data-table" style="margin-bottom:18px;">
      <thead><tr><th style="width:160px;">Step</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ((array)$diag['trace'] as $row):
          $row_ok = !empty($row['ok']);
        ?>
          <tr>
            <td>
              <span class="status-pill <?= $row_ok ? 'status-paid' : 'status-overdue' ?>">
                <?= $row_ok ? 'ok' : 'fail' ?>
              </span>
              &nbsp;<code><?= $h((string)($row['step'] ?? '?')) ?></code>
            </td>
            <td>
              <?php if (!$row_ok && !empty($row['error'])): ?>
                <div style="color:var(--danger);margin-bottom:6px;">
                  <?= $h((string)$row['error']) ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($row['detail'])): ?>
                <pre style="white-space:pre-wrap;word-break:break-word;font-size:.85rem;margin:0;background:var(--card-bg-2,rgba(255,255,255,.04));padding:8px;border-radius:6px;"><?= $h(json_encode(notify_push_redact((array)$row['detail']), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!empty($diag['send'])): ?>
      <h3>Test send result</h3>
      <table class="data-table">
        <thead>
          <tr>
            <th>Token</th>
            <th>Platform</th>
            <th>Device</th>
            <th>HTTP</th>
            <th>Response</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$diag['send'] as $s):
            $sok = !empty($s['ok']);
          ?>
            <tr>
              <td>
                #<?= (int)$s['token_id'] ?>
                <span class="status-pill <?= $sok ? 'status-paid' : 'status-overdue' ?>" style="margin-left:6px;">
                  <?= $sok ? 'sent' : 'failed' ?>
                </span>
              </td>
              <td><?= $h((string)$s['platform']) ?></td>
              <td><small class="muted"><?= $h($s['device_label'] ?: '—') ?></small></td>
              <td><?= (int)$s['http'] ?></td>
              <td>
                <?php if (!empty($s['curl_error'])): ?>
                  <div style="color:var(--danger);margin-bottom:6px;">curl: <?= $h((string)$s['curl_error']) ?></div>
                <?php endif; ?>
                <?php if (!empty($s['body'])): ?>
                  <pre style="white-space:pre-wrap;word-break:break-word;font-size:.8rem;margin:0;background:var(--card-bg-2,rgba(255,255,255,.04));padding:8px;border-radius:6px;"><?= $h((string)$s['body']) ?></pre>
                <?php else: ?>
                  <span class="muted small">— empty —</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted small">
      Pick a user and run a test. The diagnostic checks <code>notify_push</code>
      config, loads the service account, normalises the private key, signs a
      JWT, exchanges it for an access token at Google, and finally fires a
      real FCM message. Each step's status is shown above.
    </p>
  <?php endif; ?>

  <p class="muted small" style="margin-top:14px;">
    The trace never echoes the private key — only its length and PEM
    header. Audit log records every run under <code>push.debug</code>.
    <a href="/admin/notifications.php">Exit debug mode</a>.
  </p>
</div>
<?php endif; ?>

<div class="portal-card" id="push-debug">
  <h2>Debug push</h2>
  <p class="muted small" style="margin-top:0;">
    Step-by-step trace of the FCM pipeline plus a real test send to the
    target user's registered devices. Failures show the FCM HTTP code and
    raw response so you can read the reason directly.
  </p>
  <form method="post" class="form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="push_debug">
    <div class="field" style="flex:1;min-width:240px;">
      <label>Target user</label>
      <select name="target_user_id">
        <option value="<?= (int)$user['id'] ?>">— me (<?= $h($user['username'] ?? '') ?>) —</option>
        <?php
          $targets = $pdo->query(
            "SELECT u.id, u.username, u.name, u.role, COUNT(t.id) AS device_count
               FROM users u
               LEFT JOIN device_tokens t ON t.user_id = u.id AND t.is_active = 1
              GROUP BY u.id
             HAVING device_count > 0 OR u.role <> 'client'
              ORDER BY u.role, u.username
              LIMIT 500"
          )->fetchAll();
        ?>
        <?php foreach ($targets as $t): ?>
          <option value="<?= (int)$t['id'] ?>">
            <?= $h($t['username']) ?> &middot; <?= $h($t['role']) ?> &middot; <?= (int)$t['device_count'] ?> device<?= (int)$t['device_count'] === 1 ? '' : 's' ?>
            <?= $t['name'] ? '— ' . $h($t['name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Run diagnostic &amp; send test</button>
    </div>
  </form>

  <?php
    $debug = null;
    if (($_GET['debug'] ?? '') === '1') {
        $debug = $_SESSION['_push_debug'] ?? null;
        unset($_SESSION['_push_debug']);
    }
  ?>
  <?php if ($debug): ?>
    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">
    <h3 style="margin-top:0;">
      Result:
      <?php if ($debug['ok']): ?>
        <span class="status-pill status-paid">delivered to <?= (int)$debug['sent'] ?> device<?= $debug['sent'] === 1 ? '' : 's' ?></span>
      <?php else: ?>
        <span class="status-pill status-overdue">failed</span>
      <?php endif; ?>
    </h3>
    <?php if (!empty($debug['error'])): ?>
      <div class="alert alert-error" style="margin:12px 0;">
        <strong>Stopped at:</strong> <?= $h($debug['error']) ?>
      </div>
    <?php endif; ?>

    <h4 style="margin-bottom:6px;">Pipeline trace</h4>
    <ol class="kv" style="margin:0 0 14px;padding-left:20px;">
      <?php foreach ($debug['log'] as $entry): ?>
        <li>
          <strong><?= $h($entry['step']) ?></strong>
          <?php $detail = $entry; unset($detail['step']); ?>
          <code style="display:block;background:var(--bg-elev);padding:6px 10px;border-radius:6px;margin-top:4px;font-size:.78rem;">
            <?= $h(json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?>
          </code>
        </li>
      <?php endforeach; ?>
    </ol>

    <?php if (!empty($debug['results'])): ?>
      <h4 style="margin-bottom:6px;">Per-device results</h4>
      <div class="table-scroll">
        <table class="data-table">
          <thead>
            <tr>
              <th>Token</th>
              <th>Platform</th>
              <th>Device</th>
              <th>HTTP</th>
              <th>Response</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($debug['results'] as $r): ?>
              <tr>
                <td><small class="muted">#<?= (int)$r['token_id'] ?> &middot; <?= $h($r['token_preview']) ?></small></td>
                <td><?= $h($r['platform']) ?></td>
                <td><small class="muted"><?= $h($r['device_label'] ?: '—') ?></small></td>
                <td>
                  <?php if ($r['ok']): ?>
                    <span class="status-pill status-paid"><?= (int)$r['http'] ?></span>
                  <?php else: ?>
                    <span class="status-pill status-overdue"><?= (int)$r['http'] ?: 'ERR' ?></span>
                  <?php endif; ?>
                </td>
                <td><pre style="margin:0;white-space:pre-wrap;font-size:.75rem;max-width:520px;"><?= $h($r['curl_error'] ? 'curl error: '.$r['curl_error'] : ($r['response'] ?: '(empty)')) ?></pre></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
