<?php
/**
 * Network map — Leaflet over OSM/Esri tiles. Shows sites, links between
 * sites, and clients geocoded from their address. Supports drag-to-move,
 * add/delete via map popups and a side panel, and a Nominatim geocoder.
 *
 * Most actions are AJAX (POST with ?ajax=1) and return JSON. The page
 * itself is a normal HTML render.
 */
$page_title = 'Network map';
$active_key = 'map';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/outages.php';

$is_ajax = !empty($_GET['ajax']);
$reply   = function (array $payload) use ($is_ajax) {
    // _layout.php has already started buffering and emitted the page
    // chrome — discard it so the response body is clean JSON.
    while (ob_get_level() > 0) ob_end_clean();
    if (!$is_ajax) {
        flash($payload['ok'] ? 'success' : 'error', (string)($payload['message'] ?? $payload['error'] ?? 'OK'));
        header('Location: /admin/map.php');
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

// Shape a sector row for the JS the same way the bootstrap payload
// does, so the client can drop it straight into its index after a
// create/update without a full reload.
$sector_shape = function (array $s, ?array $ap_device): array {
    return [
        'id'                => (int)$s['id'],
        'tower_id'          => (int)$s['tower_id'],
        'ap_device_id'      => $s['ap_device_id'],
        'ap_device_name'    => $ap_device['name'] ?? null,
        'name'              => $s['name'],
        'azimuth_deg'       => $s['azimuth_deg'],
        'beamwidth_deg'     => $s['beamwidth_deg'],
        'band'              => $s['band'],
        'frequency_mhz'     => $s['frequency_mhz'],
        'channel_width_mhz' => $s['channel_width_mhz'],
        'tx_power_dbm'      => $s['tx_power_dbm'],
        'max_clients'       => $s['max_clients'],
        'notes'             => $s['notes'] ?? null,
    ];
};
$device_shape = function (array $d): array {
    return [
        'id'           => (int)$d['id'],
        'site_id'      => $d['site_id'],
        'name'         => $d['name'],
        'vendor'       => $d['vendor'],
        'model'        => $d['model'],
        'role'         => $d['role'],
        'mac'          => $d['mac'] ?? '',
        'mgmt_ip'      => $d['mgmt_ip'],
        'status'       => $d['status'],
        'notes'        => $d['notes'] ?? null,
        'last_seen_at' => $d['last_seen_at'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_site':
            case 'update_site': {
                $id    = $action === 'update_site' ? (int)($_POST['id'] ?? 0) : null;
                $newid = site_save($_POST, $id);
                audit_log('site.' . ($id ? 'update' : 'create'), [
                    'target_type' => 'site', 'target_id' => $newid,
                ]);
                $reply(['ok' => true, 'id' => $newid, 'message' => $id ? 'Site updated.' : 'Site added.']);
                break;
            }

            case 'move_site': {
                $id  = (int)($_POST['id'] ?? 0);
                $lat = (float)($_POST['lat'] ?? 0);
                $lng = (float)($_POST['lng'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No site id.']);
                site_move($id, $lat, $lng);
                $reply(['ok' => true]);
                break;
            }

            case 'delete_site': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No site id.']);
                site_delete($id);
                audit_log('site.delete', ['target_type' => 'site', 'target_id' => $id]);
                $reply(['ok' => true, 'message' => 'Site removed.']);
                break;
            }

            case 'add_link': {
                $id = site_link_save($_POST);
                audit_log('site_link.create', ['target_type' => 'site_link', 'target_id' => $id]);
                $reply(['ok' => true, 'id' => $id, 'message' => 'Link added.']);
                break;
            }

            case 'delete_link': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No link id.']);
                site_link_delete($id);
                audit_log('site_link.delete', ['target_type' => 'site_link', 'target_id' => $id]);
                $reply(['ok' => true, 'message' => 'Link removed.']);
                break;
            }

            case 'move_client': {
                $id  = (int)($_POST['id'] ?? 0);
                $lat = (float)($_POST['lat'] ?? 0);
                $lng = (float)($_POST['lng'] ?? 0);
                $u = $id ? find_user_by_id($id) : null;
                if (!$u || ($u['role'] ?? '') !== 'client') {
                    $reply(['ok' => false, 'error' => 'Client not found.']);
                }
                update_user($id, function (array $u) use ($lat, $lng) {
                    $u['lat'] = $lat;
                    $u['lng'] = $lng;
                    return $u;
                });
                $reply(['ok' => true]);
                break;
            }

            case 'geocode_client': {
                $id = (int)($_POST['id'] ?? 0);
                $u  = $id ? find_user_by_id($id) : null;
                if (!$u || ($u['role'] ?? '') !== 'client' || empty($u['address'])) {
                    $reply(['ok' => false, 'error' => 'Client has no address to geocode.']);
                }
                $hit = geocode_address((string)$u['address']);
                if (!$hit) $reply(['ok' => false, 'error' => 'Nominatim found nothing for that address.']);
                update_user($id, function (array $u) use ($hit) {
                    $u['lat'] = $hit['lat'];
                    $u['lng'] = $hit['lng'];
                    return $u;
                });
                $reply([
                    'ok' => true,
                    'lat' => $hit['lat'], 'lng' => $hit['lng'],
                    'display_name' => $hit['display_name'],
                    'message' => 'Located: ' . $hit['display_name'],
                ]);
                break;
            }

            case 'add_sector':
            case 'update_sector': {
                $id    = $action === 'update_sector' ? (int)($_POST['id'] ?? 0) : null;
                $newid = sector_save($_POST, $id);
                audit_log('sector.' . ($id ? 'update' : 'create'), [
                    'target_type' => 'sector', 'target_id' => $newid,
                ]);
                $row = sector_find($newid);
                $ap  = (!empty($row['ap_device_id'])) ? device_find((int)$row['ap_device_id']) : null;
                $reply([
                    'ok'      => true,
                    'id'      => $newid,
                    'sector'  => $row ? $sector_shape($row, $ap) : null,
                    'message' => $id ? 'Sector updated.' : 'Sector added.',
                ]);
                break;
            }

            case 'delete_sector': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No sector id.']);
                sector_delete($id);
                audit_log('sector.delete', ['target_type' => 'sector', 'target_id' => $id]);
                $reply(['ok' => true, 'message' => 'Sector removed.']);
                break;
            }

            case 'add_device':
            case 'update_device': {
                $id    = $action === 'update_device' ? (int)($_POST['id'] ?? 0) : null;
                $newid = device_save($_POST, $id);
                audit_log('device.' . ($id ? 'update' : 'create'), [
                    'target_type' => 'device', 'target_id' => $newid,
                ]);
                $row = device_find($newid);
                $reply([
                    'ok'      => true,
                    'id'      => $newid,
                    'device'  => $row ? $device_shape($row) : null,
                    'message' => $id ? 'Device updated.' : 'Device added.',
                ]);
                break;
            }

            case 'delete_device': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No device id.']);
                device_delete($id);
                audit_log('device.delete', ['target_type' => 'device', 'target_id' => $id]);
                $reply(['ok' => true, 'message' => 'Device removed.']);
                break;
            }

            default:
                $reply(['ok' => false, 'error' => 'Unknown action.']);
        }
    } catch (Throwable $e) {
        $reply(['ok' => false, 'error' => $e->getMessage()]);
    }
}

