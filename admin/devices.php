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
require_once __DIR__ . '/../auth/poll_status.php';

$self = '/admin/devices.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
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

    if ($action === 'poll_wireless_now') {
        $id = (int)($_POST['id'] ?? 0);
        $is_ajax = !empty($_POST['ajax']);
        $reply_w = function (bool $ok, string $msg) use ($is_ajax, $self) {
            if ($is_ajax) {
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => $ok, 'message' => $msg]);
                exit;
            }
            flash($ok ? 'success' : 'error', $msg);
            header('Location: ' . $self);
            exit;
        };
        $cmd = sprintf(
            '/usr/bin/php %s --once --quiet --only-device=%d 2>&1',
            escapeshellarg(realpath(__DIR__ . '/../bin/poll-wireless.php')),
            $id
        );
        $out = shell_exec($cmd);
        audit_log('device.poll_wireless_now', [
            'target_type' => 'device', 'target_id' => $id,
            'meta' => ['output' => mb_substr((string)$out, 0, 200)],
        ]);
        $reply_w(true, 'Wireless poll triggered. Refresh in a few seconds for telemetry.');
    }

    if ($action === 'test_credentials') {
        // Synchronous "does this credential actually work?" check —
        // calls the vendor adapter's poll function in-process and
        // records the auth attempt on the credential row. Always
        // returns JSON so the inline JS can flash the result without a
        // page reload.
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');

        $cred_id = (int)($_POST['cred_id'] ?? 0);
        if ($cred_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Missing credential id.']); exit; }

        $stmt = pdo()->prepare("SELECT * FROM device_credentials WHERE id = ? LIMIT 1");
        $stmt->execute([$cred_id]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'message' => 'Credential not found.']); exit; }

        $device = device_find((int)$row['device_id']);
        if (!$device || trim((string)$device['mgmt_ip']) === '') {
            echo json_encode(['ok' => false, 'message' => 'Device has no management IP.']);
            exit;
        }
        if (device_secret_key() === null) {
            echo json_encode(['ok' => false, 'message' => 'No device_key configured — cannot decrypt.']);
            exit;
        }
        $cred = device_credentials_unlock($row);
        if (!$cred) {
            echo json_encode(['ok' => false, 'message' => 'Could not decrypt credential.']);
            exit;
        }

        // Pick the right adapter. We deliberately call the poll function
        // (not snapshot) — every vendor has it, and if poll comes back
        // with telemetry then auth definitely worked.
        $vendor_map = [
            'ubiquiti' => ['airos_poll_device',    __DIR__ . '/../auth/vendors/airos.php'],
            'mikrotik' => ['routeros_poll_device', __DIR__ . '/../auth/vendors/routeros.php'],
            'cambium'  => ['cambium_poll_device',  __DIR__ . '/../auth/vendors/cambium.php'],
            'mimosa'   => ['mimosa_poll_device',   __DIR__ . '/../auth/vendors/mimosa.php'],
        ];
        $entry = $vendor_map[$device['vendor']] ?? null;
        if (!$entry) {
            echo json_encode(['ok' => false, 'message' => 'Vendor "' . $device['vendor'] . '" has no poll adapter.']);
            exit;
        }
        [$vendor_fn, $vendor_file] = $entry;
        if (!function_exists($vendor_fn) && is_file($vendor_file)) {
            require_once $vendor_file;
        }
        if (!function_exists($vendor_fn)) {
            echo json_encode(['ok' => false, 'message' => 'Adapter file missing: ' . basename($vendor_file)]);
            exit;
        }

        $start = microtime(true);
        try {
            $result = $vendor_fn($device, $cred);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        $ms = (int)round((microtime(true) - $start) * 1000);

        $ok  = !empty($result['ok']);
        $err = (string)($result['error'] ?? ($ok ? '' : 'unspecified failure'));
        device_credentials_record_attempt($cred_id, $ok, $err);
        audit_log('device.credentials_tested', [
            'target_type' => 'device', 'target_id' => (int)$device['id'],
            'meta' => ['scheme' => $row['scheme'], 'ok' => $ok, 'ms' => $ms, 'err' => mb_substr($err, 0, 200)],
        ]);

        echo json_encode([
            'ok'      => $ok,
            'message' => $ok
                ? sprintf('Auth OK on %s in %d ms.', $device['name'], $ms)
                : sprintf('Auth FAILED on %s — %s', $device['name'], $err),
            'ms'      => $ms,
        ]);
        exit;
    }

    if ($action === 'reboot_now') {
        /* High-risk op — requires step-up TOTP (matching the freq /
           sector bulk-apply pattern). Audited regardless of outcome. */
        require_once __DIR__ . '/../auth/totp.php';
        $is_ajax = !empty($_POST['ajax']);
        $reply = function (bool $ok, string $msg) use ($is_ajax, $self) {
            if ($is_ajax) {
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => $ok, 'message' => $msg]);
                exit;
            }
            flash($ok ? 'success' : 'error', $msg);
            header('Location: ' . $self);
            exit;
        };
        $id = (int)($_POST['id'] ?? 0);
        $d  = $id ? device_find($id) : null;
        if (!$d) $reply(false, 'Device not found.');
        if (!totp_require_step_up($user, (string)($_POST['totp_code'] ?? ''))) {
            audit_log('device.reboot_denied', [
                'target_type' => 'device', 'target_id' => $id,
                'meta' => ['reason' => 'no_totp'],
            ]);
            $reply(false, 'Two-factor code required for remote reboot.');
        }
        if (trim((string)$d['mgmt_ip']) === '') $reply(false, 'No management IP set on this device.');
        if (device_secret_key() === null) $reply(false, 'No device_key configured — cannot decrypt credentials.');
        $cred_rows = device_credentials_for((int)$d['id']);
        if (!$cred_rows) $reply(false, 'No saved credentials for this device.');
        $cred = device_credentials_unlock($cred_rows[0]);
        if (!$cred) $reply(false, 'Could not decrypt credentials.');

        $vendor_map = [
            'ubiquiti' => ['airos_reboot_device',    __DIR__ . '/../auth/vendors/airos.php'],
            'mikrotik' => ['routeros_reboot_device', __DIR__ . '/../auth/vendors/routeros.php'],
            'cambium'  => ['cambium_reboot_device',  __DIR__ . '/../auth/vendors/cambium.php'],
            'mimosa'   => ['mimosa_reboot_device',   __DIR__ . '/../auth/vendors/mimosa.php'],
        ];
        $entry = $vendor_map[$d['vendor']] ?? null;
        if (!$entry) {
            audit_log('device.reboot_denied', [
                'target_type' => 'device', 'target_id' => $id,
                'meta' => ['reason' => 'unsupported_vendor', 'vendor' => $d['vendor']],
            ]);
            $reply(false, 'Vendor "' . $d['vendor'] . '" reboot is not supported yet.');
        }
        [$fn, $file] = $entry;
        if (!function_exists($fn) && is_file($file)) require_once $file;
        if (!function_exists($fn))                    $reply(false, 'Adapter file missing.');

        try { $r = $fn($d, $cred); }
        catch (Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
        $ok = !empty($r['ok']);
        audit_log('device.reboot', [
            'target_type' => 'device', 'target_id' => $id,
            'meta' => ['ok' => $ok, 'error' => mb_substr((string)($r['error'] ?? ''), 0, 200)],
        ]);
        $reply($ok, $ok
            ? sprintf('%s reboot issued — telemetry will pause for ~60 s.', $d['name'])
            : sprintf('Reboot FAILED on %s — %s', $d['name'], $r['error'] ?? 'unknown error'));
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

// Pre-load firmware_eol rows so the per-device matcher doesn't hit the
// DB N times. Pattern matching happens in PHP — same LIKE semantics as
// device_firmware_eol_status() but loop-once instead of per-row.
$eol_rows = pdo()->query(
    "SELECT * FROM firmware_eol ORDER BY FIELD(severity,'critical','warn','info'), eol_date ASC"
)->fetchAll();

$eol_for_device = function (array $d) use ($eol_rows): ?array {
    if (empty($d['firmware'])) return null;
    foreach ($eol_rows as $r) {
        if ($r['vendor'] !== $d['vendor']) continue;
        $mod = (string)($d['model'] ?? '');
        $fw  = (string)$d['firmware'];
        // Re-implement SQL LIKE with anchors. % → .*, _ → . (escaped
        // so a literal '.' in firmware doesn't act as a wildcard).
        $mp  = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string)$r['model_match'], '/')) . '$/i';
        $vp  = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string)$r['version_match'], '/')) . '$/i';
        if (@preg_match($mp, $mod) === 1 && @preg_match($vp, $fw) === 1) {
            return $r;
        }
    }
    return null;
};

