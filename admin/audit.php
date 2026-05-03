<?php
$page_title = 'Audit log';
$active_key = 'audit';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/csv.php';
require_once __DIR__ . '/../auth/wireless.php';

$show_wireless = (string)($_GET['q'] ?? '') === 'wireless'
              || ($_GET['action'] ?? '') === 'wireless'
              || isset($_GET['wireless']);

$action_filter = trim((string)($_GET['action'] ?? ''));
$user_filter   = (int)($_GET['user_id'] ?? 0);
$limit         = (int)($_GET['limit'] ?? 200);
if ($limit < 25)   $limit = 25;
if ($limit > 5000) $limit = 5000;

// Date range. Defaults to last 30 days; both bounds are optional.
$from_filter = trim((string)($_GET['from'] ?? ''));
$to_filter   = trim((string)($_GET['to']   ?? ''));
$valid_date  = fn($s) => $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$from_iso = $valid_date($from_filter) ? $from_filter : null;
$to_iso   = $valid_date($to_filter)   ? $to_filter   : null;

// CSV export — same filter set, capped at 50 000 rows.
if (($_GET['export'] ?? '') === 'csv') {
    $export_rows = audit_recent(50000, $action_filter ?: null, $user_filter ?: null, $from_iso, $to_iso);
    audit_log('audit.export', ['target_type' => 'audit_log', 'meta' => ['rows' => count($export_rows)]]);
    csv_download('audit-log', $export_rows, [
        'created_at', 'username', 'user_id', 'action',
        'target_type', 'target_id', 'meta', 'ip_address',
    ]);
}

$rows = audit_recent($limit, $action_filter ?: null, $user_filter ?: null, $from_iso, $to_iso);

// Build a list of distinct actions seen recently for the filter dropdown.
$actions_seen = pdo()->query(
    "SELECT action, COUNT(*) AS c
     FROM audit_log
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
     GROUP BY action
     ORDER BY action ASC"
)->fetchAll();

$users_seen = pdo()->query(
    "SELECT DISTINCT user_id, username
     FROM audit_log
     WHERE user_id IS NOT NULL
     ORDER BY username ASC"
)->fetchAll();
?>

<div class="portal-head">
  <h1>Audit log</h1>
  <p class="portal-sub">Who did what, when. Logins, password changes, 2FA activity, account mutations and admin actions land here.</p>
</div>

<div class="portal-card">
  <form method="get" class="form form-grid">
    <div class="field">
      <label>Action contains</label>
      <input type="text" name="action" value="<?= htmlspecialchars($action_filter, ENT_QUOTES) ?>" placeholder="e.g. login, password, user.delete">
    </div>
    <div class="field">
      <label>User</label>
      <select name="user_id">
        <option value="0">— anyone —</option>
        <?php foreach ($users_seen as $u): ?>
          <option value="<?= (int)$u['user_id'] ?>" <?= $user_filter === (int)$u['user_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($u['username'] ?? '#' . $u['user_id']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from_filter, ENT_QUOTES) ?>">
    </div>
    <div class="field">
      <label>To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to_filter, ENT_QUOTES) ?>">
    </div>
    <div class="field">
      <label>Limit</label>
      <select name="limit">
        <?php foreach ([100, 200, 500, 1000, 5000] as $n): ?>
          <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/admin/audit.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php
        // Build the export URL with the same filters
        $export_qs = http_build_query(array_filter([
          'action'  => $action_filter,
          'user_id' => $user_filter ?: null,
          'from'    => $from_filter,
          'to'      => $to_filter,
          'export'  => 'csv',
        ]));
      ?>
      <a href="/admin/audit.php?<?= htmlspecialchars($export_qs) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
    </div>
  </form>
</div>

