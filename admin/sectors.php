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
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/totp.php';
require_once __DIR__ . '/../auth/poll_status.php';

$self = '/admin/sectors.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
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

    if ($action === 'bulk_apply') {
        $ids   = array_filter(array_map('intval', (array)($_POST['sector_ids'] ?? [])));
        $freq  = (int)($_POST['frequency_mhz']     ?? 0);
        $width = (int)($_POST['channel_width_mhz'] ?? 0);
        $txp   = $_POST['tx_power_dbm'] !== '' ? (int)$_POST['tx_power_dbm'] : null;
        if (!totp_require_step_up($user, (string)($_POST['totp_code'] ?? ''))) {
            flash('error', 'Two-factor code is required for bulk push-to-radio.');
            header('Location: ' . $self);
            exit;
        }
        if (!$ids) {
            flash('error', 'Select at least one sector.');
            header('Location: ' . $self);
            exit;
        }
        $payload = [];
        if ($freq  > 0) $payload['frequency_mhz']     = $freq;
        if ($width > 0) $payload['channel_width_mhz'] = $width;
        if ($txp !== null) $payload['tx_power_dbm']  = $txp;
        if (!$payload) {
            flash('error', 'Set at least one of frequency, channel width or TX power.');
            header('Location: ' . $self);
            exit;
        }
        $queued = 0;
        foreach ($ids as $sid) {
            wireless_change_job_enqueue('sector', (int)$sid, (int)$user['id'], $payload);
            $queued++;
        }
        audit_log('sector.bulk_apply', [
            'meta' => ['count' => $queued, 'payload_keys' => array_keys($payload)],
        ]);
        flash('success', "Queued $queued change job(s). Worker will pick them up within 60 s.");
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

// One-pass overlap detection across the whole inventory. Doing it here
// instead of calling sectors_overlap_check() per row means O(n²) in
// PHP rather than O(n²) round-trips to MySQL — fine for thousands of
// sectors. Only sectors with both freq + width set can overlap.
$overlap_count = [];
foreach ($sectors as $a) {
    if (!$a['frequency_mhz'] || !$a['channel_width_mhz']) continue;
    foreach ($sectors as $b) {
        if ($a['id'] === $b['id']) continue;
        if ($a['band'] !== $b['band']) continue;
        if (!$b['frequency_mhz'] || !$b['channel_width_mhz']) continue;
        $sep  = abs((int)$a['frequency_mhz'] - (int)$b['frequency_mhz']);
        $half = ((int)$a['channel_width_mhz'] + (int)$b['channel_width_mhz']) / 2.0;
        if ($half - $sep <= 0) continue;
        // Tower distance gate — same SECTOR_OVERLAP_DISTANCE_KM threshold
        // as sectors_overlap_check(). Look up tower lat/lng from the
        // already-loaded $towers list so this stays in-process.
        $ta = $tb = null;
        foreach ($towers as $t) {
            if ((int)$t['id'] === (int)$a['tower_id']) $ta = $t;
            if ((int)$t['id'] === (int)$b['tower_id']) $tb = $t;
        }
        if (!$ta || !$tb) continue;
        $km = haversine_km((float)$ta['lat'], (float)$ta['lng'], (float)$tb['lat'], (float)$tb['lng']);
        if ($km > SECTOR_OVERLAP_DISTANCE_KM) continue;
        $overlap_count[(int)$a['id']] = ($overlap_count[(int)$a['id']] ?? 0) + 1;
    }
}

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

<?php $sectors_freshness = poll_classify(poll_latest_link_sample_at()); ?>
<div class="portal-head">
  <h1>Sectors <?= poll_badge_html($sectors_freshness, 'Newest sector member-link sample') ?></h1>
  <p class="portal-sub">AP-on-tower configurations: where each AP is pointed, what band it's on, and how much power it pushes. Live metrics (noise, clients, utilisation) flow in from <code>bin/poll-wireless.php</code> via the AP's connected stations — see <a href="/admin/diagnostics.php">polling status</a> if the badge is amber or red.</p>
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
    <div class="empty-state">
      <div class="empty-icon">⌖</div>
      <h3>No sectors yet</h3>
      <p>Each tower can host multiple sector APs pointed in different directions. Define one with the form below, or draw it visually on the network map.</p>
      <a class="btn btn-primary" href="/admin/map.php">Open the map</a>
    </div>
  <?php else: ?>
    <form method="post" id="bulk-sectors"
          onsubmit="<?= !empty($user['totp_enabled'])
              ? "var c=prompt('Two-factor code:');if(!c)return false;this.totp_code.value=c;"
              : '' ?>return confirm('Queue this change for every selected sector?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_apply">
    <input type="hidden" name="totp_code" value="">
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('input[name=&quot;sector_ids[]&quot;]').forEach(c => c.checked = this.checked)"></th>
          <th>Name</th><th>Tower</th><th>AP device</th>
          <th>Azimuth / beam</th><th>Band</th><th>Frequency</th><th>TX</th>
          <th>Capacity</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sectors as $s): ?>
          <tr>
            <td><input type="checkbox" name="sector_ids[]" value="<?= (int)$s['id'] ?>"></td>
            <td><strong><?= htmlspecialchars($s['name']) ?></strong>
              <?php if (!empty($overlap_count[(int)$s['id']])): ?>
                <br><span title="Co-channel overlap with neighbour sectors — open the sector to investigate."
                         style="display:inline-block;background:#f97316;color:#fff;padding:1px 7px;border-radius:6px;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-top:4px;">⚠ <?= (int)$overlap_count[(int)$s['id']] ?> overlap</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($s['tower_name'] ?? $tower_label((int)$s['tower_id'])) ?></td>
            <td>
              <?php if ($s['ap_device_id']):
                $apc = ['online'=>'#0c8','offline'=>'#d44','unknown'=>'#888','retired'=>'#555'];
                $apb = $apc[$s['ap_device_status'] ?? 'unknown'] ?? '#888';
              ?>
                <a href="/admin/device-view.php?id=<?= (int)$s['ap_device_id'] ?>" style="color:inherit;">
                  <?= htmlspecialchars($s['ap_device_name'] ?? ('#' . $s['ap_device_id'])) ?>
                </a>
                <span style="display:inline-block;background:<?= $apb ?>;color:#fff;padding:1px 5px;border-radius:6px;font-size:10px;margin-left:4px;">
                  <?= htmlspecialchars($s['ap_device_status'] ?? 'unknown') ?>
                </span>
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
            <td style="min-width:120px;">
              <?php
                $cc  = (int)($s['customer_count'] ?? 0);
                $max = $s['max_clients'];
                $pct = ($max && $max > 0) ? min(100, round(($cc / $max) * 100)) : null;
                $cap_class = '';
                if ($pct !== null) {
                  if      ($pct >= 100) $cap_class = ' cap-full';
                  elseif  ($pct >= 80)  $cap_class = ' cap-warn';
                }
              ?>
              <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:.85rem;">
                <strong<?= $cc === 0 ? ' class="muted"' : '' ?>><?= $cc ?><?= $max !== null ? ' / ' . (int)$max : '' ?></strong>
                <?php if ($pct !== null): ?>
                  <small class="muted"><?= $pct ?>%</small>
                <?php endif; ?>
              </div>
              <?php if ($pct !== null): ?>
                <div class="cap-bar" title="<?= $cc ?> of <?= (int)$max ?> max clients">
                  <span class="cap-bar-fill<?= $cap_class ?>" style="width: <?= $pct ?>%;"></span>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn btn-primary btn-sm" href="/admin/sector-view.php?id=<?= (int)$s['id'] ?>">Open</a>
              <a class="btn btn-ghost btn-sm" href="/admin/sector-edit.php?id=<?= (int)$s['id'] ?>">Edit</a>
              <details style="margin-top:6px;">
                <summary class="btn btn-ghost btn-sm">Quick edit</summary>
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
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-top:14px;padding:12px;background:rgba(0,0,0,0.03);border-radius:8px;">
      <strong style="margin-right:8px;align-self:center;">Bulk apply to selected:</strong>
      <div class="field" style="margin:0;"><label>Frequency (MHz)</label>
        <input type="number" name="frequency_mhz" placeholder="e.g. 5200" style="width:120px;"></div>
      <div class="field" style="margin:0;"><label>Width (MHz)</label>
        <select name="channel_width_mhz" style="width:90px;">
          <option value="">—</option>
          <?php foreach ([5,8,10,20,30,40,60,80,160] as $w): ?>
            <option value="<?= $w ?>"><?= $w ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field" style="margin:0;"><label>TX power (dBm)</label>
        <input type="number" name="tx_power_dbm" placeholder="" style="width:90px;"></div>
      <button type="submit" class="btn btn-primary btn-sm">Queue change</button>
      <small class="muted" style="align-self:center;">Each selected sector becomes a separate <code>wireless_change_jobs</code> row. Empty fields are unchanged.</small>
    </div>
    </form>
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
