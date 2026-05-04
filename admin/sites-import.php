<?php
/**
 * Bulk CSV import for sites. Two-step: upload+preview first, then commit
 * once the operator has eyeballed the rows. Idempotent on (name, type,
 * lat, lng) — re-uploading the same file won't double-create.
 *
 * CSV columns (header row required, any order):
 *   name         REQUIRED
 *   type         tower | ap | ptp_endpoint | pop | other  (default tower)
 *   lat          REQUIRED, decimal degrees
 *   lng          REQUIRED, decimal degrees
 *   height_m     optional, decimal
 *   coverage_radius_m  optional, integer
 *   color        optional
 *   notes        optional
 *   parent       optional, name of the parent site (already in DB)
 *   is_active    optional, 1/0/yes/no — default 1
 */
$page_title = 'Import sites';
$active_key = 'sites';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';

$self = '/admin/sites-import.php';

// ----- helpers -----
$normalise_bool = function ($v): int {
    $s = strtolower(trim((string)$v));
    if ($s === '' || $s === '1' || $s === 'yes' || $s === 'true' || $s === 'y') return 1;
    if ($s === '0' || $s === 'no' || $s === 'false' || $s === 'n') return 0;
    return 1;
};

$parse_rows = function (string $csv_blob) use ($normalise_bool): array {
    $rows = [];
    $headers = null;
    $line_no = 0;
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv_blob);
    rewind($fh);

    while (($cells = fgetcsv($fh)) !== false) {
        $line_no++;
        if ($cells === [null] || $cells === false) continue;
        if ($headers === null) {
            $headers = array_map(fn($h) => strtolower(trim((string)$h)), $cells);
            continue;
        }
        if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;

        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
        }

        $errors = [];
        if (($row['name'] ?? '') === '')              $errors[] = 'name is required';
        if (!is_numeric($row['lat'] ?? null))         $errors[] = 'lat must be a number';
        if (!is_numeric($row['lng'] ?? null))         $errors[] = 'lng must be a number';
        $type = strtolower(trim((string)($row['type'] ?? 'tower')));
        if ($type === '') $type = 'tower';
        if (!in_array($type, SITE_TYPES, true))       $errors[] = "type must be one of " . implode('/', SITE_TYPES);

        $rows[] = [
            'line_no'           => $line_no,
            'name'              => (string)($row['name'] ?? ''),
            'type'              => $type,
            'lat'               => is_numeric($row['lat'] ?? null) ? (float)$row['lat'] : null,
            'lng'               => is_numeric($row['lng'] ?? null) ? (float)$row['lng'] : null,
            'height_m'          => is_numeric($row['height_m'] ?? null) ? (float)$row['height_m'] : null,
            'coverage_radius_m' => is_numeric($row['coverage_radius_m'] ?? null) ? (int)$row['coverage_radius_m'] : null,
            'color'             => (string)($row['color'] ?? ''),
            'notes'             => (string)($row['notes'] ?? ''),
            'parent_name'       => (string)($row['parent'] ?? ''),
            'is_active'         => $normalise_bool($row['is_active'] ?? 1),
            'errors'            => $errors,
        ];
    }
    fclose($fh);
    return $rows;
};

// ----- POST handlers -----
$preview_rows = [];
$summary      = ['total' => 0, 'valid' => 0, 'errors' => 0, 'duplicates' => 0];
$preview_csv  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Upload failed: ' . ($f['error'] ?? 'no file'));
            header('Location: ' . $self);
            exit;
        }
        if (((int)$f['size']) > 2 * 1024 * 1024) {
            flash('error', 'CSV too large (max 2 MB).');
            header('Location: ' . $self);
            exit;
        }
        $blob = (string)file_get_contents($f['tmp_name']);
        $preview_rows = $parse_rows($blob);
        $preview_csv  = $blob;

        // Flag duplicates against existing sites — same name + type.
        $existing = [];
        foreach (sites_all(false) as $s) {
            $existing[strtolower($s['name']) . '|' . $s['type']] = $s['id'];
        }
        foreach ($preview_rows as &$r) {
            $key = strtolower($r['name']) . '|' . $r['type'];
            $r['duplicate'] = isset($existing[$key]);
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
        if ($blob === '') {
            flash('error', 'No CSV submitted.');
            header('Location: ' . $self);
            exit;
        }
        $rows = $parse_rows($blob);

        // Pre-build lookup of existing sites for parent resolution.
        $by_name = [];
        foreach (sites_all(false) as $s) {
            $by_name[strtolower($s['name'])] = (int)$s['id'];
        }
        $existing_pair = [];
        foreach (sites_all(false) as $s) {
            $existing_pair[strtolower($s['name']) . '|' . $s['type']] = $s['id'];
        }

        $created = 0;
        $skipped = 0;
        $failed  = [];
        pdo()->beginTransaction();
        try {
            foreach ($rows as $r) {
                if ($r['errors']) { $skipped++; continue; }
                $key = strtolower($r['name']) . '|' . $r['type'];
                if (isset($existing_pair[$key])) { $skipped++; continue; } // idempotent

                $parent_id = null;
                if ($r['parent_name'] !== '') {
                    $parent_id = $by_name[strtolower($r['parent_name'])] ?? null;
                }

                try {
                    $new_id = site_save([
                        'parent_id'         => $parent_id,
                        'type'              => $r['type'],
                        'name'              => $r['name'],
                        'lat'               => $r['lat'],
                        'lng'               => $r['lng'],
                        'height_m'          => $r['height_m'],
                        'coverage_radius_m' => $r['coverage_radius_m'],
                        'color'             => $r['color'],
                        'notes'             => $r['notes'],
                        'is_active'         => $r['is_active'],
                    ], null);
                    audit_log('site.import', ['target_type' => 'site', 'target_id' => $new_id]);
                    $by_name[strtolower($r['name'])] = $new_id;
                    $existing_pair[$key] = $new_id;
                    $created++;
                } catch (Throwable $e) {
                    $failed[] = 'line ' . $r['line_no'] . ': ' . $e->getMessage();
                }
            }
            pdo()->commit();
        } catch (Throwable $e) {
            pdo()->rollBack();
            flash('error', 'Import aborted: ' . $e->getMessage());
            header('Location: ' . $self);
            exit;
        }

        $msg = $created . ' created, ' . $skipped . ' skipped';
        if ($failed) $msg .= ' — ' . count($failed) . ' failed: ' . implode('; ', array_slice($failed, 0, 3));
        flash($created > 0 ? 'success' : 'info', $msg);
        header('Location: /admin/sites.php');
        exit;
    }
}

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Import sites</h1>
  <p class="portal-sub"><a href="/admin/sites.php">← All sites</a></p>
