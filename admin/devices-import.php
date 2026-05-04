<?php
/**
 * Bulk CSV import for devices. Two-step (preview, commit) like the
 * sites importer. Idempotent on (name, mac) — re-uploading the same
 * spreadsheet won't double-create.
 *
 * CSV columns (header row required, any order):
 *   name        REQUIRED
 *   site        optional, name of an existing site (resolved by name)
 *   vendor      mikrotik | ubiquiti | cambium | mimosa | other (default 'other')
 *   role        ap | cpe | router | switch | backhaul | ups | other (default 'other')
 *   model       optional
 *   serial      optional
 *   mac         optional, any common form (AA:BB:..., AABBCC..., a-b-c-...)
 *   mgmt_ip     optional
 *   mgmt_port   optional
 *   firmware    optional
 *   status      online | offline | unknown | retired (default 'unknown')
 *   notes       optional
 */
$page_title = 'Import devices';
$active_key = 'devices';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sites.php';

$self = '/admin/devices-import.php';

$parse_rows = function (string $blob): array {
    $rows = []; $headers = null; $line_no = 0;
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $blob); rewind($fh);
    while (($cells = fgetcsv($fh)) !== false) {
        $line_no++;
        if ($cells === [null] || $cells === false) continue;
        if ($headers === null) {
            $headers = array_map(fn($h) => strtolower(trim((string)$h)), $cells);
            continue;
        }
        if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';

        $errors = [];
        if (($row['name'] ?? '') === '') $errors[] = 'name is required';
        $vendor = strtolower($row['vendor'] ?? 'other'); if ($vendor === '') $vendor = 'other';
        $role   = strtolower($row['role']   ?? 'other'); if ($role === '')   $role   = 'other';
        $status = strtolower($row['status'] ?? 'unknown'); if ($status === '') $status = 'unknown';
        if (!in_array($vendor, DEVICE_VENDORS, true))   $errors[] = 'vendor must be ' . implode('/', DEVICE_VENDORS);
        if (!in_array($role,   DEVICE_ROLES,   true))   $errors[] = 'role must be ' . implode('/', DEVICE_ROLES);
        if (!in_array($status, DEVICE_STATUSES, true))  $errors[] = 'status must be ' . implode('/', DEVICE_STATUSES);

        $mac = mac_canonical((string)($row['mac'] ?? ''));
        if (($row['mac'] ?? '') !== '' && $mac === '') $errors[] = 'mac is not a valid 12-hex-digit MAC';

        $rows[] = [
            'line_no'   => $line_no,
            'name'      => (string)($row['name'] ?? ''),
            'site_name' => (string)($row['site'] ?? ''),
            'vendor'    => $vendor,
            'role'      => $role,
            'model'     => (string)($row['model']  ?? ''),
            'serial'    => (string)($row['serial'] ?? ''),
            'mac'       => $mac,
            'mgmt_ip'   => (string)($row['mgmt_ip']   ?? ''),
            'mgmt_port' => is_numeric($row['mgmt_port'] ?? null) ? (int)$row['mgmt_port'] : null,
            'firmware'  => (string)($row['firmware'] ?? ''),
            'status'    => $status,
            'notes'     => (string)($row['notes'] ?? ''),
            'errors'    => $errors,
        ];
    }
    fclose($fh);
    return $rows;
};