// MAC conflict sweep — surface duplicates the legacy data may already
// have. Save-time check stops new ones from sneaking in.
$mac_conflicts = device_mac_conflicts_all();

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

<?php $devices_freshness = poll_classify(poll_latest_device_health_at()); ?>
<div class="portal-head">
  <h1>Devices <?= poll_badge_html($devices_freshness, 'Newest device_health sample') ?></h1>
  <p class="portal-sub">Network gear inventory — APs, CPEs, routers, switches, backhaul radios.
    &nbsp;·&nbsp; <a href="/admin/devices-import.php">Bulk import CSV</a>
    &nbsp;·&nbsp; <a href="/admin/diagnostics.php">Polling status</a></p>
</div>

<?php if ($mac_conflicts): ?>
<div class="portal-card" style="border-color:var(--danger);background:rgba(220,68,68,.06);">
  <h2 style="color:var(--danger);">⚠ Duplicate MAC addresses (<?= count($mac_conflicts) ?>)</h2>
  <p>These MACs are claimed by more than one device. Save-time check now blocks new collisions, but legacy rows below need cleaning up by hand.</p>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>MAC</th><th>Devices</th></tr></thead>
    <tbody>
      <?php foreach ($mac_conflicts as $mac => $group): ?>
        <tr>
          <td><code><?= htmlspecialchars($mac) ?></code></td>
          <td>
            <?php foreach ($group as $g): ?>
              <a href="/admin/device-view.php?id=<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></a>
              <small class="muted">(<?= htmlspecialchars($g['vendor']) ?><?= $g['model'] ? ' ' . htmlspecialchars($g['model']) : '' ?><?= $g['mgmt_ip'] ? ' · ' . htmlspecialchars($g['mgmt_ip']) : '' ?>)</small>
              &nbsp;
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

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
            <td>
              <small<?= $d['firmware'] ? '' : ' class="muted"' ?>><?= $d['firmware'] ? htmlspecialchars($d['firmware']) : '—' ?></small>
              <?php $eol = $eol_for_device($d); if ($eol):
                $sev_color = ['critical' => '#d44', 'warn' => '#fa0', 'info' => '#888'][$eol['severity']] ?? '#888'; ?>
                <br><span style="display:inline-block;background:<?= $sev_color ?>;color:#fff;padding:1px 6px;border-radius:6px;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;"
                       title="<?= htmlspecialchars($eol['notes'], ENT_QUOTES) ?><?= $eol['eol_date'] ? ' · EOL ' . htmlspecialchars($eol['eol_date']) : '' ?><?= $eol['eos_date'] ? ' · EOS ' . htmlspecialchars($eol['eos_date']) : '' ?>"><?= htmlspecialchars($eol['severity']) ?> EOL</span>
              <?php endif; ?>
            </td>
            <td data-device-status="<?= (int)$d['id'] ?>"><?= $status_pill($d['status']) ?></td>
            <td><small class="muted" data-device-lastseen="<?= (int)$d['id'] ?>"><?= $d['last_seen_at'] ? htmlspecialchars($d['last_seen_at']) : 'never' ?></small></td>
            <td>
              <a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>" class="btn btn-ghost btn-sm" style="margin-right:4px;">View</a>
              <?php if ($d['mgmt_ip']): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-ping-device="<?= (int)$d['id'] ?>" data-ping-name="<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>" style="margin-right:4px;" title="ICMP ping the management IP and record a health row">Ping</button>
              <?php endif; ?>
              <?php if ($d['mgmt_ip'] && in_array($d['vendor'], ['ubiquiti','mikrotik','cambium','mimosa'], true)): ?>
                <form method="post" class="inline-form" style="display:inline-block;margin-right:4px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="poll_wireless_now">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" title="Run the vendor adapter against this device right now (synchronous)">Poll</button>
                </form>
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
                            <button type="button" class="btn btn-ghost btn-sm" data-test-cred="<?= (int)$c['id'] ?>" title="Run the vendor adapter against this device using these credentials. Synchronous — wait for result.">Test</button>
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

// AJAX credential test — talks to the vendor adapter synchronously and
// reports whether auth worked. Updates the cred row's "Last OK" /
// "Fails" cells on success.
(function () {
  document.addEventListener('click', async function (e) {
    var btn = e.target.closest('[data-test-cred]');
    if (!btn) return;
    e.preventDefault();
    var credId = btn.dataset.testCred;
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content;
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = 'Testing…';
    var fd = new FormData();
    fd.append('action',  'test_credentials');
    fd.append('cred_id', credId);
    fd.append('_csrf',   token || '');
    try {
      var res = await fetch('/admin/devices.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var j = await res.json();
      btn.disabled = false;
      btn.textContent = origText;
      window.toast && window.toast(j.message || (j.ok ? 'OK' : 'Failed'), j.ok ? 'success' : 'error', 6000);
    } catch (err) {
      btn.disabled = false;
      btn.textContent = origText;
      window.toast && window.toast('Network error', 'error');
    }
  });
})();
</script>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
