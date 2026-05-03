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
require_once __DIR__ . '/../auth/uisp_sync.php';

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

            case 'uisp_sync': {
                $r = uisp_sync_all();
                $reply([
                    'ok'      => !empty($r['ok']),
                    'message' => !empty($r['ok']) ? 'Sync OK.' : 'Sync failed: ' . implode(' | ', $r['errors'] ?? []),
                    'counts'  => $r['counts'] ?? null,
                    'errors'  => $r['errors'] ?? null,
                ]);
                break;
            }

            case 'link_site': {
                $site_id = (int)($_POST['site_id'] ?? 0);
                $uisp_id = trim((string)($_POST['uisp_id'] ?? ''));
                if ($site_id <= 0 || $uisp_id === '') {
                    $reply(['ok' => false, 'error' => 'Pick a site and a UISP id.']);
                }
                pdo()->prepare("UPDATE sites SET uisp_id = ? WHERE id = ?")->execute([$uisp_id, $site_id]);
                audit_log('uisp.link_site', [
                    'target_type' => 'site', 'target_id' => $site_id,
                    'meta' => ['uisp_id' => $uisp_id],
                ]);
                $reply(['ok' => true, 'message' => 'Linked to UISP.']);
                break;
            }

            case 'unlink_site': {
                $site_id = (int)($_POST['site_id'] ?? 0);
                if ($site_id <= 0) $reply(['ok' => false, 'error' => 'No site id.']);
                pdo()->prepare("UPDATE sites SET uisp_id = NULL WHERE id = ?")->execute([$site_id]);
                audit_log('uisp.unlink_site', ['target_type' => 'site', 'target_id' => $site_id]);
                $reply(['ok' => true, 'message' => 'Unlinked.']);
                break;
            }

            case 'link_client': {
                $user_id = (int)($_POST['user_id'] ?? 0);
                $uisp_id = trim((string)($_POST['uisp_id'] ?? ''));
                if ($user_id <= 0 || $uisp_id === '') {
                    $reply(['ok' => false, 'error' => 'Pick a client and a UISP id.']);
                }
                pdo()->prepare("UPDATE users SET uisp_client_id = ? WHERE id = ?")->execute([$uisp_id, $user_id]);
                audit_log('uisp.link_client', [
                    'target_type' => 'user', 'target_id' => $user_id,
                    'meta' => ['uisp_client_id' => $uisp_id],
                ]);
                $reply(['ok' => true, 'message' => 'Linked to UISP.']);
                break;
            }

            case 'unlink_client': {
                $user_id = (int)($_POST['user_id'] ?? 0);
                if ($user_id <= 0) $reply(['ok' => false, 'error' => 'No user id.']);
                pdo()->prepare("UPDATE users SET uisp_client_id = NULL WHERE id = ?")->execute([$user_id]);
                audit_log('uisp.unlink_client', ['target_type' => 'user', 'target_id' => $user_id]);
                $reply(['ok' => true, 'message' => 'Unlinked.']);
                break;
            }

            default:
                $reply(['ok' => false, 'error' => 'Unknown action.']);
        }
    } catch (Throwable $e) {
        $reply(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// Auto-sync on page load if cache is older than the configured interval.
// uisp_sync_all() is itself rate-limited (60s lock), so concurrent loads
// don't pile up. Failures are non-fatal — we just flag $uisp_offline so
// the map renders the cached snapshot with a banner.
$uisp_offline = false;
if (uisp_should_auto_sync()) {
    try {
        $r = uisp_sync_all();
        if (empty($r['ok'])) $uisp_offline = true;
    } catch (Throwable $e) {
        error_log('UISP auto-sync threw: ' . $e->getMessage());
        $uisp_offline = true;
    }
}

$sites    = sites_all(false);
$links    = site_links_all();
$clients  = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client'));

$uisp_cfg     = uisp_config();
$uisp_enabled = uisp_is_configured();
$uisp_sites_c   = $uisp_enabled ? uisp_sites_cached()      : [];
$uisp_devices_c = $uisp_enabled ? uisp_devices_cached()    : [];
$uisp_links_c   = $uisp_enabled ? uisp_data_links_cached() : [];
$uisp_clients_c = $uisp_enabled ? uisp_clients_cached()    : [];

$map_data = [
    'csrf'       => csrf_token(),
    'center'     => [-26.7100, 27.8300], // Vaal Triangle default
    'zoom'       => 11,
    'sites'      => array_map(fn($s) => [
        'id' => $s['id'], 'name' => $s['name'], 'type' => $s['type'],
        'lat' => $s['lat'], 'lng' => $s['lng'],
        'coverage_radius_m' => $s['coverage_radius_m'],
        'notes' => $s['notes'],
        'uisp_id' => $s['uisp_id'] ?? null,
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
        'uisp_client_id' => $c['uisp_client_id'] ?? null,
    ], $clients),
    'uisp' => [
        'enabled'      => $uisp_enabled,
        'offline'      => $uisp_offline,
        'last_sync_at' => $uisp_cfg['last_sync_at'] ?? null,
        'sites' => array_map(fn($s) => [
            'uisp_id' => $s['uisp_id'],
            'name'    => $s['name'],
            'address' => $s['address'],
            'lat'     => $s['lat'] !== null ? (float)$s['lat'] : null,
            'lng'     => $s['lng'] !== null ? (float)$s['lng'] : null,
            'status'  => $s['status'],
            'is_stale'=> (int)$s['is_stale'],
        ], $uisp_sites_c),
        'devices' => array_map(fn($d) => [
            'uisp_id'      => $d['uisp_id'],
            'uisp_site_id' => $d['uisp_site_id'],
            'name'         => $d['name'],
            'type'         => $d['type'],
            'model'        => $d['model'],
            'mac'          => $d['mac'],
            'ip'           => $d['ip'],
            'role'         => $d['role'],
            'status'       => $d['status'],
            'signal_dbm'   => $d['signal_dbm'] !== null ? (int)$d['signal_dbm'] : null,
            'lat'          => $d['lat'] !== null ? (float)$d['lat'] : null,
            'lng'          => $d['lng'] !== null ? (float)$d['lng'] : null,
            'last_seen_at' => $d['last_seen_at'],
            'is_stale'     => (int)$d['is_stale'],
        ], $uisp_devices_c),
        'data_links' => array_map(fn($l) => [
            'uisp_id'             => $l['uisp_id'],
            'from_device_uisp_id' => $l['from_device_uisp_id'],
            'to_device_uisp_id'   => $l['to_device_uisp_id'],
            'frequency'           => $l['frequency'],
            'capacity_mbps'       => $l['capacity_mbps'] !== null ? (float)$l['capacity_mbps'] : null,
            'status'              => $l['status'],
            'is_stale'            => (int)$l['is_stale'],
        ], $uisp_links_c),
        'clients' => array_map(fn($c) => [
            'uisp_id'      => $c['uisp_id'],
            'name'         => $c['name'],
            'email'        => $c['email'],
            'account_no'   => $c['account_no'],
            'address_full' => $c['address_full'],
            'lat'          => $c['lat'] !== null ? (float)$c['lat'] : null,
            'lng'          => $c['lng'] !== null ? (float)$c['lng'] : null,
            'status'       => $c['status'],
            'is_stale'     => (int)$c['is_stale'],
        ], $uisp_clients_c),
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

  /* UISP banner / status pill */
  .map-uisp-banner {
    padding: 6px 14px;
    background: rgba(255, 80, 80, 0.18);
    border-bottom: 1px solid rgba(255, 80, 80, 0.4);
    color: #fff; font-size: 12px;
    flex-shrink: 0;
  }
  .map-uisp-pill {
    display:inline-flex; align-items:center; gap:5px;
    padding:1px 6px; border-radius:8px; font-size:10px;
    background: rgba(255,255,255,0.08); color: var(--text,#fff);
  }
  .map-uisp-pill.online    { background: rgba(0,200,128,0.25); }
  .map-uisp-pill.offline   { background: rgba(220,80,80,0.30); }
  .map-uisp-pill.unknown   { background: rgba(180,180,180,0.30); }
  .map-uisp-pill.stale     { background: rgba(255,180,80,0.30); }
</style>

<div class="map-fs">
  <?php if ($uisp_enabled && $uisp_offline): ?>
    <div class="map-uisp-banner" id="uisp-offline-banner">
      UISP is unreachable — showing the last cached snapshot.
      <a href="/admin/uisp.php" style="color:#fff;text-decoration:underline;">Check connection</a>
    </div>
  <?php endif; ?>
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
      <label class="inline-check"><input type="checkbox" id="toggle-coverage">       Rings</label>
    </div>

    <?php if ($uisp_enabled): ?>
      <div class="sep"></div>
      <div class="group" title="UISP overlay layers">
        <label class="inline-check"><input type="checkbox" id="toggle-uisp-sites"   checked> UISP sites</label>
        <label class="inline-check"><input type="checkbox" id="toggle-uisp-devices" checked> Devices</label>
        <label class="inline-check"><input type="checkbox" id="toggle-uisp-links"   checked> UISP links</label>
        <label class="inline-check"><input type="checkbox" id="toggle-uisp-clients" checked> UISP clients</label>
      </div>
    <?php endif; ?>

    <div class="sep"></div>

    <div class="map-counts">
      <span>Sites <strong id="count-sites"><?= count($sites) ?></strong></span>
      <span>Links <strong id="count-links"><?= count($links) ?></strong></span>
      <span>Clients <strong id="count-clients"><?= count($clients) ?></strong></span>
      <span>Unplaced <strong id="count-unplaced"><?= count(array_filter($clients, fn($c) => $c['lat'] === null || $c['lng'] === null)) ?></strong></span>
      <?php if ($uisp_enabled): ?>
        <span>UISP devices <strong id="count-uisp-devices"><?= count($uisp_devices_c) ?></strong></span>
      <?php endif; ?>
    </div>

    <div class="sep"></div>

    <div class="group">
      <button id="geocode-all-btn" type="button" class="btn btn-ghost btn-sm">Geocode unplaced</button>
      <span id="geocode-status"></span>
      <?php if ($uisp_enabled): ?>
        <button id="uisp-sync-btn" type="button" class="btn btn-ghost btn-sm" title="Refresh UISP cache">Sync UISP</button>
        <span id="uisp-sync-status" class="muted"
              <?php if (!empty($uisp_cfg['last_sync_at'])): ?>title="Last sync: <?= htmlspecialchars((string)$uisp_cfg['last_sync_at']) ?>"<?php endif; ?>></span>
      <?php else: ?>
        <a href="/admin/uisp.php" class="btn btn-ghost btn-sm">Connect UISP</a>
      <?php endif; ?>
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
    </div>
  </div>

  <div id="map"></div>
</div>

<script type="application/json" id="map-data"><?= json_encode($map_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"></script>
<script src="/assets/js/admin-map.js" defer></script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