$sites    = sites_all(false);
$links    = site_links_all();
$clients  = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client'));
$devices  = devices_all();
$sectors  = sectors_all();
$active_outages = outages_all(['status' => 'active'], 500);

// Index active outage scope_ids so the JS can highlight affected
// sectors and (transitively) towers without iterating per render.
$outage_sector_ids = [];
$outage_tower_ids  = [];
$outage_by_sector  = [];
foreach ($active_outages as $o) {
    if ($o['scope'] === 'sector' && $o['scope_id']) {
        $outage_sector_ids[] = (int)$o['scope_id'];
        $outage_by_sector[(int)$o['scope_id']] = [
            'id'             => (int)$o['id'],
            'started_at'     => $o['started_at'],
            'cause'          => $o['cause'],
            'affected_count' => (int)$o['affected_count'],
        ];
    } elseif ($o['scope'] === 'tower' && $o['scope_id']) {
        $outage_tower_ids[] = (int)$o['scope_id'];
    }
}
// A tower is also "affected" if any of its sectors are in outage,
// even without a tower-scope outage row of its own.
foreach ($sectors as $sec) {
    if (in_array((int)$sec['id'], $outage_sector_ids, true)) {
        $outage_tower_ids[] = (int)$sec['tower_id'];
    }
}
$outage_tower_ids = array_values(array_unique($outage_tower_ids));

// Build a sector-id → "Name · Tower" lookup so each client marker can
// surface the sector label in its popup without a per-client query.
$sector_label_by_id = [];
foreach ($sectors as $sec) {
    $label = $sec['name'];
    if (!empty($sec['tower_name'])) $label .= ' · ' . $sec['tower_name'];
    $sector_label_by_id[(int)$sec['id']] = $label;
}

