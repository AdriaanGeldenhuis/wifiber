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
require_once __DIR__ . '/../auth/wireless.php';

// Lightweight poll endpoint — JS calls this every ~30s to refresh
// device statuses and outage state without reloading the whole map.
if (($_GET['poll'] ?? '') === '1') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $devs = pdo()->query("SELECT id, status, last_seen_at FROM devices")->fetchAll();
    $devs_out = [];
    foreach ($devs as $d) {
        $devs_out[(int)$d['id']] = ['status' => $d['status'], 'last_seen_at' => $d['last_seen_at']];
    }
    $active = outages_all(['status' => 'active'], 500);
    $outage_ids = array_map(fn($o) => (int)$o['scope_id'], array_filter($active, fn($o) => $o['scope'] === 'sector' && $o['scope_id']));
    echo json_encode([
        'ok'              => true,
        'devices'         => $devs_out,
        'outage_sector_ids' => array_values(array_unique($outage_ids)),
        'outage_count'    => count($active),
        'ts'              => date('c'),
    ]);
    exit;
}

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
        'customer_count'    => isset($s['customer_count']) ? (int)$s['customer_count'] : null,
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
                if ($row) {
                    // sector_find() doesn't include customer_count, so back-fill
                    // it so the JS can refresh the capacity bar in place.
                    $cnt = pdo()->prepare("SELECT COUNT(*) FROM users WHERE role='client' AND sector_id = ?");
                    $cnt->execute([$newid]);
                    $row['customer_count'] = (int)$cnt->fetchColumn();
                }
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

