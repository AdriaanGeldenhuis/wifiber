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
    <p class="muted">No devices match. Add one below to get started.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th><th>Site</th><th>Vendor / model</th><th>Role</th>
          <th>MAC / IP</th><th>Status</th><th>Last seen</th><th></th>
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
            <td><?= $status_pill($d['status']) ?></td>
            <td><small class="muted"><?= $d['last_seen_at'] ? htmlspecialchars($d['last_seen_at']) : 'never' ?></small></td>
            <td>
              <details>
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

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
