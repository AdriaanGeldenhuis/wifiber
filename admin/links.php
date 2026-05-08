<?php
/**
 * Wireless links — every AP↔CPE pairing in one place. Health-coloured
 * pills, filters, click into /admin/link-view.php for the live UISP-
 * style dashboard. New links are usually auto-created by the polling
 * worker on first sighting; the form here is for manually wiring a
 * link that hasn't been polled yet (e.g. a brand-new install).
 */
$page_title = 'Wireless links';
$active_key = 'links';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/csv.php';

$self = '/admin/links.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $saved = wireless_link_save([
                'ap_device_id'  => $_POST['ap_device_id']  ?? 0,
                'cpe_device_id' => $_POST['cpe_device_id'] ?? null,
                'sector_id'     => $_POST['sector_id']     ?? null,
                'customer_id'   => $_POST['customer_id']   ?? null,
                'ssid'          => $_POST['ssid']          ?? '',
                'ap_mac'        => $_POST['ap_mac']        ?? '',
                'station_mac'   => $_POST['station_mac']   ?? '',
            ], $id ?: null);
            audit_log('wireless_link.save', ['target_type' => 'wireless_link', 'target_id' => $saved]);
            flash('success', $id ? 'Link updated.' : 'Link added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            wireless_link_delete($id);
            audit_log('wireless_link.delete', ['target_type' => 'wireless_link', 'target_id' => $id]);
            flash('success', 'Link deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'save_site_link') {
        $edit_id = (int)($_POST['id'] ?? 0);
        try {
            $saved = site_link_save([
                'from_site_id'  => (int)($_POST['from_site_id'] ?? 0),
                'to_site_id'    => (int)($_POST['to_site_id']   ?? 0),
                'type'          => $_POST['type']          ?? 'ptp',
                'label'         => $_POST['label']         ?? '',
                'capacity_mbps' => $_POST['capacity_mbps'] ?? null,
                'frequency'     => $_POST['frequency']     ?? '',
                'color'         => $_POST['color']         ?? '',
                'notes'         => $_POST['notes']         ?? '',
            ], $edit_id ?: null);
            audit_log($edit_id ? 'site_link.update' : 'site_link.create',
                      ['target_type' => 'site_link', 'target_id' => $saved]);
            flash('success', $edit_id ? 'Backbone link updated.' : 'Backbone link added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self . '#backbone');
        exit;
    }

    if ($action === 'delete_site_link') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            site_link_delete($id);
            audit_log('site_link.delete', ['target_type' => 'site_link', 'target_id' => $id]);
            flash('success', 'Backbone link removed.');
        }
        header('Location: ' . $self . '#backbone');
        exit;
    }
}

$filters = [
    'sector_id'    => (int)($_GET['sector_id'] ?? 0),
    'ap_device_id' => (int)($_GET['ap_device_id'] ?? 0),
    'health_max'   => $_GET['health_max'] ?? '',
    'search'       => trim((string)($_GET['search'] ?? '')),
];

$links   = wireless_links_all($filters);

if (($_GET['export'] ?? '') === 'csv') {
    audit_log('wireless_links.export', ['meta' => ['rows' => count($links)]]);
    csv_download('wireless-links', $links, [
        'id', 'ap_name', 'cpe_name', 'sector_name', 'customer_name', 'customer_surname',
        'ssid', 'frequency_mhz', 'channel_width_mhz', 'wireless_mode', 'security',
        'signal_dbm', 'signal_dbm_remote', 'noise_dbm', 'snr_db', 'ccq_pct',
        'tx_rate_mbps', 'rx_rate_mbps',
        'airtime_local_pct', 'airtime_remote_pct',
        'capacity_local_mbps', 'capacity_remote_mbps',
        'throughput_local_mbps', 'throughput_remote_mbps',
        'distance_km', 'health_score', 'last_evaluated_at',
    ]);
}
$devices = devices_all(null);
$sectors = sectors_all(null);

$ap_devices  = array_values(array_filter($devices, fn ($d) => in_array($d['role'], ['ap', 'backhaul'], true)));
$cpe_devices = array_values(array_filter($devices, fn ($d) => in_array($d['role'], ['cpe', 'backhaul'], true)));

$site_links_rows = site_links_with_sites();
$sites           = sites_all();