// Per-site wireless link rollup so the inline JS at the bottom can draw
// a green/yellow/red ring on each AP site indicating worst link health.
$wl_rollup = pdo()->query(
    "SELECT d.site_id,
            COUNT(*)                                     AS link_count,
            MIN(wl.health_score)                         AS worst_health,
            SUM(wl.health_score IS NOT NULL AND wl.health_score < 50) AS degraded
       FROM wireless_links wl
       JOIN devices d ON d.id = wl.ap_device_id
      WHERE d.site_id IS NOT NULL
      GROUP BY d.site_id"
)->fetchAll();
$wl_by_site = [];
foreach ($wl_rollup as $r) {
    $wl_by_site[(int)$r['site_id']] = [
        'count'     => (int)$r['link_count'],
        'worst'     => $r['worst_health'] !== null ? (int)$r['worst_health'] : null,
        'degraded'  => (int)$r['degraded'],
    ];
}
$map_data['wireless_link_summary'] = $wl_by_site;
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
    background: var(--bg);
  }

  /* ---------- Toolbar ---------- */
  .map-bar {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    padding: 10px 18px;
    background: linear-gradient(180deg, rgba(5,218,253,0.04) 0%, var(--bg-elev) 100%);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    font-size: 12px;
    color: var(--text-dim);
  }
  .map-bar h1 {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: .04em;
    margin: 0;
    color: var(--text);
  }
  .map-bar .group { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .map-bar .sep {
    width: 1px;
    height: 22px;
    background: var(--border-strong);
    opacity: .6;
  }
  .map-bar .btn {
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 999px;
  }
  .map-bar .inline-check {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    padding: 4px 8px;
    border-radius: 999px;
    cursor: pointer;
    transition: background .15s, color .15s;
    color: var(--text-dim);
  }
  .map-bar .inline-check:hover { background: rgba(255,255,255,.04); color: var(--text); }
  .map-bar .inline-check input { accent-color: var(--accent); cursor: pointer; }
  .map-bar .inline-check:has(input:checked) { color: var(--text); }

  /* Mode buttons get a filled accent state when active. */
  .map-mode-active {
    background: var(--accent) !important;
    color: #001218 !important;
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 4px var(--accent-soft);
  }

  /* Counts as small rounded chips. */
  .map-counts {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }
  .map-counts > span {
    display: inline-flex;
    align-items: baseline;
    gap: 5px;
    padding: 3px 10px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 11px;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: .06em;
  }
  .map-counts strong {
    color: var(--accent);
    font-weight: 700;
    font-size: 12px;
    letter-spacing: 0;
  }
  .map-counts a { color: inherit; }

  .map-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    font-size: 11px;
    color: var(--text-muted);
  }
  .map-legend span { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
  .map-legend i {
    display: inline-block;
    width: 9px; height: 9px;
    border-radius: 50%;
    border: 1.5px solid var(--text);
  }
  .map-legend .pipe {
    border-left: 1px solid var(--border-strong);
    padding-left: 10px;
    margin-left: 2px;
  }

  #map { flex: 1; min-height: 300px; background: #0a0d12; }

  /* ---------- Mode hint ---------- */
  .map-hint {
    display: none;
    padding: 8px 18px;
    background: rgba(5,218,253,0.08);
    border-bottom: 1px solid rgba(5,218,253,0.25);
    color: var(--accent);
    font-size: 12px;
    font-weight: 500;
    letter-spacing: .02em;
    flex-shrink: 0;
    animation: map-hint-pulse 2.4s ease-in-out infinite;
  }
  .map-hint::before {
    content: '●';
    margin-right: 8px;
    font-size: 8px;
    vertical-align: middle;
  }
  @keyframes map-hint-pulse {
    0%, 100% { background: rgba(5,218,253,0.06); }
    50%      { background: rgba(5,218,253,0.14); }
  }

  /* ---------- Leaflet popup theming ---------- */
  /* Override Leaflet's default white popup so it matches the portal. */
  .leaflet-popup-content-wrapper {
    background: var(--bg-card);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 8px 24px rgba(0,0,0,.5), 0 0 0 1px rgba(5,218,253,.05);
    padding: 4px;
  }
  .leaflet-popup-content {
    margin: 12px 14px;
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 13px;
    line-height: 1.5;
    color: var(--text);
  }
  .leaflet-popup-tip {
    background: var(--bg-card);
    border: 1px solid var(--border);
    box-shadow: none;
  }
  .leaflet-popup-close-button {
    color: var(--text-muted) !important;
    font-size: 22px !important;
    padding: 6px 10px 0 0 !important;
    transition: color .15s;
  }
  .leaflet-popup-close-button:hover { color: var(--accent) !important; }

  /* ---------- Popup typography ---------- */
  .map-popup strong {
    color: var(--text);
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 14px;
    letter-spacing: -.005em;
  }
  .map-popup small { color: var(--text-muted); font-size: 11px; }
  .map-popup .muted { color: var(--text-muted); }
  .map-popup p { margin: 6px 0 0; color: var(--text-dim); font-size: 12px; }

  /* ---------- Popup forms ---------- */
  form.map-popup {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 240px;
  }
  form.map-popup label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
  }
  .map-popup input,
  .map-popup select,
  .map-popup textarea {
    width: 100%;
    padding: 7px 10px;
    box-sizing: border-box;
    background: var(--bg-elev);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    transition: border-color .15s, box-shadow .15s;
  }
  .map-popup input:focus,
  .map-popup select:focus,
  .map-popup textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }
  .map-popup select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'><path fill='none' stroke='%23a5b0bd' stroke-width='1.5' d='M2 4l4 4 4-4'/></svg>");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 28px;
  }
  .map-popup .row { display: flex; gap: 8px; }
  .map-popup .row > * { flex: 1; min-width: 0; }
  .map-popup form.map-popup > button[type="submit"] {
    margin-top: 4px;
    padding: 9px 16px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .03em;
  }

  /* ---------- Popup data list (devices / sectors at a tower) ---------- */
  .map-popup .pp-section {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
  }
  .map-popup .pp-section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
  }
  .map-popup .pp-section-head strong {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--text-muted);
    font-weight: 600;
  }
  .map-popup .pp-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .map-popup .pp-list li {
    padding: 6px 8px;
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 12px;
    transition: background .15s, border-color .15s;
  }
  .map-popup .pp-list li:hover { background: rgba(5,218,253,.05); border-color: rgba(5,218,253,.2); }
  .map-popup .pp-list .pp-row {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .map-popup .pp-list .pp-name { color: var(--text); font-weight: 500; flex: 1; min-width: 0; }
  .map-popup .pp-list .pp-name a { color: inherit; }
  .map-popup .pp-list .pp-meta { font-size: 10.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.4; }

  /* Status pills inside popup lists. */
  .map-popup .pp-pill {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #fff;
  }

  /* Inline action buttons (edit / delete / add) — small pills. */
  .map-popup .pp-act {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 2px 9px;
    border-radius: 999px;
    border: 1px solid var(--border-strong);
    background: transparent;
    font-size: 10.5px;
    font-weight: 500;
    color: var(--text-dim);
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, color .15s, border-color .15s;
    white-space: nowrap;
  }
  .map-popup .pp-act:hover { background: var(--accent-soft); color: var(--accent); border-color: var(--accent); }
  .map-popup .pp-act.pp-act-danger { color: #e88; }
  .map-popup .pp-act.pp-act-danger:hover { background: rgba(255,84,112,.12); color: var(--danger); border-color: rgba(255,84,112,.4); }
  .map-popup .pp-act.pp-act-primary {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: rgba(5,218,253,.35);
  }
  .map-popup .pp-act.pp-act-primary:hover { background: var(--accent); color: #001218; border-color: var(--accent); }

  /* Sector / device dot prefix in lists. */
  .map-popup .pp-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  /* Tower popup top action row (Edit / Delete / + Sector). */
  .map-popup .pp-actions {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
  }
  .map-popup .pp-actions .btn { font-size: 11px; padding: 6px 12px; }

  /* Outage banner inside sector popup. */
  .map-popup .pp-outage {
    margin: 8px 0;
    padding: 8px 10px;
    background: rgba(220,68,68,.12);
    border-left: 3px solid var(--danger);
    border-radius: var(--radius-sm);
    font-size: 11px;
    line-height: 1.45;
  }
  .map-popup .pp-outage strong { color: var(--danger); font-size: 12px; }

  /* Key-value lines in sector / device popup details. */
  .map-popup .pp-kv {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 12px;
    margin-top: 8px;
    font-size: 12px;
  }
  .map-popup .pp-kv dt { color: var(--text-muted); }
  .map-popup .pp-kv dd { margin: 0; color: var(--text); }

  /* ---------- Sector preview cone label ---------- */
  .wf-sector-label-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--accent);
    color: #001218;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .04em;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(5,218,253,.4);
    font-family: 'Space Grotesk', 'Inter', sans-serif;
  }
  .wf-sector-label-pill::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #001218;
  }

  /* ---------- Sector cone + marker hover ---------- */
  .leaflet-interactive { transition: filter .12s; }
  path.leaflet-interactive:hover { filter: brightness(1.25) drop-shadow(0 0 6px rgba(5,218,253,.5)); cursor: pointer; }
  .wf-marker { transition: transform .15s; }
  .wf-marker:hover { transform: scale(1.18); cursor: pointer; }

  /* ---------- Geocode status text ---------- */
  #geocode-status {
    font-size: 11px;
    color: var(--text-muted);
    font-style: italic;
  }

  /* ---------- Layer-control widget restyle ---------- */
  .leaflet-control-layers {
    background: var(--bg-card) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-sm) !important;
    color: var(--text) !important;
    box-shadow: 0 4px 12px rgba(0,0,0,.4) !important;
  }
  .leaflet-control-layers-expanded { padding: 8px 12px !important; }
  .leaflet-control-layers label { color: var(--text-dim); font-size: 12px; padding: 2px 0; }
  .leaflet-control-layers-separator { border-top-color: var(--border) !important; }

  /* ---------- Zoom buttons ---------- */
  .leaflet-bar a {
    background: var(--bg-card) !important;
    color: var(--text) !important;
    border-color: var(--border) !important;
    transition: background .15s, color .15s;
  }
  .leaflet-bar a:hover { background: var(--accent-soft) !important; color: var(--accent) !important; }

  /* ---------- Mobile / narrow toolbar ---------- */
  @media (max-width: 900px) {
    .map-bar { padding: 8px 12px; gap: 10px; }
    .map-legend { display: none; }
    .map-counts { font-size: 10px; }
  }
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

