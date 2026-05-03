<?php
/**
 * Sectors — list, create, edit, delete the AP-on-a-tower configurations.
 *
 * One sector = one AP device pointed in a specific direction on a
 * specific tower, broadcasting on a specific frequency. Live noise /
 * client count / utilisation come from the Phase 3 polling worker
 * (when it lands) and live in rf_samples — never on this page.
 */
$page_title = 'Sectors';
$active_key = 'sectors';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';

$self = '/admin/sectors.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $saved = sector_save([
                'tower_id'          => $_POST['tower_id']          ?? 0,
                'ap_device_id'      => $_POST['ap_device_id']      ?? null,
                'name'              => $_POST['name']              ?? '',
                'azimuth_deg'       => $_POST['azimuth_deg']       ?? null,
                'beamwidth_deg'     => $_POST['beamwidth_deg']     ?? null,
                'band'              => $_POST['band']              ?? '',
                'frequency_mhz'     => $_POST['frequency_mhz']     ?? null,
                'channel_width_mhz' => $_POST['channel_width_mhz'] ?? null,
                'tx_power_dbm'      => $_POST['tx_power_dbm']      ?? null,
                'max_clients'       => $_POST['max_clients']       ?? null,
                'notes'             => $_POST['notes']             ?? '',
            ], $id ?: null);
            audit_log('sector.save', ['target_type' => 'sector', 'target_id' => $saved]);
            flash('success', $id ? 'Sector updated.' : 'Sector added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            sector_delete($id);
            audit_log('sector.delete', ['target_type' => 'sector', 'target_id' => $id]);
            flash('success', 'Sector deleted.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$filters = [
    'tower_id' => (int)($_GET['tower_id'] ?? 0),
    'band'     => $_GET['band'] ?? '',
    'search'   => trim((string)($_GET['search'] ?? '')),
];

$sectors = sectors_all($filters);
// Towers are sites with type='tower' — that's the only host that makes sense.
$towers  = array_values(array_filter(sites_all(false), fn($s) => $s['type'] === 'tower'));
// Any device can in theory drive a sector, but APs and routers are the
// realistic options. Show all to allow the odd unconventional setup.
$ap_devices = devices_all(['role' => 'ap']);
$all_devices = devices_all();

$tower_label = function (int $id) use ($towers): string {
    foreach ($towers as $t) if ((int)$t['id'] === $id) return $t['name'];
    return '#' . $id;
};

$device_label = function (?int $id) use ($all_devices): string {
    if (!$id) return '— none —';
    foreach ($all_devices as $d) if ((int)$d['id'] === $id) {
        $tail = $d['vendor'] ? ' (' . $d['vendor'] . ($d['model'] ? ' ' . $d['model'] : '') . ')' : '';
        return $d['name'] . $tail;
    }
    return '#' . $id;
};
?>

<div class="portal-head">
  <h1>Sectors</h1>
  <p class="portal-sub">AP-on-tower configurations: where each AP is pointed, what band it's on, and how much power it pushes. Live metrics (noise, clients, utilisation) come online with the Phase&nbsp;3 polling worker.</p>
</div>

<?php if (!$towers): ?>
  <div class="portal-card">
    <p class="muted">No towers yet — <a href="/admin/map.php">add one on the network map</a> first (set its type to <code>tower</code>), then come back to add sectors.</p>
  </div>
<?php else: ?>

<div class="portal-card">
  <h2>Filter</h2>
  <form method="get" class="form form-grid">
    <div class="field"><label>Search</label>
      <input type="text" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES) ?>" placeholder="sector name">
    </div>
    <div class="field"><label>Tower</label>
      <select name="tower_id">
        <option value="0">— any —</option>
        <?php foreach ($towers as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $filters['tower_id'] === (int)$t['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Band</label>
      <select name="band">
        <option value="">— any —</option>
        <?php foreach (SECTOR_BANDS as $b): ?>
          <option value="<?= $b ?>" <?= $filters['band'] === $b ? 'selected' : '' ?>><?= $b ?></option>
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
  <h2>Sectors <span class="muted">(<?= count($sectors) ?>)</span></h2>
  <?php if (!$sectors): ?>
    <p class="muted">No sectors match. Add one below to get started.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th><th>Tower</th><th>AP device</th>
          <th>Azimuth / beam</th><th>Band</th><th>Frequency</th><th>TX</th>
          <th style="text-align:right;">Clients</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sectors as $s): ?>
          <tr>
            <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
            <td><?= htmlspecialchars($s['tower_name'] ?? $tower_label((int)$s['tower_id'])) ?></td>
            <td>
              <?php if ($s['ap_device_id']): ?>
                <?= htmlspecialchars($s['ap_device_name'] ?? ('#' . $s['ap_device_id'])) ?>
                <?php if (!empty($s['ap_device_vendor']) || !empty($s['ap_device_model'])): ?>
                  <br><small class="muted"><?= htmlspecialchars(trim(($s['ap_device_vendor'] ?? '') . ' ' . ($s['ap_device_model'] ?? ''))) ?></small>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?= $s['azimuth_deg']   !== null ? (int)$s['azimuth_deg']   . '&deg;' : '—' ?>
              /
              <?= $s['beamwidth_deg'] !== null ? (int)$s['beamwidth_deg'] . '&deg;' : '—' ?>
            </td>
            <td><?= htmlspecialchars($s['band']) ?></td>
            <td>
              <?= $s['frequency_mhz']     !== null ? (int)$s['frequency_mhz']     . ' MHz' : '—' ?>
              <?php if ($s['channel_width_mhz'] !== null): ?>
                <br><small class="muted">@ <?= (int)$s['channel_width_mhz'] ?> MHz wide</small>
              <?php endif; ?>
            </td>
            <td><?= $s['tx_power_dbm'] !== null ? (int)$s['tx_power_dbm'] . ' dBm' : '—' ?></td>
            <td style="text-align:right;">
              <?php
                $cc  = (int)($s['customer_count'] ?? 0);
                $max = $s['max_clients'];
              ?>
              <strong<?= $cc === 0 ? ' class="muted"' : '' ?>><?= $cc ?></strong>
              <?php if ($max !== null): ?>
                <small class="muted"> / <?= (int)$max ?></small>
              <?php endif; ?>
              <?php if ($max !== null && $cc >= $max && $max > 0): ?>
                <br><small style="color:#d44;">at capacity</small>
              <?php endif; ?>
            </td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm">Edit</summary>
                <form method="post" class="form form-grid" style="margin-top:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <div class="field"><label>Name</label>
                    <input type="text" name="name" required maxlength="120" value="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Tower</label>
                    <select name="tower_id" required>
                      <?php foreach ($towers as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (int)$s['tower_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($t['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>AP device</label>
                    <select name="ap_device_id">
                      <option value="">— none —</option>
                      <?php foreach ($all_devices as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= (int)$s['ap_device_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($d['name']) ?>
                          <?= $d['role'] !== 'ap' ? ' [' . htmlspecialchars($d['role']) . ']' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Azimuth (&deg;)</label>
                    <input type="number" min="0" max="359" name="azimuth_deg" value="<?= $s['azimuth_deg'] !== null ? (int)$s['azimuth_deg'] : '' ?>">
                  </div>
                  <div class="field"><label>Beamwidth (&deg;)</label>
                    <input type="number" min="1" max="360" name="beamwidth_deg" value="<?= $s['beamwidth_deg'] !== null ? (int)$s['beamwidth_deg'] : '' ?>">
                  </div>
                  <div class="field"><label>Band</label>
                    <select name="band">
                      <?php foreach (SECTOR_BANDS as $b): ?>
                        <option value="<?= $b ?>" <?= $s['band'] === $b ? 'selected' : '' ?>><?= $b ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Frequency (MHz)</label>
                    <input type="number" min="0" name="frequency_mhz" value="<?= $s['frequency_mhz'] !== null ? (int)$s['frequency_mhz'] : '' ?>">
                  </div>
                  <div class="field"><label>Channel width (MHz)</label>
                    <input type="number" min="0" name="channel_width_mhz" value="<?= $s['channel_width_mhz'] !== null ? (int)$s['channel_width_mhz'] : '' ?>">
                  </div>
                  <div class="field"><label>TX power (dBm)</label>
                    <input type="number" min="-128" max="127" name="tx_power_dbm" value="<?= $s['tx_power_dbm'] !== null ? (int)$s['tx_power_dbm'] : '' ?>">
                  </div>
                  <div class="field"><label>Max clients</label>
                    <input type="number" min="0" name="max_clients" value="<?= $s['max_clients'] !== null ? (int)$s['max_clients'] : '' ?>">
                  </div>
                  <div class="field" style="grid-column:1/-1;"><label>Notes</label>
                    <textarea name="notes" rows="2"><?= htmlspecialchars((string)($s['notes'] ?? '')) ?></textarea>
                  </div>
                  <div class="form-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </div>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete <?= htmlspecialchars($s['name'], ENT_QUOTES) ?>? Wireless links pointing at this sector will be unlinked.">
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
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Add sector</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Name</label>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. Sector 3 East">
    </div>
    <div class="field"><label>Tower</label>
      <select name="tower_id" required>
        <?php foreach ($towers as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= $filters['tower_id'] === (int)$t['id'] ? ' selected' : '' ?>>
            <?= htmlspecialchars($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>AP device</label>
      <select name="ap_device_id">
        <option value="">— none —</option>
        <?php foreach ($all_devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>">
            <?= htmlspecialchars($d['name']) ?>
            <?= $d['role'] !== 'ap' ? ' [' . htmlspecialchars($d['role']) . ']' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Azimuth (&deg;)</label>
      <input type="number" min="0" max="359" name="azimuth_deg" placeholder="0–359">
    </div>
    <div class="field"><label>Beamwidth (&deg;)</label>
      <input type="number" min="1" max="360" name="beamwidth_deg" placeholder="60, 90, 120">
    </div>
    <div class="field"><label>Band</label>
      <select name="band">
        <?php foreach (SECTOR_BANDS as $b): ?>
          <option value="<?= $b ?>" <?= $b === '5GHz' ? 'selected' : '' ?>><?= $b ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Frequency (MHz)</label>
      <input type="number" min="0" name="frequency_mhz" placeholder="e.g. 5500">
    </div>
    <div class="field"><label>Channel width (MHz)</label>
      <input type="number" min="0" name="channel_width_mhz" placeholder="20, 40, 80">
    </div>
    <div class="field"><label>TX power (dBm)</label>
      <input type="number" min="-128" max="127" name="tx_power_dbm">
    </div>
    <div class="field"><label>Max clients</label>
      <input type="number" min="0" name="max_clients">
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2"></textarea>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary">Add sector</button>
    </div>
  </form>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