// Server-side edit: ?edit=N pre-fills the form below the table with that
// backbone link's current values. The form's hidden id field then makes
// save_site_link UPDATE in place instead of INSERT.
$edit_sl_id = (int)($_GET['edit'] ?? 0);
$edit_sl    = $edit_sl_id ? site_link_find($edit_sl_id) : null;

$site_link_type_labels = [
    'ptp'      => 'Point-to-point',
    'ptmp'     => 'Point-to-multipoint',
    'fiber'    => 'Fibre',
    'backhaul' => 'Backhaul',
];
$site_link_type_color = [
    'ptp'      => '#08e',
    'ptmp'     => '#0c8',
    'fiber'    => '#f0a',
    'backhaul' => '#f80',
];

$health_pill = function (?int $score): string {
    if ($score === null) {
        return '<span class="link-pill" style="background:#888;color:#fff;">no data</span>';
    }
    [$bg, $label] = match (true) {
        $score >= 75 => ['#0c8', 'good'],
        $score >= 50 => ['#e8a814', 'fair'],
        default      => ['#d44', 'poor'],
    };
    return sprintf('<span class="link-pill" style="background:%s;color:#fff;">%d · %s</span>', $bg, $score, $label);
};
?>

<style>
  .link-pill { display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;letter-spacing:.02em; }
  .data-table tr.row-poor td { background:rgba(212,68,68,0.06); }
  .data-table tr.row-fair td { background:rgba(232,168,20,0.06); }

  /* Make every cell of a backbone row a transparent <a> covering the
     full cell area — the entire row becomes a clickable link to the
     server-rendered edit form below the table, with no JS. The actions
     cell on the right still hosts its own buttons (Edit link + Delete
     form) and lives outside this treatment. */
  .data-table tr.is-clickable td { transition:background .12s; }
  .data-table tr.is-clickable:hover td { background:rgba(5,218,253,0.06); }
  .data-table tr.is-clickable td.cell-link { padding:0; }
  .data-table tr.is-clickable td.cell-link > a {
    display:block; padding:12px 14px; color:inherit; text-decoration:none;
  }
  .data-table tr.is-clickable td.cell-link > a:hover { color:inherit; }

  /* Visual highlight on the row currently being edited so it's obvious
     which one you opened. */
  .sl-row-editing td { background:rgba(5,218,253,0.10) !important; outline:1px solid var(--accent); outline-offset:-1px; }
</style>

<div class="portal-head">
  <h1>Links</h1>
  <p class="portal-sub">Every link in the network: customer AP↔CPE wireless links (auto-registered by the polling worker) and the backbone PTP / fibre / backhaul lines drawn between sites. Jump to <a href="#backbone">backbone links</a>.</p>
</div>