// Build sector-id → AP-device status so customer markers can surface a
// "your sector AP is down" badge without the JS doing two lookups.
$device_status_by_id = [];
foreach ($devices as $d) {
    $device_status_by_id[(int)$d['id']] = $d['status'];
}
$sector_ap_status_by_id = [];
foreach ($sectors as $sec) {
    if ($sec['ap_device_id'] !== null && isset($device_status_by_id[(int)$sec['ap_device_id']])) {
        $sector_ap_status_by_id[(int)$sec['id']] = $device_status_by_id[(int)$sec['ap_device_id']];
    }
}

$map_data = [
    'csrf'       => csrf_token(),
    'center'     => [-26.7100, 27.8300], // Vaal Triangle default
    'zoom'       => 11,
    'sites'      => array_map(fn($s) => [
        'id' => $s['id'], 'name' => $s['name'], 'type' => $s['type'],
        'lat' => $s['lat'], 'lng' => $s['lng'],
        'coverage_radius_m' => $s['coverage_radius_m'],
        'notes' => $s['notes'],
    ], $sites),
    'site_links' => array_map(fn($l) => [
        'id' => $l['id'], 'from_site_id' => $l['from_site_id'], 'to_site_id' => $l['to_site_id'],
        'type' => $l['type'], 'label' => $l['label'],
        'capacity_mbps' => $l['capacity_mbps'], 'frequency' => $l['frequency'],
    ], $links),
    'clients'    => array_map(fn($c) => [
        'id'             => (int)$c['id'],
        'username'       => $c['username'],
        'name'           => $c['name'],
        'account_no'     => $c['account_no'] ?? null,
        'status'         => $c['status']     ?? 'active',
        'address'        => $c['address']    ?? '',
        'lat'            => $c['lat']        !== null ? (float)$c['lat'] : null,
        'lng'            => $c['lng']        !== null ? (float)$c['lng'] : null,
        'sector_id'      => !empty($c['sector_id']) ? (int)$c['sector_id'] : null,
        'sector_label'   => !empty($c['sector_id']) ? ($sector_label_by_id[(int)$c['sector_id']] ?? null) : null,
        'network_status' => !empty($c['sector_id']) ? ($sector_ap_status_by_id[(int)$c['sector_id']] ?? null) : null,
    ], $clients),
    'devices' => array_map($device_shape, $devices),
    'sectors' => array_map(fn($s) => $sector_shape($s, [
        'name' => $s['ap_device_name'] ?? null,
    ]), $sectors),
    'outages' => [
        'sector_ids'    => $outage_sector_ids,
        'tower_ids'     => $outage_tower_ids,
        'by_sector_id'  => $outage_by_sector,
        'active_count'  => count($active_outages),
    ],
];
?>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="anonymous">

