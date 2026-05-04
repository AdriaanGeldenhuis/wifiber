<?php
/**
 * RADIUS / NAS administration.
 *
 *   • CRUD for the `nas` table (one row per BNG / hAP / OLT that talks RADIUS)
 *   • Live PPP / hotspot sessions (open rows in radacct)
 *   • Per-customer "Disconnect now" — fires a CoA-Disconnect at the NAS
 *   • Monthly bandwidth-usage report (sum of acctinput/output octets)
 *
 * The customer-side hooks (provision / suspend / disconnect on status
 * change) live in auth/helpers.php → user_save() and auth/products.php
 * → product_save(). This page is the operator's window into the AAA layer.
 */

declare(strict_types=1);

$page_title = 'RADIUS / NAS';
$active_key = 'radius';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/radius.php';

$self = '/admin/radius.php';

/* ------------------------------------------------------------- POST handlers */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'nas_save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $saved = nas_save([
                'nasname'     => $_POST['nasname']     ?? '',
                'shortname'   => $_POST['shortname']   ?? '',
                'type'        => $_POST['type']        ?? 'other',
                'ports'       => $_POST['ports']       ?? null,
                'secret'      => $_POST['secret']      ?? '',
                'server'      => $_POST['server']      ?? '',
                'community'   => $_POST['community']   ?? '',
                'description' => $_POST['description'] ?? '',
                'pod_port'    => $_POST['pod_port']    ?? 3799,
                'device_id'   => $_POST['device_id']   ?? null,
            ], $id ?: null);
            audit_log('nas.save', ['target_type' => 'nas', 'target_id' => $saved]);
            flash('success', $id ? 'NAS updated.' : 'NAS added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'nas_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            nas_delete($id);
            audit_log('nas.delete', ['target_type' => 'nas', 'target_id' => $id]);
            flash('success', 'NAS deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'pod') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $u = $user_id ? find_user_by_id($user_id) : null;
        if ($u) {
            $sent = radius_send_pod(radius_username_for($u));
            flash($sent ? 'success' : 'error',
                $sent ? "Disconnect sent to {$sent} NAS." : 'No matching open session — nothing to disconnect.');
        } else {
            flash('error', 'Customer not found.');
        }
        header('Location: ' . $self . '#sessions');
        exit;
    }

    if ($action === 'reprovision') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id && radius_provision_user($user_id)) {
            flash('success', 'RADIUS attributes refreshed.');
        } else {
            flash('error', 'Could not provision — check the customer record.');
        }
        header('Location: ' . $self);
        exit;
    }
}

/* --------------------------------------------------------------- read-state */

$nas_rows  = nas_all();
$sessions  = radius_sessions_open(200);

$period_start = $_GET['from'] ?? date('Y-m-01');
$period_end   = $_GET['to']   ?? date('Y-m-01', strtotime('+1 month'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_start)) $period_start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_end))   $period_end   = date('Y-m-01', strtotime('+1 month'));
$usage = radius_usage_by_user($period_start, $period_end);

// Index user lookups so we can show real names next to the RADIUS username
// without N+1ing through find_user_by_username().
$user_by_radius = [];
foreach (load_users() as $u) {
    if (($u['role'] ?? '') !== 'client') continue;
    $key = trim((string)($u['radius_username'] ?? '')) ?: (string)($u['username'] ?? '');
    if ($key !== '') $user_by_radius[$key] = $u;
}

$nas_types = ['other', 'mikrotik', 'cisco', 'juniper', 'huawei', 'rad-vsa'];
?>

<div class="portal-head">
  <h1>RADIUS / NAS</h1>
  <p class="portal-sub">
    AAA backbone — register the NAS devices that point at this database, watch live PPP / hotspot
    sessions, and disconnect a session in flight when a customer is suspended or the operator needs
    to bounce them.
  </p>
</div>