<?php
$wcl_rows = pdo()->prepare(
    "SELECT l.*, j.payload_json, u.username AS actor_username, d.name AS device_name
       FROM wireless_change_log l
       LEFT JOIN wireless_change_jobs j ON j.id = l.job_id
       LEFT JOIN users u   ON u.id = l.actor_user_id
       LEFT JOIN devices d ON d.id = l.device_id
      ORDER BY l.occurred_at DESC, l.id DESC
      LIMIT 100"
);
$wcl_rows->execute();
$wcl_rows = $wcl_rows->fetchAll();
?>
<?php if ($show_wireless || $wcl_rows): ?>
<div class="portal-card" id="wireless-changes">
  <h2>Wireless config changes
    <small class="muted">(<?= count($wcl_rows) ?>, last 100 from <code>wireless_change_log</code>)</small>
  </h2>
  <?php if (!$wcl_rows): ?>
    <small class="muted">No push-to-radio activity yet. Queue one from /admin/sector-edit.php or /admin/freq-planner.php.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>When</th><th>Actor</th><th>Job</th><th>Action</th><th>Device</th><th>Payload</th><th>Result</th><th>Error</th></tr></thead>
      <tbody>
        <?php foreach ($wcl_rows as $r): ?>
          <tr>
            <td class="muted small"><?= htmlspecialchars(substr((string)$r['occurred_at'], 0, 19)) ?></td>
            <td>
              <?= !empty($r['actor_username'])
                ? '<strong>' . htmlspecialchars($r['actor_username']) . '</strong>'
                : '<span class="muted">worker</span>' ?>
            </td>
            <td>
              <?php if (!empty($r['job_id'])): ?>
                <code>#<?= (int)$r['job_id'] ?></code>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td><code><?= htmlspecialchars($r['action']) ?></code></td>
            <td><small><?= htmlspecialchars($r['device_name'] ?? ($r['scope'] . '#' . $r['scope_id'])) ?></small></td>
            <td class="muted small"><code><?= htmlspecialchars((string)($r['after_json'] ?? $r['payload_json'] ?? '')) ?></code></td>
            <td>
              <?php if ($r['success']): ?>
                <span style="display:inline-block;background:#0c8;color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;">ok</span>
              <?php else: ?>
                <span style="display:inline-block;background:#d44;color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;">fail</span>
              <?php endif; ?>
            </td>
            <td class="muted small" style="color:#d44;"><?= htmlspecialchars((string)$r['error']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($action_filter === '' && $user_filter === 0 && !empty($actions_seen)): ?>
  <div class="portal-card">
    <h2>Recent actions (last 90 days)</h2>
    <p class="inline-form" style="margin:0;flex-wrap:wrap;">
      <?php foreach ($actions_seen as $a): ?>
        <a href="/admin/audit.php?action=<?= urlencode($a['action']) ?>" class="btn btn-ghost btn-sm">
          <?= htmlspecialchars($a['action']) ?>
          <span class="muted small">(<?= (int)$a['c'] ?>)</span>
        </a>
      <?php endforeach; ?>
    </p>
  </div>
<?php endif; ?>

<div class="portal-card">
  <h2><?= count($rows) ?> entries <?= $action_filter ? '(filter: ' . htmlspecialchars($action_filter) . ')' : '' ?></h2>
  <?php if (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-icon">∅</div>
      <h3>Nothing matches</h3>
      <p>No audit entries for that filter combination. Try widening the date range or clearing the filters.</p>
      <a class="btn btn-ghost" href="/admin/audit.php">Clear filters</a>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $meta = $r['meta'] ? (json_decode($r['meta'], true) ?: $r['meta']) : null;
        ?>
          <tr>
            <td class="muted small"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
            <td>
              <?php if (!empty($r['username'])): ?>
                <strong><?= htmlspecialchars($r['username']) ?></strong>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><code><?= htmlspecialchars($r['action']) ?></code></td>
            <td class="muted small">
              <?php if (!empty($r['target_type'])): ?>
                <?= htmlspecialchars($r['target_type']) ?>#<?= (int)$r['target_id'] ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="muted small">
              <?php if (is_array($meta)):
                $bits = [];
                foreach ($meta as $k => $v) {
                  if (is_scalar($v)) $bits[] = htmlspecialchars((string)$k) . '=' . htmlspecialchars((string)$v);
                }
                echo implode(' &middot; ', $bits);
              else: ?>—<?php endif; ?>
            </td>
            <td class="muted small"><?= htmlspecialchars((string)($r['ip_address'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