<script>
/* Wireless link health overlay — coloured ring on each site that hosts
   an AP with active wireless_links. Layered onto the same Leaflet
   instance admin-map.js creates (exposed as window.WIFIBER_MAP). */
(function () {
  function init() {
    if (!window.WIFIBER_MAP || typeof L === 'undefined') {
      return setTimeout(init, 250);
    }
    const dataEl = document.getElementById('map-data');
    if (!dataEl) return;
    let boot;
    try { boot = JSON.parse(dataEl.textContent); } catch (e) { return; }
    if (!boot || !boot.sites || !boot.wireless_link_summary) return;

    const map = window.WIFIBER_MAP;
    const layer = L.layerGroup().addTo(map);
    const siteById = {};
    boot.sites.forEach(function (s) { siteById[s.id] = s; });

    function colourFor(worst) {
      if (worst === null || worst === undefined) return '#888';
      if (worst >= 75) return '#0c8';
      if (worst >= 50) return '#e8a814';
      return '#d44';
    }

    Object.entries(boot.wireless_link_summary).forEach(function (entry) {
      const siteId = entry[0], sum = entry[1];
      const s = siteById[siteId];
      if (!s || s.lat === null || s.lng === null) return;
      const ring = L.circleMarker([s.lat, s.lng], {
        radius: 14,
        color: colourFor(sum.worst),
        weight: 3,
        fill: false,
        opacity: 0.85,
      }).addTo(layer);
      ring.bindTooltip(
        '<strong>' + s.name + '</strong><br>' +
        sum.count + ' wireless link' + (sum.count === 1 ? '' : 's') +
        (sum.degraded > 0 ? ' · <span style="color:#d44">' + sum.degraded + ' degraded</span>' : '') +
        (sum.worst !== null ? ' · worst health ' + sum.worst : ''),
        { sticky: true, direction: 'top' }
      );
      ring.on('click', function () {
        window.location = '/admin/links.php';
      });
    });
  }
  init();
})();
</script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