</div>

<?php if (!$preview_rows): ?>
<div class="portal-card">
  <h2>Upload CSV</h2>
  <p>Bulk-create towers, APs, PTP endpoints and PoPs from a spreadsheet. The file must have a header row.
     Re-uploading the same data is safe — rows that match an existing <code>(name, type)</code> pair are skipped.</p>

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
  <ul>
    <li><code>name</code> — site label</li>
    <li><code>type</code> — <?= implode(' / ', SITE_TYPES) ?> (default <code>tower</code>)</li>
    <li><code>lat</code>, <code>lng</code> — decimal degrees, e.g. <code>-26.7100</code>, <code>27.8300</code></li>
  </ul>
  <h3>Optional columns</h3>
  <ul>
    <li><code>height_m</code>, <code>coverage_radius_m</code></li>
    <li><code>color</code>, <code>notes</code></li>
    <li><code>parent</code> — name of an existing parent site</li>
    <li><code>is_active</code> — 1/0/yes/no (default 1)</li>
  </ul>

  <h3>Example</h3>
  <pre style="background:#0a0d12;padding:10px;border-radius:6px;overflow:auto;">name,type,lat,lng,height_m,coverage_radius_m,parent,is_active
VDB-North-Tower,tower,-26.7104,27.8312,30,1500,,1
VDB-North-AP-N,ap,-26.7104,27.8312,28,800,VDB-North-Tower,1
Sasol-PTP,ptp_endpoint,-26.6500,27.8550,12,,VDB-North-Tower,1</pre>
</div>

<?php else: ?>
<div class="portal-card">
  <h2>Preview <span class="muted">(<?= $summary['total'] ?> rows)</span></h2>
  <p>
    <strong style="color:var(--success,#0c8);"><?= $summary['valid'] ?></strong> ready to create
    · <strong style="color:#fa0;"><?= $summary['duplicates'] ?></strong> duplicates (will be skipped)
    · <strong style="color:#d44;"><?= $summary['errors'] ?></strong> with errors
  </p>

  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>#</th><th>Name</th><th>Type</th><th>Lat</th><th>Lng</th><th>Height</th><th>Radius</th><th>Parent</th><th>Active</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($preview_rows as $r): ?>
        <?php
          $cls = $r['errors'] ? 'background:rgba(220,68,68,.08);'
               : (!empty($r['duplicate']) ? 'background:rgba(255,170,0,.08);' : '');
        ?>
        <tr style="<?= $cls ?>">
          <td><small><?= (int)$r['line_no'] ?></small></td>
          <td><strong><?= $h($r['name']) ?></strong></td>
          <td><?= $h($r['type']) ?></td>
          <td><small><?= $r['lat'] !== null ? number_format($r['lat'], 5) : '—' ?></small></td>
          <td><small><?= $r['lng'] !== null ? number_format($r['lng'], 5) : '—' ?></small></td>
          <td><small><?= $r['height_m'] !== null ? number_format($r['height_m'], 1) . ' m' : '—' ?></small></td>
          <td><small><?= $r['coverage_radius_m'] !== null ? (int)$r['coverage_radius_m'] . ' m' : '—' ?></small></td>
          <td><small><?= $h($r['parent_name']) ?></small></td>
          <td><?= $r['is_active'] ? '✓' : '<span class="muted">—</span>' ?></td>
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
      Create <?= $summary['valid'] ?> site<?= $summary['valid'] === 1 ? '' : 's' ?>
    </button>
    <a href="<?= $self ?>" class="btn btn-ghost btn-sm">Cancel</a>
  </form>
</div>
<?php endif; ?>
