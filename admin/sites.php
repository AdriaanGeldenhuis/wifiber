<?php
/**
 * Sites — full CRUD for towers, APs, PTP endpoints, PoPs.
 *
 * Promotes the inline sidebar on /admin/map.php to a first-class page
 * with table, filters, and a CSV-friendly grid. Map-side editing still
 * works; this is for keyboard-driven bulk work where dragging pins is
 * too slow.
 */
$page_title = 'Sites';
$active_key = 'sites';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';

$self = '/admin/sites.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $saved = site_save([
                'parent_id'         => $_POST['parent_id']         ?? null,
                'type'              => $_POST['type']              ?? 'tower',
                'name'              => $_POST['name']              ?? '',
                'lat'               => $_POST['lat']               ?? null,
                'lng'               => $_POST['lng']               ?? null,
                'height_m'          => $_POST['height_m']          ?? null,
                'coverage_radius_m' => $_POST['coverage_radius_m'] ?? null,
                'color'             => $_POST['color']             ?? '',
                'notes'             => $_POST['notes']             ?? '',
                'is_active'         => !empty($_POST['is_active']),
            ], $id ?: null);
            audit_log('site.save', ['target_type' => 'site', 'target_id' => $saved]);
            flash('success', $id ? 'Site updated.' : 'Site added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            site_delete($id);
            audit_log('site.delete', ['target_type' => 'site', 'target_id' => $id]);
            flash('success', 'Site deleted.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$type_filter = $_GET['type'] ?? '';
$search      = trim((string)($_GET['search'] ?? ''));

$sites = sites_all(false);
if ($type_filter !== '') {
    $sites = array_values(array_filter($sites, fn ($s) => $s['type'] === $type_filter));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $sites = array_values(array_filter($sites, fn ($s) =>
        str_contains(mb_strtolower($s['name']), $needle)
        || str_contains(mb_strtolower((string)($s['notes'] ?? '')), $needle)
    ));
}

// Device count per site for the table.
$device_counts = [];
foreach (pdo()->query("SELECT site_id, COUNT(*) c FROM devices WHERE site_id IS NOT NULL GROUP BY site_id") as $r) {
    $device_counts[(int)$r['site_id']] = (int)$r['c'];
}

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$site_label = function (?int $id) use ($sites): string {
    if (!$id) return '—';
    foreach ($sites as $s) if ((int)$s['id'] === $id) return $s['name'];
    return '#' . $id;
};
?>

<div class="portal-head">
  <h1>Sites</h1>
  <p class="portal-sub">Towers, AP poles, PTP endpoints and PoPs. Drag-edit on <a href="/admin/map.php">the map</a>; bulk-edit here.</p>
</div>

<div class="portal-card">
  <h2>Filter</h2>
  <form method="get" class="form form-grid">
    <div class="field"><label>Search</label>
      <input type="text" name="search" value="<?= $h($search) ?>" placeholder="name or notes">
    </div>
    <div class="field"><label>Type</label>
      <select name="type">
        <option value="">— any —</option>
        <?php foreach (SITE_TYPES as $t): ?>
          <option value="<?= $t ?>" <?= $type_filter === $t ? 'selected' : '' ?>><?= $t ?></option>
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
  <h2>Sites <span class="muted">(<?= count($sites) ?>)</span></h2>
  <?php if (!$sites): ?>
    <div class="empty-state">
      <div class="empty-icon">📍</div>
      <h3>No sites yet</h3>
      <p>Drop a tower on <a href="/admin/map.php">the map</a> or add one with the form below.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th><th>Type</th><th>Parent</th><th>Lat / lng</th>
          <th>Height</th><th>Coverage</th><th>Devices</th><th>Active</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sites as $s): ?>
          <tr<?= $s['is_active'] ? '' : ' style="opacity:.55;"' ?>>
            <td><strong><?= $h($s['name']) ?></strong>
              <?php if ($s['notes']): ?><br><small class="muted"><?= $h(mb_substr((string)$s['notes'], 0, 80)) ?></small><?php endif; ?>
            </td>
            <td><?= $h($s['type']) ?></td>
            <td><?= $h($site_label($s['parent_id'])) ?></td>
            <td><small><?= number_format($s['lat'], 5) ?>, <?= number_format($s['lng'], 5) ?></small></td>
            <td><?= $s['height_m'] !== null ? number_format($s['height_m'], 1) . ' m' : '—' ?></td>
            <td><?= $s['coverage_radius_m'] !== null ? (int)$s['coverage_radius_m'] . ' m' : '—' ?></td>
            <td><?= (int)($device_counts[$s['id']] ?? 0) ?></td>
            <td><?= $s['is_active'] ? '✓' : '<span class="muted">—</span>' ?></td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm">Edit</summary>
                <form method="post" class="form form-grid" style="margin-top:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <div class="field"><label>Name</label>
                    <input type="text" name="name" required maxlength="120" value="<?= $h($s['name']) ?>"></div>
                  <div class="field"><label>Type</label>
                    <select name="type">
                      <?php foreach (SITE_TYPES as $t): ?>
                        <option value="<?= $t ?>" <?= $s['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Parent</label>
                    <select name="parent_id">
                      <option value="">— none —</option>
                      <?php foreach ($sites as $p): if ($p['id'] === $s['id']) continue; ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)$s['parent_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                          <?= $h($p['name']) ?> (<?= $h($p['type']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Latitude</label>
                    <input type="number" step="0.000001" name="lat" required value="<?= $h($s['lat']) ?>"></div>
                  <div class="field"><label>Longitude</label>
                    <input type="number" step="0.000001" name="lng" required value="<?= $h($s['lng']) ?>"></div>
                  <div class="field"><label>Height (m)</label>
                    <input type="number" step="0.1" name="height_m" value="<?= $h($s['height_m']) ?>"></div>
                  <div class="field"><label>Coverage radius (m)</label>
                    <input type="number" name="coverage_radius_m" value="<?= $h($s['coverage_radius_m']) ?>"></div>
                  <div class="field"><label>Color</label>
                    <input type="text" name="color" maxlength="20" value="<?= $h($s['color']) ?>"></div>
                  <div class="field"><label><input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?>> Active</label></div>
                  <div class="field" style="grid-column:1/-1;"><label>Notes</label>
                    <textarea name="notes" rows="2"><?= $h($s['notes'] ?? '') ?></textarea></div>
                  <div class="form-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </div>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete <?= $h($s['name']) ?>? Devices on this site lose their site_id; site_links to/from it are dropped.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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
  <h2>Add site</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Name</label>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. VDB-North-Tower"></div>
    <div class="field"><label>Type</label>
      <select name="type">
        <?php foreach (SITE_TYPES as $t): ?>
          <option value="<?= $t ?>"><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Parent</label>
      <select name="parent_id">
        <option value="">— none —</option>
        <?php foreach ($sites as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= $h($p['name']) ?> (<?= $h($p['type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Latitude</label><input type="number" step="0.000001" name="lat" required></div>
    <div class="field"><label>Longitude</label><input type="number" step="0.000001" name="lng" required></div>
    <div class="field"><label>Height (m)</label><input type="number" step="0.1" name="height_m"></div>
    <div class="field"><label>Coverage radius (m)</label><input type="number" name="coverage_radius_m"></div>
    <div class="field"><label>Color</label><input type="text" name="color" maxlength="20" placeholder="#08c"></div>
    <div class="field"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Add site</button>
    </div>
  </form>
</div>
