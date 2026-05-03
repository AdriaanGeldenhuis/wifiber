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

$is_ajax = !empty($_GET['ajax']);
$reply   = function (array $payload) use ($is_ajax) {
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
            }

            case 'move_site': {
                $id  = (int)($_POST['id'] ?? 0);
                $lat = (float)($_POST['lat'] ?? 0);
                $lng = (float)($_POST['lng'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No site id.']);
                site_move($id, $lat, $lng);
                $reply(['ok' => true]);
            }

            case 'delete_site': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No site id.']);
                site_delete($id);
                audit_log('site.delete', ['target_type' => 'site', 'target_id' => $id]);
                $reply(['ok' => true, 'message' => 'Site removed.']);
            }

            case 'add_link': {
                $id = site_link_save($_POST);
                audit_log('site_link.create', ['target_type' => 'site_link', 'target_id' => $id]);
                $reply(['ok' => true, 'id' => $id, 'message' => 'Link added.']);
            }

            case 'delete_link': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $reply(['ok' => false, 'error' => 'No link id.']);
                site_link_delete($id);
                audit_log('site_link.delete', ['target_type' => 'site_link', 'target_id' => $id]);
                $reply(['ok' => true, 'message' => 'Link removed.']);
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
        'id'         => (int)$c['id'],
        'username'   => $c['username'],
        'name'       => $c['name'],
        'account_no' => $c['account_no'] ?? null,
        'status'     => $c['status']     ?? 'active',
        'address'    => $c['address']    ?? '',
        'lat'        => $c['lat']        !== null ? (float)$c['lat'] : null,
        'lng'        => $c['lng']        !== null ? (float)$c['lng'] : null,
    ], $clients),
];
?>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="anonymous">

<style>
  .map-page { display:grid; grid-template-columns: 1fr 320px; gap:14px; align-items:stretch; }
  #map { height: 78vh; border-radius: 8px; }
  .map-side { display:flex; flex-direction:column; gap:14px; }
  .map-side .portal-card { padding:14px; }
  .map-toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
  .map-toolbar .btn { font-size:12px; }
  .map-legend { display:flex; flex-wrap:wrap; gap:10px; font-size:12px; color:var(--muted, #aaa); }
  .map-legend i { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px; vertical-align:middle; border:1.5px solid #fff; }
  .map-popup form { display:flex; flex-direction:column; gap:6px; min-width:220px; }
  .map-popup input, .map-popup select { width:100%; padding:4px 6px; }
  .map-popup .row { display:flex; gap:6px; }
  .map-popup .row > * { flex:1; }
  .map-mode-active { box-shadow:0 0 0 2px var(--accent, #0cf) inset; }
  @media (max-width: 900px) { .map-page { grid-template-columns: 1fr; } #map { height: 60vh; } }
</style>

<div class="portal-head">
  <h1>Network map</h1>
  <p class="portal-sub">
    Towers, PTP/PTMP links and clients on one map.
    Drag a marker to fix its position — saves automatically.
    Tile data: OpenStreetMap / Esri World Imagery.
  </p>
</div>

<div class="map-toolbar">
  <button id="mode-pan"        class="btn btn-ghost btn-sm map-mode-active" data-mode="pan">Pan / select</button>
  <button id="mode-add-site"   class="btn btn-ghost btn-sm" data-mode="add_site">+ Add site (click on map)</button>
  <button id="mode-add-link"   class="btn btn-ghost btn-sm" data-mode="add_link">+ Add link (click two sites)</button>
  <span class="map-legend" style="margin-left:auto;">
    <span><i style="background:#08e;"></i>tower</span>
    <span><i style="background:#0c8;"></i>AP</span>
    <span><i style="background:#f80;"></i>PTP</span>
    <span><i style="background:#80f;"></i>PoP</span>
    <span style="border-left:1px solid #555;padding-left:10px;"><i style="background:#0c8;"></i>active</span>
    <span><i style="background:#08e;"></i>lead</span>
    <span><i style="background:#fa0;"></i>suspended</span>
    <span><i style="background:#888;"></i>disconnected</span>
  </span>
</div>

<div class="map-page">
  <div id="map"></div>

  <aside class="map-side">
    <div class="portal-card">
      <h3 style="margin-top:0;">Layers</h3>
      <label class="inline-check"><input type="checkbox" id="toggle-sites"   checked> Sites</label><br>
      <label class="inline-check"><input type="checkbox" id="toggle-links"   checked> Links</label><br>
      <label class="inline-check"><input type="checkbox" id="toggle-clients" checked> Clients</label><br>
      <label class="inline-check"><input type="checkbox" id="toggle-coverage"> Coverage rings</label>
    </div>

    <div class="portal-card">
      <h3 style="margin-top:0;">Geocode unplaced clients</h3>
      <p class="muted small">Calls OpenStreetMap Nominatim for each client that has an address but no GPS. Slow (≤ 1/sec).</p>
      <button id="geocode-all-btn" type="button" class="btn btn-ghost btn-sm">Geocode all unplaced</button>
      <div id="geocode-status" class="muted small" style="margin-top:8px;"></div>
    </div>

    <div class="portal-card">
      <h3 style="margin-top:0;">Counts</h3>
      <ul class="kv">
        <li><span>Sites</span>   <strong id="count-sites"><?= count($sites) ?></strong></li>
        <li><span>Links</span>   <strong id="count-links"><?= count($links) ?></strong></li>
        <li><span>Clients</span> <strong id="count-clients"><?= count($clients) ?></strong></li>
        <li><span>Unplaced clients</span>
          <strong id="count-unplaced"><?= count(array_filter($clients, fn($c) => $c['lat'] === null || $c['lng'] === null)) ?></strong>
        </li>
      </ul>
    </div>
  </aside>
</div>

<script type="application/json" id="map-data"><?= json_encode($map_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"></script>
<script src="/assets/js/admin-map.js" defer></script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