<div class="portal-card">
  <h2>NAS devices</h2>
  <p class="muted" style="margin-top:-4px;">
    A NAS row authorises a router (FreeRADIUS <code>clients.conf</code>) to talk to us. The
    <strong>shared secret</strong> must match what's configured on the NAS itself; the
    <strong>shortname</strong> is what you'll see on the live-sessions list.
  </p>
  <?php if (!$nas_rows): ?>
    <p class="muted">No NAS devices yet. Add the BNG / hAP / OLT below.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Shortname</th><th>Hostname / IP</th><th>Type</th>
          <th style="text-align:right;">Ports</th><th>PoD port</th>
          <th>Description</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nas_rows as $n): ?>
          <tr>
            <td><strong><?= htmlspecialchars((string)$n['shortname']) ?></strong></td>
            <td><code><?= htmlspecialchars((string)$n['nasname']) ?></code></td>
            <td><?= htmlspecialchars((string)$n['type']) ?></td>
            <td style="text-align:right;"><?= $n['ports'] !== null ? (int)$n['ports'] : '—' ?></td>
            <td><?= (int)$n['pod_port'] ?></td>
            <td><?= htmlspecialchars((string)($n['description'] ?? '')) ?></td>
            <td>
              <details style="display:inline-block;">
                <summary class="btn btn-ghost btn-sm">Edit</summary>
                <form method="post" class="form form-grid" style="margin-top:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="nas_save">
                  <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                  <div class="field"><label>Shortname</label>
                    <input type="text" name="shortname" required maxlength="32" value="<?= htmlspecialchars((string)$n['shortname'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Hostname / IP</label>
                    <input type="text" name="nasname" required maxlength="128" value="<?= htmlspecialchars((string)$n['nasname'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Type</label>
                    <select name="type">
                      <?php foreach ($nas_types as $t): ?>
                        <option value="<?= $t ?>" <?= $n['type']===$t?'selected':'' ?>><?= $t ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Shared secret</label>
                    <input type="text" name="secret" required maxlength="60" value="<?= htmlspecialchars((string)$n['secret'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Ports</label>
                    <input type="number" name="ports" value="<?= $n['ports'] ?? '' ?>">
                  </div>
                  <div class="field"><label>PoD port</label>
                    <input type="number" name="pod_port" min="1" max="65535" value="<?= (int)$n['pod_port'] ?>">
                  </div>
                  <div class="field" style="grid-column:1/-1;"><label>Description</label>
                    <input type="text" name="description" maxlength="200" value="<?= htmlspecialchars((string)($n['description'] ?? ''), ENT_QUOTES) ?>">
                  </div>
                  <div class="form-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </div>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete NAS &quot;<?= htmlspecialchars((string)$n['shortname'], ENT_QUOTES) ?>&quot;? RADIUS requests from this NAS will be rejected.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="nas_delete">
                  <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Add NAS</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="nas_save">
    <div class="field"><label>Shortname</label>
      <input type="text" name="shortname" required maxlength="32" placeholder="bng-1">
    </div>
    <div class="field"><label>Hostname / IP</label>
      <input type="text" name="nasname" required maxlength="128" placeholder="10.0.0.1">
    </div>
    <div class="field"><label>Type</label>
      <select name="type">
        <?php foreach ($nas_types as $t): ?>
          <option value="<?= $t ?>"><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Shared secret</label>
      <input type="text" name="secret" required maxlength="60">
    </div>
    <div class="field"><label>Ports</label>
      <input type="number" name="ports" value="1812">
    </div>
    <div class="field"><label>PoD port</label>
      <input type="number" name="pod_port" value="3799">
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Description</label>
      <input type="text" name="description" maxlength="200" placeholder="Tower 4 BNG">
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary">Add NAS</button>
    </div>
  </form>
</div>

<div class="portal-card" id="sessions">
  <h2>Live sessions <span class="muted" style="font-weight:400;font-size:.85em;">(<?= count($sessions) ?> open)</span></h2>
  <?php if (!$sessions): ?>
    <p class="muted">No open accounting sessions. Either no NAS is sending Accounting-Request, or the polling worker hasn't run yet.</p>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Username</th><th>Customer</th><th>NAS</th><th>Framed IP</th>
            <th>Caller</th><th style="text-align:right;">In</th>
            <th style="text-align:right;">Out</th><th>Started</th>
            <th>Duration</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $s):
            $name = $user_by_radius[$s['username']] ?? null;
            $started = $s['acctstarttime'] ? strtotime((string)$s['acctstarttime']) : 0;
            $dur = $started ? (time() - $started) : 0;
          ?>
          <tr>
            <td><code><?= htmlspecialchars((string)$s['username']) ?></code></td>
            <td>
              <?php if ($name): ?>
                <a href="/admin/client-edit.php?id=<?= (int)$name['id'] ?>"><?= htmlspecialchars((string)($name['name'] ?: $name['username'])) ?></a>
              <?php else: ?>
                <span class="muted">unknown</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)$s['nasipaddress']) ?></td>
            <td><?= htmlspecialchars((string)$s['framedipaddress']) ?></td>
            <td><?= htmlspecialchars((string)$s['callingstationid']) ?></td>
            <td style="text-align:right;"><?= radius_format_octets((int)($s['acctinputoctets']  ?? 0)) ?></td>
            <td style="text-align:right;"><?= radius_format_octets((int)($s['acctoutputoctets'] ?? 0)) ?></td>
            <td><?= $started ? date('Y-m-d H:i', $started) : '—' ?></td>
            <td><?= radius_format_duration($dur) ?></td>
            <td>
              <?php if ($name && admin_can_write()): ?>
                <form method="post" class="inline-form" data-confirm="Disconnect <?= htmlspecialchars((string)$s['username'], ENT_QUOTES) ?>? Their session will be torn down.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="pod">
                  <input type="hidden" name="user_id" value="<?= (int)$name['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Disconnect</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Bandwidth usage</h2>
  <form method="get" class="form form-inline" style="margin-bottom:12px;">
    <label>From <input type="date" name="from" value="<?= htmlspecialchars($period_start, ENT_QUOTES) ?>"></label>
    <label>To <input type="date" name="to" value="<?= htmlspecialchars($period_end, ENT_QUOTES) ?>"></label>
    <button type="submit" class="btn btn-ghost btn-sm">Refresh</button>
  </form>
  <?php if (!$usage): ?>
    <p class="muted">No accounting data for this range.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Username</th><th>Customer</th>
          <th style="text-align:right;">Sessions</th>
          <th style="text-align:right;">Down</th>
          <th style="text-align:right;">Up</th>
          <th style="text-align:right;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage as $u):
          $name = $user_by_radius[$u['username']] ?? null;
          $total = (int)$u['in_octets'] + (int)$u['out_octets'];
        ?>
        <tr>
          <td><code><?= htmlspecialchars((string)$u['username']) ?></code></td>
          <td>
            <?php if ($name): ?>
              <a href="/admin/client-edit.php?id=<?= (int)$name['id'] ?>"><?= htmlspecialchars((string)($name['name'] ?: $name['username'])) ?></a>
            <?php else: ?>
              <span class="muted">unknown</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;"><?= (int)$u['sessions'] ?></td>
          <td style="text-align:right;"><?= radius_format_octets((int)$u['out_octets']) ?></td>
          <td style="text-align:right;"><?= radius_format_octets((int)$u['in_octets']) ?></td>
          <td style="text-align:right;"><strong><?= radius_format_octets($total) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