<div class="portal-card">
  <h2>Filter</h2>
  <form method="get" class="form form-grid">
    <div class="field"><label>Search</label>
      <input type="text" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES) ?>"
             placeholder="AP / CPE / SSID / customer">
    </div>
    <div class="field"><label>Sector</label>
      <select name="sector_id">
        <option value="0">— any —</option>
        <?php foreach ($sectors as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $filters['sector_id'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>AP device</label>
      <select name="ap_device_id">
        <option value="0">— any —</option>
        <?php foreach ($ap_devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $filters['ap_device_id'] === (int)$d['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Max health</label>
      <select name="health_max">
        <option value="">— any —</option>
        <option value="49"  <?= $filters['health_max'] === '49'  ? 'selected' : '' ?>>poor (&lt; 50)</option>
        <option value="74"  <?= $filters['health_max'] === '74'  ? 'selected' : '' ?>>fair (&lt; 75)</option>
      </select>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="<?= $self ?>" class="btn btn-ghost btn-sm">Reset</a>
      <?php
        $export_qs = http_build_query(array_filter([
          'sector_id'    => $filters['sector_id'] ?: null,
          'ap_device_id' => $filters['ap_device_id'] ?: null,
          'health_max'   => $filters['health_max'] !== '' ? $filters['health_max'] : null,
          'search'       => $filters['search'] !== ''     ? $filters['search']     : null,
          'export'       => 'csv',
        ]));
      ?>
      <a href="<?= $self ?>?<?= htmlspecialchars($export_qs) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Wireless customer links <span class="muted">(<?= count($links) ?>)</span></h2>
  <?php if (!$links): ?>
    <div class="empty-state">
      <div class="empty-icon">📡</div>
      <h3>No wireless links yet</h3>
      <p>Add credentials to an AP in <a href="/admin/devices.php">/admin/devices.php</a>, then run
        <code>php bin/poll-wireless.php</code>. Stations attached to the AP will register here automatically.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Health</th>
          <th>AP</th>
          <th>CPE / customer</th>
          <th>Freq / width</th>
          <th>Signal (L / R)</th>
          <th>SNR</th>
          <th>CCQ</th>
          <th>TX / RX rate</th>
          <th>Distance</th>
          <th>Last sample</th>
          <th>Alerts</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($links as $l): ?>
          <?php
            $row_class = '';
            if ($l['health_score'] !== null) {
                if      ($l['health_score'] < 50) $row_class = 'row-poor';
                elseif  ($l['health_score'] < 75) $row_class = 'row-fair';
            }
            $cust = trim(($l['customer_name'] ?? '') . ' ' . ($l['customer_surname'] ?? ''));
          ?>
          <tr class="<?= $row_class ?>">
            <td><?= $health_pill($l['health_score']) ?></td>
            <td>
              <strong><?= htmlspecialchars($l['ap_name']) ?></strong>
              <?php if ($l['ap_model']): ?><br><small class="muted"><?= htmlspecialchars($l['ap_model']) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if ($l['cpe_name']): ?>
                <strong><?= htmlspecialchars($l['cpe_name']) ?></strong>
              <?php else: ?>
                <span class="muted">— unclaimed —</span>
              <?php endif; ?>
              <?php if ($cust !== ''): ?><br><small><?= htmlspecialchars($cust) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if ($l['frequency_mhz']): ?>
                <?= (int)$l['frequency_mhz'] ?> MHz
                <?php if ($l['channel_width_mhz']): ?>
                  <br><small class="muted"><?= (int)$l['channel_width_mhz'] ?> MHz wide</small>
                <?php endif; ?>
              <?php else: ?><small class="muted">—</small><?php endif; ?>
            </td>
            <td>
              <?php if ($l['signal_dbm'] !== null): ?>
                <?= (int)$l['signal_dbm'] ?>
                <?php if ($l['signal_dbm_remote'] ?? null): ?> / <?= (int)$l['signal_dbm_remote'] ?><?php endif; ?>
                <small class="muted">dBm</small>
              <?php else: ?><small class="muted">—</small><?php endif; ?>
            </td>
            <td>
              <?php if ($l['snr_db'] !== null): ?>
                <?= (int)$l['snr_db'] ?> <small class="muted">dB</small>
              <?php else: ?><small class="muted">—</small><?php endif; ?>
            </td>
            <td>
              <?= $l['ccq_pct'] !== null ? number_format((float)$l['ccq_pct'], 0) . '%' : '<small class="muted">—</small>' ?>
            </td>
            <td>
              <?php if ($l['tx_rate_mbps'] !== null): ?>
                <?= number_format((float)$l['tx_rate_mbps'], 0) ?> /
                <?= number_format((float)($l['rx_rate_mbps'] ?? 0), 0) ?>
                <small class="muted">Mbps</small>
              <?php else: ?><small class="muted">—</small><?php endif; ?>
            </td>
            <td>
              <?= $l['distance_km'] !== null
                  ? '<small>' . number_format((float)$l['distance_km'], 2) . ' km</small>'
                  : '<small class="muted">—</small>' ?>
            </td>
            <td>
              <?= $l['last_evaluated_at']
                  ? '<small>' . htmlspecialchars((string)$l['last_evaluated_at']) . '</small>'
                  : '<small class="muted">never</small>' ?>
            </td>
            <td>
              <?php $aa = (int)($l['active_alerts'] ?? 0); ?>
              <?php if ($aa > 0): ?>
                <span class="link-pill" style="background:#d44;color:#fff;"><?= $aa ?></span>
              <?php else: ?>
                <small class="muted">—</small>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=<?= (int)$l['id'] ?>">Open</a>
              <a class="btn btn-ghost btn-sm" href="/admin/link-history.php?id=<?= (int)$l['id'] ?>&days=7"  title="7-day trends">7d</a>
              <a class="btn btn-ghost btn-sm" href="/admin/link-history.php?id=<?= (int)$l['id'] ?>&days=30" title="30-day trends">30d</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card" id="backbone">
  <h2>Backbone links <span class="muted">(<?= count($site_links_rows) ?>)</span></h2>
  <p class="muted">Site-to-site PTP / fibre / backhaul connections — the lines you see on the <a href="/admin/map.php">network map</a>. Edits made here update the map immediately.</p>
  <?php if (!$site_links_rows): ?>
    <div class="empty-state">
      <div class="empty-icon">🛰️</div>
      <h3>No backbone links yet</h3>
      <p>Draw them on the <a href="/admin/map.php">network map</a> by clicking two sites in turn, or pre-stage one with the form below.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>From → To</th>
          <th>Label</th>
          <th>Capacity</th>
          <th>Frequency</th>
          <th>Distance</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($site_links_rows as $sl): ?>
          <?php
            $type_lbl = $site_link_type_labels[$sl['type']] ?? $sl['type'];
            $type_bg  = $site_link_type_color[$sl['type']]  ?? '#888';
            // Row click → full UISP-style dashboard. The Edit button on
            // the right takes the operator to the quick-edit form below
            // the table for fast inline tweaks.
            $view_url = '/admin/site-link-view.php?id=' . (int)$sl['id'];
            $edit_url = $self . '?edit=' . (int)$sl['id'] . '#backbone-form';
            $is_editing = $edit_sl && (int)$edit_sl['id'] === (int)$sl['id'];
            $aria       = 'Open backbone link ' . $sl['from_name'] . ' to ' . $sl['to_name'];
          ?>
          <tr class="is-clickable<?= $is_editing ? ' sl-row-editing' : '' ?>">
            <td class="cell-link">
              <a href="<?= htmlspecialchars($view_url) ?>" aria-label="<?= htmlspecialchars($aria) ?>">
                <span class="link-pill" style="background:<?= $type_bg ?>;color:#fff;">
                  <?= htmlspecialchars($type_lbl) ?>
                </span>
              </a>
            </td>
            <td class="cell-link">
              <a href="<?= htmlspecialchars($view_url) ?>" aria-label="<?= htmlspecialchars($aria) ?>">
                <strong><?= htmlspecialchars($sl['from_name']) ?></strong>
                <span class="muted">→</span>
                <strong><?= htmlspecialchars($sl['to_name']) ?></strong>
              </a>
            </td>
            <td class="cell-link">
              <a href="<?= htmlspecialchars($view_url) ?>" aria-label="<?= htmlspecialchars($aria) ?>">
                <?= $sl['label'] !== ''
                    ? htmlspecialchars($sl['label'])
                    : '<small class="muted">—</small>' ?>
              </a>
            </td>
            <td class="cell-link">
              <a href="<?= htmlspecialchars($view_url) ?>" aria-label="<?= htmlspecialchars($aria) ?>">
                <?= $sl['capacity_mbps'] !== null
                    ? number_format((float)$sl['capacity_mbps'], 0) . ' <small class="muted">Mbps</small>'
                    : '<small class="muted">—</small>' ?>
              </a>
            </td>
            <td class="cell-link">
              <a href="<?= htmlspecialchars($view_url) ?>" aria-label="<?= htmlspecialchars($aria) ?>">
                <?= $sl['frequency']
                    ? htmlspecialchars((string)$sl['frequency'])
                    : '<small class="muted">—</small>' ?>
              </a>
            </td>
            <td class="cell-link">
              <a href="<?= htmlspecialchars($view_url) ?>" aria-label="<?= htmlspecialchars($aria) ?>">
                <small><?= number_format($sl['distance_km'], 2) ?> km</small>
              </a>
            </td>
            <td class="row-actions">
              <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($edit_url) ?>">Edit</a>
              <form method="post" class="inline-form" data-confirm="Delete this backbone link?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_site_link">
                <input type="hidden" name="id" value="<?= (int)$sl['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <small class="muted">Click any row to open the full backbone dashboard. Use <strong>Edit</strong> for a quick edit form below.</small>
  <?php endif; ?>
</div>

<?php
/* Server-rendered Add / Edit form. Switches mode based on $edit_sl.
   Field defaults pull from the link being edited, otherwise are empty. */
$f_from   = $edit_sl ? (int)$edit_sl['from_site_id']  : 0;
$f_to     = $edit_sl ? (int)$edit_sl['to_site_id']    : 0;
$f_type   = $edit_sl ? (string)$edit_sl['type']       : 'ptp';
$f_label  = $edit_sl ? (string)$edit_sl['label']      : '';
$f_cap    = $edit_sl && $edit_sl['capacity_mbps'] !== null ? (string)$edit_sl['capacity_mbps'] : '';
$f_freq   = $edit_sl ? (string)($edit_sl['frequency'] ?? '') : '';
$f_color  = $edit_sl ? (string)($edit_sl['color']     ?? '') : '';
$f_notes  = $edit_sl ? (string)($edit_sl['notes']     ?? '') : '';
?>
<div class="portal-card" id="backbone-form">
  <h2><?= $edit_sl ? 'Edit backbone link' : 'Add a backbone link' ?></h2>
  <p class="muted">
    <?php if ($edit_sl):
      // Look up the from/to site names for the descriptive heading.
      $edit_from = site_find((int)$edit_sl['from_site_id']);
      $edit_to   = site_find((int)$edit_sl['to_site_id']);
      $edit_lbl  = trim(($edit_from['name'] ?? '#' . (int)$edit_sl['from_site_id'])
                  . ' → ' . ($edit_to['name'] ?? '#' . (int)$edit_sl['to_site_id']));
    ?>
      Editing <strong><?= htmlspecialchars($edit_lbl) ?></strong> · any changes update the map immediately.
      <a class="btn btn-ghost btn-sm" href="<?= $self ?>#backbone" style="margin-left:8px;">Cancel edit</a>
    <?php else: ?>
      Wire two sites together without leaving this page. For graphical placement use the <a href="/admin/map.php">network map</a>.
    <?php endif; ?>
  </p>
  <?php if (count($sites) < 2): ?>
    <p class="muted">You need at least two sites before you can link them. Add some on <a href="/admin/sites.php">/admin/sites.php</a>.</p>
  <?php else: ?>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_site_link">
      <?php if ($edit_sl): ?>
        <input type="hidden" name="id" value="<?= (int)$edit_sl['id'] ?>">
      <?php endif; ?>
      <div class="field"><label>From site *</label>
        <select name="from_site_id" required>
          <option value="">— pick —</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $f_from === (int)$s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>To site *</label>
        <select name="to_site_id" required>
          <option value="">— pick —</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $f_to === (int)$s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Type</label>
        <select name="type">
          <?php foreach ($site_link_type_labels as $k => $lbl): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $f_type === $k ? 'selected' : '' ?>>
              <?= htmlspecialchars($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Label</label>
        <input type="text" name="label" maxlength="120" placeholder="e.g. Tower A ↔ Tower B"
               value="<?= htmlspecialchars($f_label) ?>">
      </div>
      <div class="field"><label>Capacity (Mbps)</label>
        <input type="number" step="any" min="0" name="capacity_mbps" placeholder="e.g. 1000"
               value="<?= htmlspecialchars($f_cap) ?>">
      </div>
      <div class="field"><label>Frequency</label>
        <input type="text" name="frequency" maxlength="20" placeholder="e.g. 5 GHz / fibre"
               value="<?= htmlspecialchars($f_freq) ?>">
      </div>
      <div class="field"><label>Map line colour</label>
        <input type="text" name="color" maxlength="20" placeholder="e.g. #08e or 'cyan' (optional)"
               value="<?= htmlspecialchars($f_color) ?>">
      </div>
      <div class="field" style="grid-column:1/-1;"><label>Notes</label>
        <textarea name="notes" rows="2" maxlength="2000"><?= htmlspecialchars($f_notes) ?></textarea>
      </div>
      <div class="form-actions" style="grid-column:1/-1;">
        <button type="submit" class="btn btn-primary btn-sm">
          <?= $edit_sl ? 'Save changes' : 'Add backbone link' ?>
        </button>
        <?php if ($edit_sl): ?>
          <a class="btn btn-ghost btn-sm" href="<?= $self ?>#backbone">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Pre-stage a new link</h2>
  <p class="muted">Use this for a brand-new install where the CPE hasn't been seen by the AP yet. Once the polling worker observes the station MAC the live values fill in automatically.</p>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>AP device *</label>
      <select name="ap_device_id" required>
        <option value="">— pick —</option>
        <?php foreach ($ap_devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>CPE device</label>
      <select name="cpe_device_id">
        <option value="">— pick —</option>
        <?php foreach ($cpe_devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Sector</label>
      <select name="sector_id">
        <option value="">— pick —</option>
        <?php foreach ($sectors as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>SSID</label><input type="text" name="ssid" maxlength="64"></div>
    <div class="field"><label>AP MAC</label><input type="text" name="ap_mac" maxlength="20"></div>
    <div class="field"><label>Station MAC</label><input type="text" name="station_mac" maxlength="20"></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Add link</button>
    </div>
  </form>
</div>
