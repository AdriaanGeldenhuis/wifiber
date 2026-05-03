<?php
/**
 * Devices — list, create, edit, delete the network gear inventory.
 *
 * Manual entry only for now; live status is "unknown" until Phase 3
 * adds the polling worker. Filters at the top narrow the grid by role,
 * status, vendor and a free-text search across name / mac / serial / IP.
 */
$page_title = 'Devices';
$active_key = 'devices';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/wireless.php';

$self = '/admin/devices.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $saved = device_save([
                'site_id'   => $_POST['site_id']   ?? null,
                'name'      => $_POST['name']      ?? '',
                'vendor'    => $_POST['vendor']    ?? '',
                'model'     => $_POST['model']     ?? '',
                'role'      => $_POST['role']      ?? '',
                'serial'    => $_POST['serial']    ?? '',
                'mac'       => $_POST['mac']       ?? '',
                'mgmt_ip'   => $_POST['mgmt_ip']   ?? '',
                'mgmt_port' => $_POST['mgmt_port'] ?? null,
                'firmware'  => $_POST['firmware']  ?? '',
                'status'    => $_POST['status']    ?? '',
                'notes'     => $_POST['notes']     ?? '',
            ], $id ?: null);
            audit_log('device.save', ['target_type' => 'device', 'target_id' => $saved]);
            flash('success', $id ? 'Device updated.' : 'Device added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            device_delete($id);
            audit_log('device.delete', ['target_type' => 'device', 'target_id' => $id]);
            flash('success', 'Device deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'discover') {
        $cidr = trim((string)($_POST['cidr'] ?? ''));
        $is_ajax = !empty($_POST['ajax']);
        $reply = function (array $data) use ($is_ajax, $self) {
            if ($is_ajax) {
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode($data);
                exit;
            }
            flash($data['ok'] ? 'success' : 'error', $data['message'] ?? '');
            header('Location: ' . $self);
            exit;
        };
        // Parse a /24 (only) — anything wider stresses the host and triggers
        // upstream rate limits. /24 = up to 254 hosts which we probe in
        // small batches via curl.
        if (!preg_match('#^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}/24$#', $cidr, $m)) {
            $reply(['ok' => false, 'message' => 'Discover only supports /24 ranges (e.g. 10.0.0.0/24).']);
        }
        $base = $m[1];
        $found = []; $checked = 0;
        $existing = [];
        foreach (devices_all() as $ed) $existing[$ed['mgmt_ip']] = true;

        $mh = curl_multi_init();
        $handles = [];
        for ($i = 1; $i <= 254; $i++) {
            $ip = "$base.$i";
            if (isset($existing[$ip])) continue;
            // Probe AirOS / RouterOS REST / Mimosa LuCI on HTTPS:443.
            $ch = curl_init('https://' . $ip . '/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'WifiberDiscover/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$ip] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);
        } while ($running > 0);
        foreach ($handles as $ip => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hdr  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $checked++;
            if ($code > 0 && $code < 500) {
                // Anything that talks HTTPS at all is interesting; the
                // operator decides if it's a radio.
                $found[] = ['ip' => $ip, 'http' => $code, 'content_type' => $hdr];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        audit_log('device.discover', ['meta' => ['cidr' => $cidr, 'found' => count($found), 'checked' => $checked]]);
        flash('success', sprintf('Discover found %d HTTPS responder(s) on %s.', count($found), $cidr));
        $_SESSION['discover_results'] = $found;
        header('Location: ' . $self . '#discover');
        exit;
    }

    if ($action === 'save_credentials') {
        $device_id = (int)($_POST['device_id'] ?? 0);
        $scheme    = (string)($_POST['scheme'] ?? '');
        try {
            if (device_secret_key() === null) {
                throw new RuntimeException('Set the 32-byte device_key in data/db.php before saving credentials. See data/db.php.example.');
            }
            device_credentials_save($device_id, $scheme, [
                'password'       => $_POST['password']       ?? '',
                'snmp_community' => $_POST['snmp_community'] ?? '',
                'api_token'      => $_POST['api_token']      ?? '',
                'ssh_key'        => $_POST['ssh_key']        ?? '',
            ], [
                'username'   => $_POST['username']   ?? '',
                'port'       => $_POST['port']       ?? null,
                'verify_tls' => !empty($_POST['verify_tls']),
                'notes'      => $_POST['cred_notes'] ?? '',
            ]);
            audit_log('device.credentials_saved', [
                'target_type' => 'device', 'target_id' => $device_id,
                'meta' => ['scheme' => $scheme],
            ]);
            flash('success', 'Credentials saved (encrypted at rest).');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete_credentials') {
        $cred_id = (int)($_POST['cred_id'] ?? 0);
        if ($cred_id) {
            device_credentials_delete($cred_id);
            audit_log('device.credentials_deleted', ['meta' => ['cred_id' => $cred_id]]);
            flash('success', 'Credentials deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'ping_now') {
        $id = (int)($_POST['id'] ?? 0);
        $d  = $id ? device_find($id) : null;
        $is_ajax = !empty($_POST['ajax']);
        $reply = function (bool $ok, string $msg, array $extra = []) use ($is_ajax, $self) {
            if ($is_ajax) {
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => $ok, 'message' => $msg] + $extra);
                exit;
            }
            flash($ok ? 'success' : 'error', $msg);
            header('Location: ' . $self);
            exit;
        };
        if (!$d) $reply(false, 'Device not found.');
        if (trim((string)$d['mgmt_ip']) === '') $reply(false, 'No management IP set on this device.');
        $r = icmp_ping($d['mgmt_ip']);
        device_record_poll_result($id, (bool)$r['ok'], $r['rtt_ms']);
        audit_log('device.ping', [
            'target_type' => 'device', 'target_id' => $id,
            'meta' => ['ok' => (bool)$r['ok'], 'rtt_ms' => $r['rtt_ms']],
        ]);
        if ($r['ok']) {
            $reply(true,
                sprintf('%s is reachable%s', $d['name'], $r['rtt_ms'] !== null ? ' — ' . number_format($r['rtt_ms'], 2) . ' ms' : ''),
                ['rtt_ms' => $r['rtt_ms'], 'status' => 'online']);
        }
        $reply(false, $d['name'] . ' is unreachable.', ['status' => 'offline']);
    }
}

$filters = [
    'role'    => $_GET['role']    ?? '',
    'status'  => $_GET['status']  ?? '',
    'vendor'  => $_GET['vendor']  ?? '',
    'search'  => trim((string)($_GET['search'] ?? '')),
    'site_id' => (int)($_GET['site_id'] ?? 0),
];

$devices = devices_all($filters);
$sites   = sites_all(false);

$site_label = function (?int $id) use ($sites): string {
    if (!$id) return '—';
    foreach ($sites as $s) if ((int)$s['id'] === $id) return $s['name'];
    return '#' . $id;
};

$status_pill = function (string $status): string {
    $colors = ['online' => '#0c8', 'offline' => '#d44', 'unknown' => '#888', 'retired' => '#555'];
    $bg = $colors[$status] ?? '#888';
    return '<span style="display:inline-block;background:' . $bg
        . ';color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;">'
        . htmlspecialchars($status) . '</span>';
};
?>

<div class="portal-head">
  <h1>Devices</h1>
  <p class="portal-sub">Network gear inventory — APs, CPEs, routers, switches, backhaul radios. Live status comes online in Phase&nbsp;3 once the polling worker is wired up.</p>
</div>

<div class="portal-card" id="discover">
  <h2>Discover radios on a subnet</h2>
  <p class="muted">HTTPS-probe a /24 to find unclaimed radios. Anything responding on :443 with an HTTP status &lt; 500 shows up below — operator decides if it's actually a radio. /24 only (254 hosts).</p>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="discover">
    <div class="field"><label>CIDR</label>
      <input type="text" name="cidr" placeholder="10.0.0.0/24" required pattern="\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/24">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Probe</button>
    </div>
  </form>
  <?php $discover_results = $_SESSION['discover_results'] ?? []; unset($_SESSION['discover_results']); ?>
  <?php if ($discover_results): ?>
    <h3 class="lv-label">Last discover results</h3>
    <table class="data-table">
      <thead><tr><th>IP</th><th>HTTP</th><th>Content-Type</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($discover_results as $r): ?>
          <tr>
            <td><code><?= htmlspecialchars($r['ip']) ?></code></td>
            <td><?= (int)$r['http'] ?></td>
            <td><small><?= htmlspecialchars((string)$r['content_type']) ?></small></td>
            <td>
              <a class="btn btn-ghost btn-sm" href="https://<?= htmlspecialchars($r['ip']) ?>/" target="_blank" rel="noopener">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted"><small>Add the radio with the form below using the IP above as <code>mgmt_ip</code>, then attach credentials to start polling.</small></p>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Filter</h2>
  <form method="get" class="form form-grid">
    <div class="field"><label>Search</label>
      <input type="text" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES) ?>" placeholder="name, MAC, serial, IP">
    </div>
    <div class="field"><label>Role</label>
      <select name="role">
        <option value="">— any —</option>
        <?php foreach (DEVICE_ROLES as $r): ?>
          <option value="<?= $r ?>" <?= $filters['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status">
        <option value="">— any —</option>
        <?php foreach (DEVICE_STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Vendor</label>
      <select name="vendor">
        <option value="">— any —</option>
        <?php foreach (DEVICE_VENDORS as $v): ?>
          <option value="<?= $v ?>" <?= $filters['vendor'] === $v ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Site</label>
      <select name="site_id">
        <option value="0">— any —</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $filters['site_id'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['type']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="<?= $self ?>" class="btn btn-ghost btn-sm">Reset</a>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Inventory <span class="muted">(<?= count($devices) ?>)</span></h2>
  <?php if (!$devices): ?>
    <div class="empty-state">
      <div class="empty-icon">⚙</div>
      <h3>No devices yet</h3>
      <p>APs, CPEs, routers, switches and backhaul radios all live here. Add one with the form below, or place markers on the network map.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th><th>Site</th><th>Vendor / model</th><th>Role</th>
          <th>MAC / IP</th><th>Firmware</th><th>Status</th><th>Last seen</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($devices as $d): ?>
          <tr<?= $d['status'] === 'retired' ? ' style="opacity:.5;"' : '' ?>>
            <td><strong><?= htmlspecialchars($d['name']) ?></strong>
              <?php if ($d['serial']): ?><br><small class="muted"><?= htmlspecialchars($d['serial']) ?></small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($site_label($d['site_id'])) ?></td>
            <td><?= htmlspecialchars($d['vendor']) ?><?php if ($d['model']): ?><br><small class="muted"><?= htmlspecialchars($d['model']) ?></small><?php endif; ?></td>
            <td><?= htmlspecialchars($d['role']) ?></td>
            <td>
              <?= $d['mac'] ? '<small><code>' . htmlspecialchars($d['mac']) . '</code></small><br>' : '' ?>
              <?= $d['mgmt_ip'] ? '<small>' . htmlspecialchars($d['mgmt_ip']) . ($d['mgmt_port'] ? ':' . (int)$d['mgmt_port'] : '') . '</small>' : '<small class="muted">—</small>' ?>
            </td>
            <td><small<?= $d['firmware'] ? '' : ' class="muted"' ?>><?= $d['firmware'] ? htmlspecialchars($d['firmware']) : '—' ?></small></td>
            <td data-device-status="<?= (int)$d['id'] ?>"><?= $status_pill($d['status']) ?></td>
            <td><small class="muted" data-device-lastseen="<?= (int)$d['id'] ?>"><?= $d['last_seen_at'] ? htmlspecialchars($d['last_seen_at']) : 'never' ?></small></td>
            <td>
              <a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>" class="btn btn-ghost btn-sm" style="margin-right:4px;">View</a>
              <?php if ($d['mgmt_ip']): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-ping-device="<?= (int)$d['id'] ?>" data-ping-name="<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>" style="margin-right:4px;" title="ICMP ping the management IP and record a health row">Ping</button>
              <?php endif; ?>
              <details style="display:inline-block;">
                <summary class="btn btn-ghost btn-sm">Creds</summary>
                <?php $existing_creds = device_credentials_for((int)$d['id']); ?>
                <div style="margin-top:12px;">
                  <?php if ($existing_creds): ?>
                    <table class="data-table" style="margin-bottom:10px;">
                      <thead><tr><th>Scheme</th><th>Username</th><th>Port</th><th>Last OK</th><th>Fails</th><th></th></tr></thead>
                      <tbody>
                      <?php foreach ($existing_creds as $c): ?>
                        <tr>
                          <td><code><?= htmlspecialchars($c['scheme']) ?></code></td>
                          <td><?= htmlspecialchars($c['username']) ?></td>
                          <td><?= $c['port'] !== null ? (int)$c['port'] : '<small class="muted">—</small>' ?></td>
                          <td><small><?= htmlspecialchars((string)($c['last_auth_ok_at'] ?? 'never')) ?></small></td>
                          <td><?= (int)$c['consecutive_fails'] ?></td>
                          <td>
                            <form method="post" class="inline-form" data-confirm="Delete <?= htmlspecialchars($c['scheme'], ENT_QUOTES) ?> credentials?">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="delete_credentials">
                              <input type="hidden" name="cred_id" value="<?= (int)$c['id'] ?>">
                              <button class="btn btn-danger btn-sm" type="submit">×</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                  <form method="post" class="form form-grid" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_credentials">
                    <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                    <div class="field"><label>Scheme</label>
                      <select name="scheme">
                        <?php foreach (CRED_SCHEMES as $s): ?>
                          <option value="<?= $s ?>"><?= $s ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="field"><label>Username</label>
                      <input type="text" name="username" autocomplete="off">
                    </div>
                    <div class="field"><label>Password (HTTP/HTTPS/SSH)</label>
                      <input type="password" name="password" autocomplete="new-password">
                    </div>
                    <div class="field"><label>SNMP community (snmpv2)</label>
                      <input type="password" name="snmp_community" autocomplete="new-password">
                    </div>
                    <div class="field"><label>API token (api scheme)</label>
                      <input type="password" name="api_token" autocomplete="new-password">
                    </div>
                    <div class="field"><label>Port (override)</label>
                      <input type="number" min="1" max="65535" name="port">
                    </div>
                    <div class="field"><label>Verify TLS?</label>
                      <input type="checkbox" name="verify_tls" value="1">
                    </div>
                    <div class="field"><label>Notes (e.g. cnMaestro base URL)</label>
                      <input type="text" name="cred_notes" maxlength="255">
                    </div>
                    <div class="form-actions" style="grid-column:1/-1;">
                      <button type="submit" class="btn btn-primary btn-sm">Save credentials</button>
                      <small class="muted">Secrets are encrypted at rest with libsodium. Leave a field blank to keep its existing ciphertext.</small>
                    </div>
                  </form>
                </div>
              </details>
              <details style="display:inline-block;">
                <summary class="btn btn-ghost btn-sm">Edit</summary>
                <form method="post" class="form form-grid" style="margin-top:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <div class="field"><label>Name</label>
                    <input type="text" name="name" required maxlength="120" value="<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Site</label>
                    <select name="site_id">
                      <option value="">— none —</option>
                      <?php foreach ($sites as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$d['site_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['type']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Vendor</label>
                    <select name="vendor">
                      <?php foreach (DEVICE_VENDORS as $v): ?>
                        <option value="<?= $v ?>" <?= $d['vendor'] === $v ? 'selected' : '' ?>><?= $v ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Model</label>
                    <input type="text" name="model" maxlength="80" value="<?= htmlspecialchars($d['model'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Role</label>
                    <select name="role">
                      <?php foreach (DEVICE_ROLES as $r): ?>
                        <option value="<?= $r ?>" <?= $d['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Status</label>
                    <select name="status">
                      <?php foreach (DEVICE_STATUSES as $s): ?>
                        <option value="<?= $s ?>" <?= $d['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Serial</label>
                    <input type="text" name="serial" maxlength="80" value="<?= htmlspecialchars($d['serial'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>MAC</label>
                    <input type="text" name="mac" maxlength="20" value="<?= htmlspecialchars($d['mac'], ENT_QUOTES) ?>" placeholder="AA:BB:CC:DD:EE:FF">
                  </div>
                  <div class="field"><label>Management IP</label>
                    <input type="text" name="mgmt_ip" maxlength="45" value="<?= htmlspecialchars($d['mgmt_ip'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Mgmt port</label>
                    <input type="number" min="1" max="65535" name="mgmt_port" value="<?= $d['mgmt_port'] !== null ? (int)$d['mgmt_port'] : '' ?>">
                  </div>
                  <div class="field"><label>Firmware</label>
                    <input type="text" name="firmware" maxlength="60" value="<?= htmlspecialchars($d['firmware'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field" style="grid-column:1/-1;"><label>Notes</label>
                    <textarea name="notes" rows="2"><?= htmlspecialchars((string)($d['notes'] ?? '')) ?></textarea>
                  </div>
                  <div class="form-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </div>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete <?= htmlspecialchars($d['name'], ENT_QUOTES) ?>? Health history for this device will also be wiped.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Add device</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Name</label>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. VDB-North-Sector3-AP">
    </div>
    <div class="field"><label>Site</label>
      <select name="site_id">
        <option value="">— none —</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Vendor</label>
      <select name="vendor">
        <?php foreach (DEVICE_VENDORS as $v): ?>
          <option value="<?= $v ?>"><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Model</label>
      <input type="text" name="model" maxlength="80" placeholder="e.g. RB5009UG, LiteAP AC">
    </div>
    <div class="field"><label>Role</label>
      <select name="role">
        <?php foreach (DEVICE_ROLES as $r): ?>
          <option value="<?= $r ?>"><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status">
        <?php foreach (DEVICE_STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= $s === 'unknown' ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Serial</label>
      <input type="text" name="serial" maxlength="80">
    </div>
    <div class="field"><label>MAC</label>
      <input type="text" name="mac" maxlength="20" placeholder="AA:BB:CC:DD:EE:FF">
    </div>
    <div class="field"><label>Management IP</label>
      <input type="text" name="mgmt_ip" maxlength="45" placeholder="10.0.0.1">
    </div>
    <div class="field"><label>Mgmt port</label>
      <input type="number" min="1" max="65535" name="mgmt_port" placeholder="22, 8728, 80…">
    </div>
    <div class="field"><label>Firmware</label>
      <input type="text" name="firmware" maxlength="60">
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2"></textarea>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary">Add device</button>
    </div>
  </form>
</div>

<script>
// AJAX ping — submits without leaving the page and surfaces the result
// as a toast. Updates the row's status pill in place on success.
(function () {
  document.addEventListener('click', async function (e) {
    var btn = e.target.closest('[data-ping-device]');
    if (!btn) return;
    e.preventDefault();
    var id   = btn.dataset.pingDevice;
    var name = btn.dataset.pingName || ('Device #' + id);
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content;
    btn.disabled = true;
    btn.classList.add('is-loading');
    window.toast && window.toast('Pinging ' + name + '…', 'info', 2000);
    var fd = new FormData();
    fd.append('action', 'ping_now');
    fd.append('id', id);
    fd.append('ajax', '1');
    fd.append('_csrf', token || '');
    try {
      var res = await fetch('/admin/devices.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var j = await res.json();
      btn.disabled = false;
      btn.classList.remove('is-loading');
      window.toast && window.toast(j.message || (j.ok ? 'OK' : 'Failed'), j.ok ? 'success' : 'error', 4000);
      // Flip the row's status pill in place.
      var pillCell = document.querySelector('[data-device-status="' + id + '"]');
      if (pillCell && j.status) {
        pillCell.innerHTML = '<span class="status-pill status-' + j.status + '">' + j.status + '</span>';
      }
      var lastCell = document.querySelector('[data-device-lastseen="' + id + '"]');
      if (lastCell && j.ok) {
        lastCell.textContent = 'just now';
      }
    } catch (err) {
      btn.disabled = false;
      btn.classList.remove('is-loading');
      window.toast && window.toast('Network error', 'error');
    }
  });
})();
</script>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