$preview_rows = [];
$summary = ['total' => 0, 'valid' => 0, 'errors' => 0, 'duplicates' => 0];
$preview_csv = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Upload failed.'); header('Location: ' . $self); exit;
        }
        if (((int)$f['size']) > 2 * 1024 * 1024) {
            flash('error', 'CSV too large (max 2 MB).'); header('Location: ' . $self); exit;
        }
        $blob = (string)file_get_contents($f['tmp_name']);
        $preview_rows = $parse_rows($blob);
        $preview_csv  = $blob;

        // Idempotency check: collide on (lower(name)) OR (canonical mac).
        $by_name = [];
        $by_mac  = [];
        foreach (devices_all() as $d) {
            $by_name[strtolower((string)$d['name'])] = (int)$d['id'];
            $mc = mac_canonical((string)$d['mac']);
            if ($mc !== '') $by_mac[$mc] = (int)$d['id'];
        }
        foreach ($preview_rows as &$r) {
            $r['duplicate'] = isset($by_name[strtolower($r['name'])])
                           || ($r['mac'] !== '' && isset($by_mac[$r['mac']]));
        }
        unset($r);

        foreach ($preview_rows as $r) {
            $summary['total']++;
            if ($r['errors']) $summary['errors']++;
            elseif (!empty($r['duplicate'])) $summary['duplicates']++;
            else $summary['valid']++;
        }
    }

    if ($action === 'commit') {
        $blob = (string)($_POST['csv_blob'] ?? '');
        if ($blob === '') { flash('error', 'No CSV submitted.'); header('Location: ' . $self); exit; }
        $rows = $parse_rows($blob);

        // Build site name → id map once.
        $site_id_by_name = [];
        foreach (sites_all(false) as $s) $site_id_by_name[strtolower((string)$s['name'])] = (int)$s['id'];

        $by_name = []; $by_mac = [];
        foreach (devices_all() as $d) {
            $by_name[strtolower((string)$d['name'])] = (int)$d['id'];
            $mc = mac_canonical((string)$d['mac']);
            if ($mc !== '') $by_mac[$mc] = (int)$d['id'];
        }

        $created = 0; $skipped = 0; $failed = [];
        pdo()->beginTransaction();
        try {
            foreach ($rows as $r) {
                if ($r['errors']) { $skipped++; continue; }
                if (isset($by_name[strtolower($r['name'])])) { $skipped++; continue; }
                if ($r['mac'] !== '' && isset($by_mac[$r['mac']])) { $skipped++; continue; }
                $site_id = null;
                if ($r['site_name'] !== '') {
                    $site_id = $site_id_by_name[strtolower($r['site_name'])] ?? null;
                }
                try {
                    $new_id = device_save([
                        'site_id'   => $site_id,
                        'name'      => $r['name'],
                        'vendor'    => $r['vendor'],
                        'model'     => $r['model'],
                        'role'      => $r['role'],
                        'serial'    => $r['serial'],
                        'mac'       => $r['mac'],
                        'mgmt_ip'   => $r['mgmt_ip'],
                        'mgmt_port' => $r['mgmt_port'],
                        'firmware'  => $r['firmware'],
                        'status'    => $r['status'],
                        'notes'     => $r['notes'],
                    ], null);
                    audit_log('device.import', ['target_type' => 'device', 'target_id' => $new_id]);
                    $by_name[strtolower($r['name'])] = $new_id;
                    if ($r['mac'] !== '') $by_mac[$r['mac']] = $new_id;
                    $created++;
                } catch (Throwable $e) {
                    $failed[] = 'line ' . $r['line_no'] . ': ' . $e->getMessage();
                }
            }
            pdo()->commit();
        } catch (Throwable $e) {
            pdo()->rollBack();
            flash('error', 'Import aborted: ' . $e->getMessage());
            header('Location: ' . $self); exit;
        }

        $msg = $created . ' created, ' . $skipped . ' skipped';
        if ($failed) $msg .= ' — ' . count($failed) . ' failed: ' . implode('; ', array_slice($failed, 0, 3));
        flash($created > 0 ? 'success' : 'info', $msg);
        header('Location: /admin/devices.php');
        exit;
    }
}

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Import devices</h1>
  <p class="portal-sub"><a href="/admin/devices.php">← All devices</a></p>
</div>