<style>
  /* The map page goes edge-to-edge inside portal-main. portal.css gives
     portal-main 40px of padding and caps portal-inner at 960px wide;
     blow both out so the map can use the whole viewport next to the
     fixed sidebar. */
  body:has(.map-fs) .portal-main  { padding: 0 !important; overflow: hidden; }
  body:has(.map-fs) .portal-inner { max-width: none !important; width: 100%; }

  .map-fs {
    display: flex;
    flex-direction: column;
    height: 100vh;
  }

  .map-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    padding: 8px 14px;
    background: rgba(255,255,255,0.04);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
    font-size: 12px;
    color: var(--muted, #aaa);
  }
  .map-bar .group { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
  .map-bar .sep   { width:1px; height:22px; background:rgba(255,255,255,.1); }
  .map-bar h1     { font-size:14px; margin:0; color:var(--text,#fff); }
  .map-bar .btn   { font-size:12px; }
  .map-bar .inline-check { display:inline-flex; align-items:center; gap:4px; margin:0; }
  .map-counts    { display:flex; gap:10px; }
  .map-counts strong { color:var(--text,#fff); margin-left:3px; }
  .map-legend    { display:flex; flex-wrap:wrap; gap:8px; }
  .map-legend i  { display:inline-block; width:9px; height:9px; border-radius:50%;
                   margin-right:3px; vertical-align:middle; border:1.5px solid #fff; }
  .map-legend .pipe { border-left:1px solid #555; padding-left:8px; }

  #map { flex:1; min-height:300px; }

  /* Popup forms */
  form.map-popup       { display:flex; flex-direction:column; gap:6px; min-width:220px; }
  form.map-popup label { display:flex; flex-direction:column; gap:2px; font-size:12px; color:var(--muted, #aaa); }
  .map-popup input, .map-popup select { width:100%; padding:4px 6px; box-sizing:border-box; }
  .map-popup .row { display:flex; gap:6px; }
  .map-popup .row > * { flex:1; }
  .map-mode-active { box-shadow:0 0 0 2px var(--accent, #0cf) inset; }

  /* Inline-mode hint that appears under the toolbar while a draw mode is active. */
  .map-hint {
    display: none;
    padding: 6px 14px;
    background: rgba(5,218,253,0.10);
    border-bottom: 1px solid rgba(5,218,253,0.25);
    color: var(--accent, #05DAFD);
    font-size: 12px;
    flex-shrink: 0;
  }
  /* Sector cone interactivity — light hover lift so they feel clickable. */
  .leaflet-interactive:hover { filter: brightness(1.15); cursor: pointer; }
  /* Popup links inside our map popups — keep them subtle but legible. */
  .map-popup a[data-edit-device], .map-popup a[data-delete-device],
  .map-popup a[data-edit-sector], .map-popup a[data-delete-sector],
  .map-popup a[data-add-device],  .map-popup a[data-add-sector],
  .map-popup a[data-focus-sector] { text-decoration: none; }
  .map-popup a[data-edit-device]:hover, .map-popup a[data-edit-sector]:hover,
  .map-popup a[data-add-device]:hover,  .map-popup a[data-add-sector]:hover,
  .map-popup a[data-focus-sector]:hover { text-decoration: underline; }
</style>

<div class="map-fs">
  <div class="map-bar">
    <h1>Network map</h1>

    <div class="sep"></div>

    <div class="group">
      <button id="mode-pan"      class="btn btn-ghost btn-sm map-mode-active" data-mode="pan">Pan</button>
      <button id="mode-add-site" class="btn btn-ghost btn-sm" data-mode="add_site">+ Site</button>
      <button id="mode-add-link" class="btn btn-ghost btn-sm" data-mode="add_link">+ Link</button>
    </div>

    <div class="sep"></div>

    <div class="group">
      <label class="inline-check"><input type="checkbox" id="toggle-sites"    checked> Sites</label>
      <label class="inline-check"><input type="checkbox" id="toggle-links"    checked> Links</label>
      <label class="inline-check"><input type="checkbox" id="toggle-clients"  checked> Clients</label>
      <label class="inline-check"><input type="checkbox" id="toggle-sectors"  checked> Sectors</label>
      <label class="inline-check"><input type="checkbox" id="toggle-coverage">       Rings</label>
    </div>

    <div class="sep"></div>

    <div class="map-counts">
      <span>Sites <strong id="count-sites"><?= count($sites) ?></strong></span>
      <span>Links <strong id="count-links"><?= count($links) ?></strong></span>
      <span>Clients <strong id="count-clients"><?= count($clients) ?></strong></span>
      <span>Devices <strong id="count-devices"><?= count($devices) ?></strong></span>
      <span>Sectors <strong id="count-sectors"><?= count($sectors) ?></strong></span>
      <span>Unplaced <strong id="count-unplaced"><?= count(array_filter($clients, fn($c) => $c['lat'] === null || $c['lng'] === null)) ?></strong></span>
      <?php if (count($active_outages) > 0): ?>
        <span style="color:#d44;"><a href="/admin/outages.php" style="color:inherit;">Outages <strong><?= count($active_outages) ?></strong></a></span>
      <?php endif; ?>
    </div>

    <div class="sep"></div>

    <div class="group">
      <button id="geocode-all-btn" type="button" class="btn btn-ghost btn-sm">Geocode unplaced</button>
      <span id="geocode-status"></span>
    </div>

    <div class="map-legend" style="margin-left:auto;">
      <span><i style="background:#08e;"></i>tower</span>
      <span><i style="background:#0c8;"></i>AP</span>
      <span><i style="background:#f80;"></i>PTP</span>
      <span><i style="background:#80f;"></i>PoP</span>
      <span class="pipe"><i style="background:#0c8;"></i>active</span>
      <span><i style="background:#08e;"></i>lead</span>
      <span><i style="background:#fa0;"></i>suspended</span>
      <span><i style="background:#888;"></i>disconnected</span>
      <span class="pipe"><i style="background:#d44;width:6px;height:6px;"></i>AP down</span>
    </div>
  </div>

  <div id="map-hint" class="map-hint"></div>
  <div id="map"></div>
</div>

<script type="application/json" id="map-data"><?= json_encode($map_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"></script>
<script src="/assets/js/admin-map.js" defer></script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