<?php if (!$preview_rows): ?>
<div class="portal-card">
  <h2>Upload CSV</h2>
  <p>Bulk-create radios, switches, routers and CPEs from a spreadsheet. The file must have a header row.
     Re-uploading the same data is safe — rows that match an existing <code>name</code> or <code>mac</code> are skipped.</p>
  <form method="post" enctype="multipart/form-data" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
    <div class="field" style="grid-column:1/-1;"><label>CSV file <span class="muted">(max 2 MB)</span></label>
      <input type="file" name="file" required accept=".csv,text/csv"></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Preview rows</button>
    </div>
  </form>

  <h3 style="margin-top:24px;">Required columns</h3>
  <ul><li><code>name</code></li></ul>
  <h3>Optional columns</h3>
  <ul>
    <li><code>site</code> — name of an existing site (resolved by exact-match name lookup)</li>
    <li><code>vendor</code> — <?= implode(' / ', DEVICE_VENDORS) ?> (default <code>other</code>)</li>
    <li><code>role</code> — <?= implode(' / ', DEVICE_ROLES) ?> (default <code>other</code>)</li>
    <li><code>model</code>, <code>serial</code>, <code>mac</code>, <code>mgmt_ip</code>, <code>mgmt_port</code>, <code>firmware</code></li>
    <li><code>status</code> — <?= implode(' / ', DEVICE_STATUSES) ?> (default <code>unknown</code>)</li>
    <li><code>notes</code></li>
  </ul>
  <h3>Example</h3>
  <pre style="background:#0a0d12;padding:10px;border-radius:6px;overflow:auto;">name,site,vendor,role,model,mac,mgmt_ip,firmware
VDB-North-Sector3-AP,VDB-North-Tower,ubiquiti,ap,LiteAP AC,AA:BB:CC:11:22:33,10.0.0.10,v8.7.13
RB5009-Core,VDB-North-Tower,mikrotik,router,RB5009UG,AA:BB:CC:11:22:34,10.0.0.1,7.16.1
PMP-450i-S2,VDB-North-Tower,cambium,ap,PMP 450i,AA:BB:CC:11:22:35,10.0.0.20,16.2.1</pre>
</div>

<?php else: ?>
<div class="portal-card">
  <h2>Preview <span class="muted">(<?= $summary['total'] ?> rows)</span></h2>
  <p>
    <strong style="color:#0c8;"><?= $summary['valid'] ?></strong> ready to create
    · <strong style="color:#fa0;"><?= $summary['duplicates'] ?></strong> duplicates (skip)
    · <strong style="color:#d44;"><?= $summary['errors'] ?></strong> with errors
  </p>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>#</th><th>Name</th><th>Site</th><th>Vendor</th><th>Role</th><th>Model</th><th>MAC</th><th>IP</th><th>FW</th><th>Status</th><th>Result</th></tr></thead>
    <tbody>
      <?php foreach ($preview_rows as $r):
        $cls = $r['errors'] ? 'background:rgba(220,68,68,.08);'
             : (!empty($r['duplicate']) ? 'background:rgba(255,170,0,.08);' : ''); ?>
        <tr style="<?= $cls ?>">
          <td><small><?= (int)$r['line_no'] ?></small></td>
          <td><strong><?= $h($r['name']) ?></strong></td>
          <td><small><?= $h($r['site_name']) ?></small></td>
          <td><?= $h($r['vendor']) ?></td>
          <td><?= $h($r['role']) ?></td>
          <td><small><?= $h($r['model']) ?></small></td>
          <td><small><code><?= $h($r['mac']) ?></code></small></td>
          <td><small><?= $h($r['mgmt_ip']) ?></small></td>
          <td><small><?= $h($r['firmware']) ?></small></td>
          <td><?= $h($r['status']) ?></td>
          <td>
            <?php if ($r['errors']): ?>
              <span style="color:#d44;"><small><?= $h(implode('; ', $r['errors'])) ?></small></span>
            <?php elseif (!empty($r['duplicate'])): ?>
              <span style="color:#fa0;"><small>duplicate — skip</small></span>
            <?php else: ?>
              <span style="color:#0c8;"><small>create</small></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <form method="post" style="margin-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commit">
    <input type="hidden" name="csv_blob" value="<?= $h($preview_csv) ?>">
    <button type="submit" class="btn btn-primary btn-sm" <?= $summary['valid'] === 0 ? 'disabled' : '' ?>>
      Create <?= $summary['valid'] ?> device<?= $summary['valid'] === 1 ? '' : 's' ?>
    </button>
    <a href="<?= $self ?>" class="btn btn-ghost btn-sm">Cancel</a>
  </form>
</div>
<?php endif; ?>
