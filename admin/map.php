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

// Coverage heatmap endpoint — returns a GeoJSON grid for one sector.
if (!empty($_GET['coverage_for'])) {
    require_once __DIR__ . '/../auth/coverage_rf.php';
    $sid = (int)$_GET['coverage_for'];
    $sec = pdo()->prepare("SELECT * FROM sectors WHERE id = ?");
    $sec->execute([$sid]);
    $sec = $sec->fetch();
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    if (!$sec) { echo json_encode(['ok' => false, 'error' => 'sector not found']); exit; }
    $tower = site_find((int)$sec['tower_id']);
    if (!$tower) { echo json_encode(['ok' => false, 'error' => 'tower missing']); exit; }
    $grid = rssi_grid_for_sector($sec, $tower, 40);
    echo json_encode(['ok' => true, 'sector' => ['id' => $sid, 'name' => $sec['name']],
                      'grid' => $grid]);
    exit;
}

// Map overlays — JSON feeds for the optional layers (live signal, RF
// density, capacity, outage-history heatmap, throughput contours). Each
// returns a small JSON payload the client can drop straight onto Leaflet
// without further processing.
if (!empty($_GET['overlay'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $kind = (string)$_GET['overlay'];
    try {
        switch ($kind) {

            // Per-client signal — last known signal_dbm / snr_db on each
            // client's wireless_link, joined to the client's lat/lng.
            // Used to render small SNR-coloured dots on top of the
            // existing client markers (so colour-blind admins still see
            // the billing colour, layered with a network-quality halo).
            case 'client_signal': {
                $rows = pdo()->query(
                    "SELECT u.id           AS client_id,
                            u.username,
                            u.account_no,
                            u.lat, u.lng,
                            wl.signal_dbm, wl.snr_db, wl.ccq_pct,
                            wl.health_score,
                            wl.last_evaluated_at
                       FROM users u
                       JOIN wireless_links wl ON wl.customer_id = u.id
                      WHERE u.role = 'client'
                        AND u.lat IS NOT NULL AND u.lng IS NOT NULL
                        AND wl.signal_dbm IS NOT NULL"
                )->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'client_id' => (int)$r['client_id'],
                        'username'  => $r['username'],
                        'account_no'=> $r['account_no'],
                        'lat'       => (float)$r['lat'],
                        'lng'       => (float)$r['lng'],
                        'signal_dbm'=> (int)$r['signal_dbm'],
                        'snr_db'    => $r['snr_db'] !== null ? (int)$r['snr_db'] : null,
                        'ccq_pct'   => $r['ccq_pct'] !== null ? (float)$r['ccq_pct'] : null,
                        'health'    => $r['health_score'] !== null ? (int)$r['health_score'] : null,
                        'last_at'   => $r['last_evaluated_at'],
                    ];
                }
                echo json_encode(['ok' => true, 'points' => $out]);
                exit;
            }

            // RF interference density — for every AP device on a site,
            // average RSSI seen during passive scans over the last 24 h.
            // The map uses this as a noisy/quiet halo around each AP.
            case 'rf_density': {
                $rows = pdo()->query(
                    "SELECT d.id AS device_id, d.name AS device_name, d.site_id,
                            s.lat, s.lng, s.name AS site_name,
                            AVG(rfe.rssi_dbm) AS avg_rssi,
                            MAX(rfe.rssi_dbm) AS peak_rssi,
                            COUNT(rfe.id)     AS sample_count,
                            MAX(rfe.polled_at) AS last_scan_at
                       FROM devices d
                       JOIN sites s   ON s.id = d.site_id
                  LEFT JOIN rf_environment_samples rfe
                            ON rfe.device_id = d.id
                           AND rfe.polled_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      WHERE d.role = 'ap'
                        AND d.site_id IS NOT NULL
                      GROUP BY d.id"
                )->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    if ($r['sample_count'] === null || (int)$r['sample_count'] === 0) continue;
                    $out[] = [
                        'device_id'   => (int)$r['device_id'],
                        'device_name' => $r['device_name'],
                        'site_id'     => (int)$r['site_id'],
                        'site_name'   => $r['site_name'],
                        'lat'         => (float)$r['lat'],
                        'lng'         => (float)$r['lng'],
                        'avg_rssi'    => round((float)$r['avg_rssi'], 1),
                        'peak_rssi'   => (int)$r['peak_rssi'],
                        'samples'     => (int)$r['sample_count'],
                        'last_scan_at'=> $r['last_scan_at'],
                    ];
                }
                echo json_encode(['ok' => true, 'points' => $out]);
                exit;
            }

            // Outage history heatmap — count + total downtime minutes per
            // tower over the requested window (30 or 90 days). Tied back
            // to the tower's lat/lng so the map can render hot-spots
            // separate from the live red-halo layer.
            case 'outage_history': {
                $days = (int)($_GET['days'] ?? 30);
                if (!in_array($days, [30, 90], true)) $days = 30;
                $stmt = pdo()->prepare(
                    "SELECT s.id  AS site_id, s.name AS site_name,
                            s.lat, s.lng,
                            COUNT(o.id)                                                   AS event_count,
                            SUM(TIMESTAMPDIFF(MINUTE, o.started_at,
                                              COALESCE(o.resolved_at, NOW())))            AS down_minutes,
                            MAX(o.started_at)                                             AS last_started
                       FROM sites s
                       JOIN outages o
                            ON ((o.scope = 'tower'  AND o.scope_id = s.id)
                             OR (o.scope = 'sector' AND o.scope_id IN
                                  (SELECT id FROM sectors WHERE tower_id = s.id)))
                      WHERE o.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                      GROUP BY s.id"
                );
                $stmt->execute([$days]);
                $rows = $stmt->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'site_id'      => (int)$r['site_id'],
                        'site_name'    => $r['site_name'],
                        'lat'          => (float)$r['lat'],
                        'lng'          => (float)$r['lng'],
                        'event_count'  => (int)$r['event_count'],
                        'down_minutes' => (int)$r['down_minutes'],
                        'last_started' => $r['last_started'],
                    ];
                }
                echo json_encode(['ok' => true, 'days' => $days, 'points' => $out]);
                exit;
            }

            // Throughput contours — sum local+remote throughput across
            // all wireless_links on each sector, averaged over the last
            // hour. Indexed by sector_id so the JS can find the cone
            // and decorate it.
            case 'throughput': {
                $rows = pdo()->query(
                    "SELECT sec.id AS sector_id, sec.name, sec.tower_id,
                            t.lat, t.lng, sec.azimuth_deg, sec.beamwidth_deg,
                            AVG(COALESCE(lhs.throughput_local_mbps, 0)
                              + COALESCE(lhs.throughput_remote_mbps, 0))   AS avg_mbps,
                            MAX(COALESCE(lhs.throughput_local_mbps, 0)
                              + COALESCE(lhs.throughput_remote_mbps, 0))   AS peak_mbps,
                            COUNT(DISTINCT wl.id) AS link_count,
                            COUNT(lhs.id)         AS sample_count
                       FROM sectors sec
                       JOIN sites   t   ON t.id = sec.tower_id
                  LEFT JOIN wireless_links wl  ON wl.sector_id = sec.id
                  LEFT JOIN link_health_samples lhs
                            ON lhs.link_id = wl.id
                           AND lhs.polled_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                      GROUP BY sec.id
                     HAVING sample_count > 0"
                )->fetchAll();
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'sector_id'  => (int)$r['sector_id'],
                        'sector_name'=> $r['name'],
                        'tower_id'   => (int)$r['tower_id'],
                        'lat'        => (float)$r['lat'],
                        'lng'        => (float)$r['lng'],
                        'azimuth'    => $r['azimuth_deg']   !== null ? (int)$r['azimuth_deg']   : null,
                        'beamwidth'  => $r['beamwidth_deg'] !== null ? (int)$r['beamwidth_deg'] : null,
                        'avg_mbps'   => round((float)$r['avg_mbps'], 1),
                        'peak_mbps'  => round((float)$r['peak_mbps'], 1),
                        'link_count' => (int)$r['link_count'],
                        'samples'    => (int)$r['sample_count'],
                    ];
                }
                echo json_encode(['ok' => true, 'points' => $out]);
                exit;
            }

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown overlay']);
                exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Rich detail endpoint — the bottom panel calls this on click for
// sectors, clients, links and sites. We return everything the panel
// needs to render: live wireless_link signal/SNR/CCQ/health, sector
// throughput rollups, AP device status, customer counts, distances,
// active outages, etc.  Kept here (rather than on /admin/links.php
// or per-entity pages) so the map UI doesn't fan out to 4 endpoints.
if (!empty($_GET['detail'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $kind = (string)$_GET['detail'];
    $id   = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'No id']); exit; }

    try {
        switch ($kind) {

            // Backbone link — the polyline drawn between two sites. We
            // also surface any wireless_links we can find between
            // devices on those two sites (because site_links is just
            // an admin-tagged backbone label; the radio truth lives
            // in wireless_links keyed by ap/cpe device pairs).
            case 'link': {
                $stmt = pdo()->prepare(
                    "SELECT sl.*,
                            fs.name AS from_name, fs.type AS from_type, fs.lat AS from_lat, fs.lng AS from_lng,
                            ts.name AS to_name,   ts.type AS to_type,   ts.lat AS to_lat,   ts.lng AS to_lng
                       FROM site_links sl
                       JOIN sites fs ON fs.id = sl.from_site_id
                       JOIN sites ts ON ts.id = sl.to_site_id
                      WHERE sl.id = ? LIMIT 1"
                );
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) { echo json_encode(['ok' => false, 'error' => 'Link not found']); exit; }

                $dist_km = haversine_km(
                    (float)$row['from_lat'], (float)$row['from_lng'],
                    (float)$row['to_lat'],   (float)$row['to_lng']
                );

                // Best-effort match on wireless_links between these two sites.
                $wstmt = pdo()->prepare(
                    "SELECT wl.id, wl.signal_dbm, wl.signal_dbm_remote,
                            wl.snr_db, wl.snr_db_remote, wl.ccq_pct,
                            wl.tx_rate_mbps, wl.rx_rate_mbps,
                            wl.throughput_local_mbps, wl.throughput_remote_mbps,
                            wl.capacity_local_mbps, wl.capacity_remote_mbps,
                            wl.frequency_mhz, wl.channel_width_mhz,
                            wl.tx_power_dbm_local, wl.tx_power_dbm_remote,
                            wl.distance_km, wl.health_score, wl.last_evaluated_at,
                            wl.modulation, wl.wireless_mode, wl.ssid,
                            ap.name AS ap_name, cpe.name AS cpe_name,
                            ap.site_id AS ap_site, cpe.site_id AS cpe_site
                       FROM wireless_links wl
                       JOIN devices ap       ON ap.id = wl.ap_device_id
                       LEFT JOIN devices cpe ON cpe.id = wl.cpe_device_id
                      WHERE (ap.site_id = ? AND cpe.site_id = ?)
                         OR (ap.site_id = ? AND cpe.site_id = ?)
                      ORDER BY wl.health_score IS NULL, wl.health_score DESC
                      LIMIT 1"
                );
                $wstmt->execute([
                    (int)$row['from_site_id'], (int)$row['to_site_id'],
                    (int)$row['to_site_id'],   (int)$row['from_site_id'],
                ]);
                $wl = $wstmt->fetch() ?: null;

                $devCounts = pdo()->prepare(
                    "SELECT site_id, COUNT(*) AS n,
                            SUM(status='online') AS online
                       FROM devices WHERE site_id IN (?, ?) GROUP BY site_id"
                );
                $devCounts->execute([(int)$row['from_site_id'], (int)$row['to_site_id']]);
                $byd = [];
                foreach ($devCounts->fetchAll() as $r) {
                    $byd[(int)$r['site_id']] = ['n' => (int)$r['n'], 'online' => (int)$r['online']];
                }

                echo json_encode([
                    'ok'             => true,
                    'kind'           => 'link',
                    'link'           => [
                        'id' => (int)$row['id'], 'type' => $row['type'], 'label' => $row['label'],
                        'capacity_mbps' => $row['capacity_mbps'] !== null ? (float)$row['capacity_mbps'] : null,
                        'frequency'     => $row['frequency'],
                    ],
                    'from'           => [
                        'id' => (int)$row['from_site_id'], 'name' => $row['from_name'],
                        'type' => $row['from_type'], 'lat' => (float)$row['from_lat'], 'lng' => (float)$row['from_lng'],
                        'devices' => $byd[(int)$row['from_site_id']] ?? ['n' => 0, 'online' => 0],
                    ],
                    'to'             => [
                        'id' => (int)$row['to_site_id'], 'name' => $row['to_name'],
                        'type' => $row['to_type'], 'lat' => (float)$row['to_lat'], 'lng' => (float)$row['to_lng'],
                        'devices' => $byd[(int)$row['to_site_id']] ?? ['n' => 0, 'online' => 0],
                    ],
                    'distance_km'    => round($dist_km, 3),
                    'wireless_link'  => $wl,
                ]);
                exit;
            }

            // Sector — radio config + customer rollup + active outage
            // + last-hour throughput aggregate. Anchored on the existing
            // sectors row so reads are cheap.
            case 'sector': {
                $sec = pdo()->prepare("SELECT * FROM sectors WHERE id = ? LIMIT 1");
                $sec->execute([$id]);
                $s = $sec->fetch();
                if (!$s) { echo json_encode(['ok' => false, 'error' => 'Sector not found']); exit; }
                $tower = site_find((int)$s['tower_id']);
                $ap    = !empty($s['ap_device_id']) ? device_find((int)$s['ap_device_id']) : null;

                $cnt = pdo()->prepare("SELECT COUNT(*) FROM users WHERE role='client' AND sector_id = ?");
                $cnt->execute([$id]);
                $customer_count = (int)$cnt->fetchColumn();

                // wireless_links rollup against this sector
                $stats = pdo()->prepare(
                    "SELECT COUNT(*)            AS link_count,
                            AVG(signal_dbm)     AS avg_signal,
                            AVG(snr_db)         AS avg_snr,
                            AVG(ccq_pct)        AS avg_ccq,
                            AVG(health_score)   AS avg_health,
                            MIN(health_score)   AS worst_health,
                            SUM(throughput_local_mbps + COALESCE(throughput_remote_mbps,0)) AS total_thr,
                            MAX(last_evaluated_at) AS last_seen_at
                       FROM wireless_links WHERE sector_id = ?"
                );
                $stats->execute([$id]);
                $st = $stats->fetch() ?: [];

                // Active outage on this sector (if any)
                $oa = pdo()->prepare(
                    "SELECT id, started_at, cause, affected_count
                       FROM outages WHERE scope='sector' AND scope_id=? AND status='active'
                       ORDER BY started_at DESC LIMIT 1"
                );
                $oa->execute([$id]);
                $outage = $oa->fetch() ?: null;

                // Last 1h throughput peak via link_health_samples
                $lh = pdo()->prepare(
                    "SELECT MAX(COALESCE(lhs.throughput_local_mbps,0) + COALESCE(lhs.throughput_remote_mbps,0)) AS peak,
                            AVG(COALESCE(lhs.throughput_local_mbps,0) + COALESCE(lhs.throughput_remote_mbps,0)) AS avg_thr
                       FROM link_health_samples lhs
                       JOIN wireless_links wl ON wl.id = lhs.link_id
                      WHERE wl.sector_id = ?
                        AND lhs.polled_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
                );
                $lh->execute([$id]);
                $thr_row = $lh->fetch() ?: ['peak' => null, 'avg_thr' => null];

                echo json_encode([
                    'ok'      => true,
                    'kind'    => 'sector',
                    'sector'  => [
                        'id' => (int)$s['id'], 'name' => $s['name'],
                        'tower_id' => (int)$s['tower_id'],
                        'azimuth_deg' => $s['azimuth_deg'] !== null ? (int)$s['azimuth_deg'] : null,
                        'beamwidth_deg' => $s['beamwidth_deg'] !== null ? (int)$s['beamwidth_deg'] : null,
                        'band' => $s['band'],
                        'frequency_mhz' => $s['frequency_mhz'] !== null ? (int)$s['frequency_mhz'] : null,
                        'channel_width_mhz' => $s['channel_width_mhz'] !== null ? (int)$s['channel_width_mhz'] : null,
                        'tx_power_dbm' => $s['tx_power_dbm'] !== null ? (int)$s['tx_power_dbm'] : null,
                        'max_clients' => $s['max_clients'] !== null ? (int)$s['max_clients'] : null,
                        'ssid' => $s['ssid'] ?? null,
                        'security' => $s['security'] ?? null,
                        'wireless_mode' => $s['wireless_mode'] ?? null,
                        'ap_device_id' => !empty($s['ap_device_id']) ? (int)$s['ap_device_id'] : null,
                        'notes' => $s['notes'] ?? null,
                    ],
                    'tower'   => $tower ? [
                        'id' => (int)$tower['id'], 'name' => $tower['name'], 'type' => $tower['type'],
                        'lat' => (float)$tower['lat'], 'lng' => (float)$tower['lng'],
                    ] : null,
                    'ap_device' => $ap ? [
                        'id' => (int)$ap['id'], 'name' => $ap['name'], 'role' => $ap['role'],
                        'vendor' => $ap['vendor'], 'model' => $ap['model'],
                        'status' => $ap['status'], 'last_seen_at' => $ap['last_seen_at'],
                        'mgmt_ip' => $ap['mgmt_ip'] ?? null,
                    ] : null,
                    'customer_count' => $customer_count,
                    'stats'   => [
                        'link_count'   => (int)($st['link_count'] ?? 0),
                        'avg_signal'   => $st['avg_signal'] !== null ? round((float)$st['avg_signal'], 1) : null,
                        'avg_snr'      => $st['avg_snr']    !== null ? round((float)$st['avg_snr'], 1)   : null,
                        'avg_ccq'      => $st['avg_ccq']    !== null ? round((float)$st['avg_ccq'], 1)   : null,
                        'avg_health'   => $st['avg_health'] !== null ? (int)round((float)$st['avg_health'])   : null,
                        'worst_health' => $st['worst_health'] !== null ? (int)$st['worst_health'] : null,
                        'total_throughput' => $st['total_thr'] !== null ? round((float)$st['total_thr'], 1) : null,
                        'last_seen_at' => $st['last_seen_at'] ?? null,
                        'peak_throughput'=> $thr_row['peak']   !== null ? round((float)$thr_row['peak'], 1) : null,
                        'avg_throughput' => $thr_row['avg_thr']!== null ? round((float)$thr_row['avg_thr'], 1) : null,
                    ],
                    'outage'  => $outage ? [
                        'id' => (int)$outage['id'], 'started_at' => $outage['started_at'],
                        'cause' => $outage['cause'], 'affected_count' => (int)$outage['affected_count'],
                    ] : null,
                ]);
                exit;
            }

            // Customer / client — billing + radio side. We pull the
            // user's wireless_link (if any) for live signal/SNR/CCQ
            // and join through to the AP and sector for context.
            case 'client': {
                $u = find_user_by_id($id);
                if (!$u || ($u['role'] ?? '') !== 'client') {
                    echo json_encode(['ok' => false, 'error' => 'Client not found']); exit;
                }
                $sector = !empty($u['sector_id']) ? sector_find((int)$u['sector_id']) : null;
                $tower  = $sector ? site_find((int)$sector['tower_id']) : null;
                $ap     = ($sector && !empty($sector['ap_device_id']))
                          ? device_find((int)$sector['ap_device_id']) : null;

                $wlstmt = pdo()->prepare(
                    "SELECT wl.id, wl.signal_dbm, wl.signal_dbm_remote,
                            wl.noise_dbm, wl.noise_dbm_remote,
                            wl.snr_db, wl.snr_db_remote, wl.ccq_pct,
                            wl.tx_rate_mbps, wl.rx_rate_mbps,
                            wl.throughput_local_mbps, wl.throughput_remote_mbps,
                            wl.capacity_local_mbps, wl.capacity_remote_mbps,
                            wl.tx_power_dbm_local, wl.tx_power_dbm_remote,
                            wl.frequency_mhz, wl.channel_width_mhz,
                            wl.distance_km, wl.health_score, wl.last_evaluated_at,
                            wl.modulation, wl.wireless_mode, wl.ssid, wl.uptime_seconds,
                            ap.name AS ap_name, cpe.name AS cpe_name
                       FROM wireless_links wl
                       JOIN devices ap       ON ap.id = wl.ap_device_id
                       LEFT JOIN devices cpe ON cpe.id = wl.cpe_device_id
                      WHERE wl.customer_id = ?
                      ORDER BY wl.last_evaluated_at IS NULL, wl.last_evaluated_at DESC
                      LIMIT 1"
                );
                $wlstmt->execute([$id]);
                $wl = $wlstmt->fetch() ?: null;

                // Distance from tower → client (if both lat/lng set)
                $distKm = null;
                if ($tower && !empty($tower['lat']) && !empty($tower['lng'])
                    && $u['lat'] !== null && $u['lng'] !== null) {
                    $distKm = round(haversine_km(
                        (float)$tower['lat'], (float)$tower['lng'],
                        (float)$u['lat'],     (float)$u['lng']
                    ), 3);
                }

                echo json_encode([
                    'ok'     => true,
                    'kind'   => 'client',
                    'client' => [
                        'id' => (int)$u['id'], 'username' => $u['username'],
                        'account_no' => $u['account_no'] ?? null,
                        'name' => trim((string)($u['name'] ?? '') . ' ' . (string)($u['surname'] ?? '')),
                        'status' => $u['status'] ?? 'active',
                        'address' => $u['address'] ?? '',
                        'phone' => $u['phone'] ?? null,
                        'email' => $u['email'] ?? null,
                        'lat'    => $u['lat'] !== null ? (float)$u['lat'] : null,
                        'lng'    => $u['lng'] !== null ? (float)$u['lng'] : null,
                        'plan_id'=> $u['plan_id'] ?? null,
                    ],
                    'sector' => $sector ? [
                        'id' => (int)$sector['id'], 'name' => $sector['name'],
                        'azimuth_deg' => $sector['azimuth_deg'] !== null ? (int)$sector['azimuth_deg'] : null,
                        'beamwidth_deg' => $sector['beamwidth_deg'] !== null ? (int)$sector['beamwidth_deg'] : null,
                        'band' => $sector['band'],
                        'frequency_mhz' => $sector['frequency_mhz'] !== null ? (int)$sector['frequency_mhz'] : null,
                        'channel_width_mhz' => $sector['channel_width_mhz'] !== null ? (int)$sector['channel_width_mhz'] : null,
                    ] : null,
                    'tower'  => $tower ? [
                        'id' => (int)$tower['id'], 'name' => $tower['name'], 'type' => $tower['type'],
                        'lat' => (float)$tower['lat'], 'lng' => (float)$tower['lng'],
                    ] : null,
                    'ap_device' => $ap ? [
                        'id' => (int)$ap['id'], 'name' => $ap['name'],
                        'status' => $ap['status'], 'last_seen_at' => $ap['last_seen_at'],
                        'vendor' => $ap['vendor'], 'model' => $ap['model'],
                    ] : null,
                    'wireless_link' => $wl,
                    'distance_km'   => $distKm,
                ]);
                exit;
            }

            // Site (tower / ap / etc) — devices + sectors + connected
            // backbone links + wireless_links rollup. Used by the panel
            // when the operator clicks a site marker.
            case 'site': {
                $site = site_find($id);
                if (!$site) { echo json_encode(['ok' => false, 'error' => 'Site not found']); exit; }
                $devs = pdo()->prepare(
                    "SELECT id, name, role, vendor, model, status, last_seen_at
                       FROM devices WHERE site_id = ? ORDER BY name ASC"
                );
                $devs->execute([$id]);
                $devices = $devs->fetchAll() ?: [];

                $secStmt = pdo()->prepare("SELECT * FROM sectors WHERE tower_id = ? ORDER BY azimuth_deg ASC, name ASC");
                $secStmt->execute([$id]);
                $sectors = $secStmt->fetchAll() ?: [];
                foreach ($sectors as &$ss) {
                    $cs = pdo()->prepare("SELECT COUNT(*) FROM users WHERE role='client' AND sector_id = ?");
                    $cs->execute([(int)$ss['id']]);
                    $ss['customer_count'] = (int)$cs->fetchColumn();
                }
                unset($ss);

                $linkStmt = pdo()->prepare(
                    "SELECT sl.id, sl.type, sl.label, sl.capacity_mbps, sl.frequency,
                            sl.from_site_id, sl.to_site_id,
                            o.name AS other_name, o.lat AS other_lat, o.lng AS other_lng
                       FROM site_links sl
                       JOIN sites o ON o.id = CASE WHEN sl.from_site_id = ? THEN sl.to_site_id ELSE sl.from_site_id END
                      WHERE sl.from_site_id = ? OR sl.to_site_id = ?"
                );
                $linkStmt->execute([$id, $id, $id]);
                $blinks = $linkStmt->fetchAll() ?: [];
                foreach ($blinks as &$bl) {
                    $bl['distance_km'] = round(haversine_km(
                        (float)$site['lat'], (float)$site['lng'],
                        (float)$bl['other_lat'], (float)$bl['other_lng']
                    ), 3);
                }
                unset($bl);

                $wstats = pdo()->prepare(
                    "SELECT COUNT(*) AS n,
                            AVG(wl.health_score) AS avg_h,
                            MIN(wl.health_score) AS worst_h,
                            SUM(wl.health_score IS NOT NULL AND wl.health_score < 50) AS degraded
                       FROM wireless_links wl
                       JOIN devices d ON d.id = wl.ap_device_id
                      WHERE d.site_id = ?"
                );
                $wstats->execute([$id]);
                $ws = $wstats->fetch() ?: ['n' => 0, 'avg_h' => null, 'worst_h' => null, 'degraded' => 0];

                echo json_encode([
                    'ok'    => true,
                    'kind'  => 'site',
                    'site'  => [
                        'id' => (int)$site['id'], 'name' => $site['name'], 'type' => $site['type'],
                        'lat' => (float)$site['lat'], 'lng' => (float)$site['lng'],
                        'coverage_radius_m' => $site['coverage_radius_m'] !== null ? (int)$site['coverage_radius_m'] : null,
                        'notes' => $site['notes'] ?? null,
                    ],
                    'devices' => array_map(fn($d) => [
                        'id' => (int)$d['id'], 'name' => $d['name'],
                        'role' => $d['role'], 'vendor' => $d['vendor'], 'model' => $d['model'],
                        'status' => $d['status'], 'last_seen_at' => $d['last_seen_at'],
                    ], $devices),
                    'sectors' => array_map(fn($s) => [
                        'id' => (int)$s['id'], 'name' => $s['name'],
                        'band' => $s['band'],
                        'azimuth_deg' => $s['azimuth_deg'] !== null ? (int)$s['azimuth_deg'] : null,
                        'beamwidth_deg' => $s['beamwidth_deg'] !== null ? (int)$s['beamwidth_deg'] : null,
                        'customer_count' => (int)($s['customer_count'] ?? 0),
                        'max_clients' => $s['max_clients'] !== null ? (int)$s['max_clients'] : null,
                    ], $sectors),
                    'links' => array_map(fn($b) => [
                        'id' => (int)$b['id'], 'type' => $b['type'], 'label' => $b['label'],
                        'capacity_mbps' => $b['capacity_mbps'] !== null ? (float)$b['capacity_mbps'] : null,
                        'frequency' => $b['frequency'],
                        'other_name' => $b['other_name'],
                        'distance_km' => $b['distance_km'],
                    ], $blinks),
                    'wireless_summary' => [
                        'count'       => (int)$ws['n'],
                        'avg_health'  => $ws['avg_h']    !== null ? (int)round((float)$ws['avg_h'])    : null,
                        'worst_health'=> $ws['worst_h']  !== null ? (int)$ws['worst_h']  : null,
                        'degraded'    => (int)$ws['degraded'],
                    ],
                ]);
                exit;
            }

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown detail kind']); exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

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

  /* ---------- Map-page sidebar ----------
     Take the .portal-side aside out of flow with position:fixed and
     park it OFF-SCREEN to the left. The map uses the full viewport.
     Clicking the floating chevron toggles a `.map-side-open` class
     on <body>; CSS slides the sidebar in. State lives in one place
     (the body class) so DevTools, the script and the cascade can
     never disagree.

     !important is used on the layout rules so portal.css's base
     .portal-side { width: 252px; position: sticky; ... } and its
     @media (max-width:720px) override (which resets position:static)
     can't reach in and undo us. */
  body:has(.map-fs) .portal-side {
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    bottom: 0 !important;
    width: 252px !important;
    height: 100vh !important;
    padding: 24px 16px !important;
    z-index: 1500 !important;
    background: var(--bg-elev);
    border-right: 1px solid var(--border);
    box-shadow: 8px 0 24px rgba(0,0,0,.5);
    overflow-y: auto;
    transform: translateX(-100%) !important;
    transition: transform .25s cubic-bezier(.2,.7,.2,1);
  }
  /* Open state — slide the sidebar back into view. !important keeps
     us above the off-screen rule above (which also uses !important
     for defensiveness) without depending on selector specificity. */
  body.map-side-open:has(.map-fs) .portal-side {
    transform: translateX(0) !important;
  }

  /* Floating chevron — the only opener for the sidebar on the map. */
  body:has(.map-fs) .map-sidebar-toggle {
    position: fixed;
    top: 14px;
    left: 14px;
    z-index: 1600;
    width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 8px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,.55);
    transition: left .25s cubic-bezier(.2,.7,.2,1),
                background .15s, border-color .15s, color .15s;
  }
  body:has(.map-fs) .map-sidebar-toggle:hover,
  body:has(.map-fs) .map-sidebar-toggle:focus-visible {
    background: var(--accent);
    color: #001218;
    border-color: var(--accent);
    outline: none;
  }
  body:has(.map-fs) .map-sidebar-toggle svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
    transition: transform .25s cubic-bezier(.2,.7,.2,1);
    pointer-events: none;     /* let clicks always hit the button itself */
  }
  /* When the sidebar is open: slide the chevron over to the sidebar's
     edge and flip it so it points back (close affordance). */
  body.map-side-open:has(.map-fs) .map-sidebar-toggle {
    left: 260px;
  }
  body.map-side-open:has(.map-fs) .map-sidebar-toggle svg {
    transform: rotate(180deg);
  }
  /* On narrow viewports the sidebar takes the full screen when open. */
  @media (max-width: 600px) {
    body:has(.map-fs) .portal-side { width: 100vw !important; }
    body.map-side-open:has(.map-fs) .map-sidebar-toggle { left: calc(100vw - 50px); }
  }

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
    /* 60px left padding clears the floating sidebar-toggle chevron
       (32px wide + 14px from edge + a bit of breathing room). */
    padding: 10px 18px 10px 60px;
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

  /* ---------- Map shell wrapper (so floating panes can absolute-position) ---------- */
  .map-shell { position: relative; flex: 1; min-height: 300px; display: flex; }
  .map-shell #map { flex: 1; min-height: 300px; background: #0a0d12; }

  /* ---------- Inline search ---------- */
  .map-search {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 220px;
    flex: 0 1 320px;
  }
  .map-search input {
    width: 100%;
    padding: 7px 12px 7px 32px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--text);
    font-size: 12px;
    transition: border-color .15s, box-shadow .15s, background .15s;
  }
  .map-search input::placeholder { color: var(--text-muted); }
  .map-search input:focus {
    outline: none;
    border-color: var(--accent);
    background: rgba(5,218,253,.06);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }
  .map-search::before {
    content: '';
    position: absolute;
    left: 11px; top: 50%;
    width: 13px; height: 13px;
    transform: translateY(-50%);
    background: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%236b7480' stroke-width='1.6'><circle cx='7' cy='7' r='5'/><path d='M11 11l3 3'/></svg>") no-repeat center / contain;
    pointer-events: none;
  }
  .map-search-results {
    position: absolute;
    top: calc(100% + 6px);
    left: 0; right: 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: 0 12px 28px rgba(0,0,0,.6);
    z-index: 1100;
    max-height: 280px;
    overflow-y: auto;
    display: none;
  }
  .map-search-results.is-open { display: block; }
  .map-search-results .msr-row {
    padding: 8px 12px;
    font-size: 12px;
    color: var(--text-dim);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid var(--border);
    transition: background .15s, color .15s;
  }
  .map-search-results .msr-row:last-child { border-bottom: none; }
  .map-search-results .msr-row:hover,
  .map-search-results .msr-row.is-cursor {
    background: var(--accent-soft);
    color: var(--text);
  }
  .map-search-results .msr-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  .map-search-results .msr-meta {
    margin-left: auto;
    color: var(--text-muted);
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: .06em;
  }
  .map-search-results .msr-empty {
    padding: 12px;
    color: var(--text-muted);
    font-size: 12px;
    text-align: center;
    font-style: italic;
  }

  /* ---------- Floating quick-tools (UISP-style top-left card) ---------- */
  .map-quicktools {
    position: absolute;
    top: 12px;
    left: 60px;     /* clear of Leaflet's zoom bar */
    z-index: 800;
    display: flex;
    gap: 6px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 4px;
    box-shadow: 0 6px 18px rgba(0,0,0,.45);
  }
  .map-quicktools button {
    width: 30px;
    height: 30px;
    border: none;
    background: transparent;
    color: var(--text-dim);
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, color .15s, transform .15s;
    padding: 0;
  }
  .map-quicktools button:hover { background: var(--accent-soft); color: var(--accent); }
  .map-quicktools button.is-active {
    background: var(--accent);
    color: #001218;
    box-shadow: 0 0 0 3px var(--accent-soft);
  }
  .map-quicktools button svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 1.7;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
  }
  .map-quicktools .qt-sep {
    width: 1px;
    background: var(--border);
    margin: 4px 2px;
  }

  /* ---------- Signal-strength legend (UISP-style top-right) ----------
     Sits below Leaflet's layers control which already lives at top-right
     (admin-map.js mounts it un-collapsed). The 100px top offset clears
     the layers control + the inline "Coverage" sector picker that the
     coverage-heatmap inline script appends right under it. */
  .map-signal-legend {
    position: absolute;
    top: 100px;
    right: 12px;
    z-index: 800;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    box-shadow: 0 6px 18px rgba(0,0,0,.45);
    font-size: 10.5px;
    color: var(--text-muted);
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 168px;
  }
  .map-signal-legend .msl-row {
    display: flex;
    justify-content: space-between;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 600;
    color: var(--text-muted);
  }
  .map-signal-legend .msl-bar {
    height: 6px;
    border-radius: 999px;
    background: linear-gradient(90deg,
      #dc2626 0%, #f97316 25%, #eab308 50%, #84cc16 75%, #22c55e 100%);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.05);
  }

  /* ---------- Live coord readout (bottom-right) ---------- */
  .map-coords {
    position: absolute;
    bottom: 12px;
    left: 12px;
    z-index: 750;
    background: rgba(16,20,27,.85);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 5px 10px;
    font-family: 'JetBrains Mono', 'Roboto Mono', monospace;
    font-size: 10.5px;
    color: var(--text-dim);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    pointer-events: none;
    user-select: none;
    letter-spacing: .02em;
  }
  .map-coords strong { color: var(--accent); font-weight: 600; }

  /* ---------- Distance-measure tooltip (follows cursor) ---------- */
  .leaflet-measure-tip {
    background: var(--accent);
    color: #001218;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .03em;
    box-shadow: 0 4px 10px rgba(5,218,253,.3);
    white-space: nowrap;
  }
  .leaflet-tooltip.leaflet-measure-tip::before { display: none; }

  /* ---------- Bottom detail panel (UISP-style flat strip) ----------
     Anchored to the bottom edge of the map, full width. Slides up
     when a feature is selected.  Internally a 3-column flex layout
     (left card | centre metrics | right card) so it stays compact
     even when packed with sector / link telemetry. */
  .map-detail-panel {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 900;
    background: linear-gradient(180deg, rgba(16,20,27,.97) 0%, rgba(10,13,18,1) 100%);
    border-top: 1px solid var(--border);
    box-shadow: 0 -8px 24px rgba(0,0,0,.5), inset 0 1px 0 rgba(5,218,253,.06);
    padding: 8px 18px 10px;
    transform: translateY(100%);
    opacity: 1;
    pointer-events: none;
    transition: transform .25s cubic-bezier(.2,.7,.2,1);
    /* Let the strip size to its content — no artificial scrollbar.
       The 40vh cap only kicks in on really short viewports, and even
       then overflow-y: auto shows the scrollbar only when needed. */
    max-height: 40vh;
    overflow-y: auto;
  }
  .map-detail-panel.is-open {
    transform: translateY(0);
    pointer-events: auto;
  }
  .map-detail-panel #mdp-grid {
    transition: opacity .15s;
  }
  .map-detail-panel .mdp-close {
    position: absolute;
    top: 8px;
    right: 10px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 18px;
    cursor: pointer;
    line-height: 1;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background .15s, color .15s;
  }
  .map-detail-panel .mdp-close:hover { background: rgba(255,255,255,.06); color: var(--text); }

  .map-detail-panel .mdp-grid {
    display: grid;
    grid-template-columns: 1.05fr 1.6fr 1.05fr;
    gap: 12px;
    align-items: start;          /* cards size to content, no empty stretch */
  }
  .map-detail-panel .mdp-card {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 11px 9px;
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    min-width: 0;
  }
  .map-detail-panel .mdp-card .mdp-name {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .map-detail-panel .mdp-card .mdp-name input {
    flex: 1;
    min-width: 0;
    background: transparent;
    border: none;
    border-bottom: 1px dashed transparent;
    padding: 2px 2px;
    color: var(--text);
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: -.005em;
    cursor: default;
    outline: none;
  }
  .map-detail-panel .mdp-card .mdp-name input:read-only:focus { border-bottom-color: transparent; }
  .map-detail-panel .mdp-card .mdp-type-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    flex-shrink: 0;
  }
  .map-detail-panel .mdp-card .mdp-type-pill::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
  }
  .map-detail-panel .mdp-kv {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 10px;
    margin-top: 2px;
  }
  .map-detail-panel .mdp-kv .mdp-cell {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .map-detail-panel .mdp-kv .mdp-cell-wide { grid-column: 1 / -1; }
  .map-detail-panel .mdp-kv .mdp-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--text-muted);
    font-weight: 600;
    line-height: 1.3;
  }
  .map-detail-panel .mdp-kv .mdp-val {
    font-size: 11.5px;
    color: var(--text);
    margin-top: 1px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
  }

  /* Centre column — link metrics (capacity bar, distance, signal gradient) */
  .map-detail-panel .mdp-center {
    padding: 9px 12px 10px;
    background: linear-gradient(180deg, rgba(5,218,253,0.04) 0%, transparent 100%);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
    gap: 7px;
  }
  .map-detail-panel .mdp-cap-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 600;
  }
  .map-detail-panel .mdp-cap-row .mdp-cap-val {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 16px;
    color: var(--accent);
    letter-spacing: 0;
    text-transform: none;
    font-weight: 700;
  }
  .map-detail-panel .mdp-cap-bar {
    height: 6px;
    border-radius: 999px;
    background: rgba(255,255,255,.05);
    overflow: hidden;
    position: relative;
  }
  .map-detail-panel .mdp-cap-fill {
    position: absolute; inset: 0;
    background: linear-gradient(90deg, #05DAFD 0%, #0c8 100%);
    transform-origin: left;
    border-radius: 999px;
  }

  .map-detail-panel .mdp-distance {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 12px;
    color: var(--text-dim);
    border-top: 1px dashed var(--border);
    border-bottom: 1px dashed var(--border);
  }
  .map-detail-panel .mdp-distance .mdp-arrow {
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, var(--accent) 0%, var(--accent) 30%, transparent 30%, transparent 70%, var(--accent) 70%);
    background-size: 8px 1px;
    background-repeat: repeat-x;
    margin: 0 10px;
    position: relative;
  }
  .map-detail-panel .mdp-distance .mdp-arrow::before,
  .map-detail-panel .mdp-distance .mdp-arrow::after {
    content: '';
    position: absolute;
    top: 50%;
    width: 6px; height: 6px;
    border: 1.5px solid var(--accent);
    border-radius: 50%;
    background: var(--bg-card);
    transform: translateY(-50%);
  }
  .map-detail-panel .mdp-distance .mdp-arrow::before { left: 0; }
  .map-detail-panel .mdp-distance .mdp-arrow::after  { right: 0; }
  .map-detail-panel .mdp-distance .mdp-dist-val {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    color: var(--accent);
    font-weight: 700;
    font-size: 13px;
    white-space: nowrap;
  }

  .map-detail-panel .mdp-signal-bar {
    height: 7px;
    border-radius: 999px;
    background: linear-gradient(90deg,
      #dc2626 0%, #f97316 25%, #eab308 50%, #84cc16 75%, #22c55e 100%);
    position: relative;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.05);
  }
  .map-detail-panel .mdp-signal-bar .mdp-signal-marker {
    position: absolute;
    top: -3px; bottom: -3px;
    width: 3px;
    background: var(--text);
    border-radius: 999px;
    box-shadow: 0 0 0 1px rgba(0,0,0,.6);
    transition: left .25s cubic-bezier(.2,.7,.2,1);
  }
  .map-detail-panel .mdp-signal-meta {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .08em;
  }

  .map-detail-panel .mdp-actions {
    display: flex;
    gap: 6px;
    margin-top: 6px;
  }
  .map-detail-panel .mdp-actions .btn {
    font-size: 11px;
    padding: 5px 10px;
    border-radius: 999px;
  }

  /* Site selection: card on the left, centre column on the right. */
  .map-detail-panel.is-site .mdp-grid {
    grid-template-columns: 1fr 1.4fr;
  }

  /* ---------- Right sidebar — related entities for selection ----------
     Slides in from the right when a feature is clicked.  Holds the
     "list of things connected to what you clicked" (sectors + links
     for a tower; clients for a sector; sectors for each endpoint of a
     link).  Sits ABOVE the bottom detail panel so the two never
     collide.  Closed by an × in its header or by Esc. */
  .map-side-panel {
    position: absolute;
    top: 12px;
    right: 0;
    bottom: 12px;        /* full height when bottom strip is closed */
    width: 340px;
    z-index: 850;
    background: var(--bg-card);
    border-left: 1px solid var(--border);
    border-top-left-radius: var(--radius);
    border-bottom-left-radius: var(--radius);
    box-shadow: -8px 0 24px rgba(0,0,0,.45);
    transform: translateX(100%);
    pointer-events: none;
    transition: transform .25s cubic-bezier(.2,.7,.2,1), bottom .2s;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .map-side-panel.is-open {
    transform: translateX(0);
    pointer-events: auto;
  }
  /* When the bottom strip is open, lift the sidebar to clear it.  The
     --mdp-h CSS var is set in JS via a ResizeObserver on the strip
     so the side panel always sits 12px above the strip's actual
     rendered height. */
  .map-shell.is-bottom-open .map-side-panel {
    bottom: calc(var(--mdp-h, 200px) + 12px);
  }

  .map-side-panel .msp-head {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px 10px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(180deg, rgba(5,218,253,.05) 0%, transparent 100%);
  }
  .map-side-panel .msp-title {
    flex: 1;
    min-width: 0;
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    letter-spacing: -.005em;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .map-side-panel .msp-subtitle {
    font-size: 10.5px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-top: 2px;
  }
  .map-side-panel .msp-close {
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background .15s, color .15s;
  }
  .map-side-panel .msp-close:hover { background: rgba(255,255,255,.06); color: var(--text); }

  .map-side-panel .msp-tabs {
    flex-shrink: 0;
    display: flex;
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,.02);
  }
  .map-side-panel .msp-tab {
    flex: 1;
    padding: 9px 8px;
    text-align: center;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    cursor: pointer;
    transition: color .15s, border-color .15s, background .15s;
    font-family: inherit;
  }
  .map-side-panel .msp-tab:hover { color: var(--text); }
  .map-side-panel .msp-tab.is-active {
    color: var(--accent);
    border-bottom-color: var(--accent);
    background: rgba(5,218,253,.04);
  }
  .map-side-panel .msp-tab .msp-tab-count {
    margin-left: 4px;
    color: var(--text-muted);
    font-weight: 500;
  }
  .map-side-panel .msp-tab.is-active .msp-tab-count { color: var(--accent); opacity: .75; }

  .map-side-panel .msp-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
  }
  .map-side-panel .msp-empty {
    padding: 24px 14px;
    text-align: center;
    color: var(--text-muted);
    font-size: 12px;
    font-style: italic;
  }
  .map-side-panel .msp-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .map-side-panel .msp-item {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: background .15s;
    color: var(--text);
  }
  .map-side-panel .msp-item:hover { background: var(--accent-soft); }
  .map-side-panel .msp-item-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
    border: 1.5px solid var(--bg-card);
    box-shadow: 0 0 0 1px rgba(255,255,255,.06);
  }
  .map-side-panel .msp-item-body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .map-side-panel .msp-item-name {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .map-side-panel .msp-item-meta {
    font-size: 10.5px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .map-side-panel .msp-item-pill {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    background: var(--accent-soft);
    color: var(--accent);
    flex-shrink: 0;
  }
  .map-side-panel .msp-item-pill.is-warn   { background: rgba(234,179,8,.18);  color: #facc15; }
  .map-side-panel .msp-item-pill.is-danger { background: rgba(220,68,68,.20);  color: #ff6e85; }
  .map-side-panel .msp-item-pill.is-muted  { background: rgba(120,140,160,.16); color: var(--text-dim); }

  /* Push the floating signal legend down when the sidebar is open
     so it doesn't collide with it on narrow viewports. */
  .map-shell.is-side-open .map-signal-legend { display: none; }
  /* Coords chip (bottom-left) overlaps the bottom strip; fade it out
     when the strip is open to keep things tidy. */
  .map-coords { transition: opacity .2s; }
  .map-shell.is-bottom-open .map-coords { opacity: 0; pointer-events: none; }

  /* ---------- Highlight selected feature ---------- */
  .leaflet-interactive.is-mdp-selected {
    filter: drop-shadow(0 0 6px rgba(5,218,253,.85));
  }
  /* Pulse ring animation for selected site marker */
  @keyframes mdp-pulse {
    0%   { transform: scale(.8);  opacity: .9; }
    100% { transform: scale(2.4); opacity: 0;  }
  }
  .mdp-pulse-marker {
    width: 22px; height: 22px;
    border-radius: 50%;
    border: 2px solid var(--accent);
    background: rgba(5,218,253,.18);
    animation: mdp-pulse 1.4s ease-out infinite;
  }

  /* ---------- Link hover tooltip (UISP-style chip) ---------- */
  .leaflet-link-tip {
    background: var(--bg-card) !important;
    color: var(--text) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-sm) !important;
    padding: 7px 10px !important;
    font-family: 'Inter', system-ui, sans-serif !important;
    font-size: 11.5px !important;
    line-height: 1.45 !important;
    box-shadow: 0 6px 18px rgba(0,0,0,.5) !important;
  }
  .leaflet-link-tip::before { display: none !important; }
  .leaflet-link-tip .ltip-title {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 3px;
  }
  .leaflet-link-tip .ltip-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    color: var(--text-dim);
    font-size: 11px;
  }
  .leaflet-link-tip .ltip-row strong {
    color: var(--accent);
    font-weight: 600;
    font-family: 'Space Grotesk', 'Inter', sans-serif;
  }
  .leaflet-link-tip .ltip-route {
    color: var(--text-muted);
    font-size: 10.5px;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: .06em;
  }

  /* Make link hover thicken the line subtly. */
  path.leaflet-interactive.wf-link:hover {
    filter: brightness(1.3) drop-shadow(0 0 6px rgba(5,218,253,.55));
  }

  /* ---------- Mobile / narrow toolbar ---------- */
  @media (max-width: 900px) {
    .map-bar { padding: 8px 12px 8px 56px; gap: 10px; }
    .map-legend { display: none; }
    .map-counts { font-size: 10px; }
    .map-search { display: none; }
    .map-quicktools { left: 56px; top: 10px; }
    .map-signal-legend { display: none; }
    .map-detail-panel { max-height: 60vh; padding: 10px 12px; }
    .map-detail-panel .mdp-grid,
    .map-detail-panel.is-site .mdp-grid {
      grid-template-columns: 1fr;
      gap: 8px;
    }
    .map-coords { display: none; }
    .map-side-panel {
      width: 100%;
      bottom: 0;        /* on mobile take full screen when open */
      top: 0;
      border-radius: 0;
    }
  }
</style>

<!-- Sidebar opener — the only way to open the admin nav on the map page.
     Clicking it toggles the body class .map-side-open, which the CSS
     rules above pick up to slide the sidebar in/out. -->
<button id="map-sidebar-toggle" class="map-sidebar-toggle" type="button"
        aria-label="Toggle navigation" aria-expanded="false"
        aria-controls="portal-side">
  <svg viewBox="0 0 16 16" aria-hidden="true">
    <path d="M5 2l6 6-6 6"/>
  </svg>
</button>

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

    <div class="group">
      <label class="inline-check"><input type="checkbox" id="toggle-signal">      Signal</label>
      <label class="inline-check"><input type="checkbox" id="toggle-rfdensity">   RF noise</label>
      <label class="inline-check"><input type="checkbox" id="toggle-throughput">  Throughput</label>
      <select id="toggle-outage-history" class="btn btn-ghost btn-sm" style="padding:4px 8px;">
        <option value="">Outage hist…</option>
        <option value="30">30 days</option>
        <option value="90">90 days</option>
      </select>
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

    <div class="sep"></div>

    <div class="map-search">
      <input id="map-search-input" type="search" placeholder="Search sites, links, clients…" autocomplete="off">
      <div id="map-search-results" class="map-search-results" role="listbox"></div>
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
      <span><i style="background:#f97316;width:6px;height:6px;"></i>≥85% full</span>
      <span><i style="background:#d44;width:6px;height:6px;"></i>≥100% full</span>
    </div>
  </div>

  <div id="map-hint" class="map-hint"></div>

  <div class="map-shell">
    <div id="map"></div>

    <!-- Floating UISP-style quick tools (top-left, next to zoom bar) -->
    <div id="map-quicktools" class="map-quicktools" role="toolbar" aria-label="Map quick tools">
      <button id="qt-fit-all" type="button" title="Fit all sites in view" aria-label="Fit all sites">
        <svg viewBox="0 0 16 16" aria-hidden="true">
          <path d="M2 5V2h3M14 5V2h-3M2 11v3h3M14 11v3h-3"/>
        </svg>
      </button>
      <button id="qt-locate" type="button" title="Recenter to default" aria-label="Recenter">
        <svg viewBox="0 0 16 16" aria-hidden="true">
          <circle cx="8" cy="8" r="3"/>
          <path d="M8 1v2M8 13v2M1 8h2M13 8h2"/>
        </svg>
      </button>
      <span class="qt-sep"></span>
      <button id="qt-measure" type="button" title="Distance measure (click two points)" aria-label="Distance measure">
        <svg viewBox="0 0 16 16" aria-hidden="true">
          <path d="M2 12L12 2l2 2L4 14z"/>
          <path d="M5 9l1 1M7 7l1 1M9 5l1 1"/>
        </svg>
      </button>
    </div>

    <!-- Signal-strength gradient legend (top-right) -->
    <div id="map-signal-legend" class="map-signal-legend" aria-hidden="true">
      <div class="msl-row"><span>Weak</span><span>Strong</span></div>
      <div class="msl-bar"></div>
      <div class="msl-row" style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">
        <span>−95 dBm</span><span>−45 dBm</span>
      </div>
    </div>

    <!-- Live cursor coords + zoom (bottom-left) -->
    <div id="map-coords" class="map-coords">
      <span><strong id="coord-lat">–</strong>, <strong id="coord-lng">–</strong></span>
      <span style="margin-left:10px;">z<strong id="coord-zoom">–</strong></span>
    </div>

    <!-- Right sidebar — related entities for the current selection
         (sectors+links for a tower, clients for a sector, etc) -->
    <aside id="map-side-panel" class="map-side-panel" role="complementary" aria-label="Related entities" aria-hidden="true">
      <header class="msp-head">
        <div class="msp-title-wrap" style="flex:1;min-width:0;">
          <div class="msp-title" id="msp-title">—</div>
          <div class="msp-subtitle" id="msp-subtitle"></div>
        </div>
        <button class="msp-close" id="msp-close" type="button" aria-label="Close sidebar">×</button>
      </header>
      <div class="msp-tabs" id="msp-tabs" role="tablist"></div>
      <div class="msp-body" id="msp-body"></div>
    </aside>

    <!-- Bottom detail panel (slides up when a link or site is selected) -->
    <div id="map-detail-panel" class="map-detail-panel" role="dialog" aria-label="Selection detail" aria-hidden="true">
      <button class="mdp-close" id="mdp-close" type="button" aria-label="Close">×</button>
      <div class="mdp-grid" id="mdp-grid">
        <!-- contents injected by JS -->
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="map-data"><?= json_encode($map_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"></script>
<script src="/assets/js/admin-map.js" defer></script>

<script>
/* Map sidebar toggle — defensive build.
   Belt and suspenders: drive the open state through TWO mechanisms
   in parallel, so even if one is being interfered with by a stale
   cached stylesheet, an extension, etc., the other still pulls the
   sidebar in:

     1) Toggle the .map-side-open class on <body> — picked up by
        the CSS rules above.
     2) Set inline style.transform on the .portal-side element — this
        beats every CSS rule that doesn't itself use !important.

   Click handling is delegated on `document` at CAPTURE PHASE so
   nothing downstream (Leaflet, browser extensions, etc.) can call
   stopPropagation before we react.  We also expose a debug API on
   window.MAP_SIDEBAR for manual probing in DevTools:

     MAP_SIDEBAR.open()    — force open
     MAP_SIDEBAR.close()   — force close
     MAP_SIDEBAR.toggle()  — flip
     MAP_SIDEBAR.state()   — current open state + element refs */
(function () {
  function getSide() { return document.querySelector('.portal-side'); }
  function getBtn()  { return document.getElementById('map-sidebar-toggle'); }
  function isOpen()  { return document.body.classList.contains('map-side-open'); }
  function setOpen(o) {
    o = !!o;
    document.body.classList.toggle('map-side-open', o);
    var side = getSide();
    if (side) side.style.transform = o ? 'translateX(0)' : 'translateX(-100%)';
    var btn = getBtn();
    if (btn) {
      btn.setAttribute('aria-expanded', o ? 'true' : 'false');
      btn.style.left = o ? '260px' : '14px';
      var svg = btn.querySelector('svg');
      if (svg) svg.style.transform = o ? 'rotate(180deg)' : 'rotate(0deg)';
    }
    console.log('[map-sidebar]', o ? 'OPEN' : 'CLOSE');
  }

  // Public debug surface. Stays on the page so the user can poke at
  // it from the console if the chevron click somehow doesn't reach
  // our delegated handler (extension, CSP, etc.).
  window.MAP_SIDEBAR = {
    open:   function () { setOpen(true);  },
    close:  function () { setOpen(false); },
    toggle: function () { setOpen(!isOpen()); },
    state:  function () {
      return {
        open:   isOpen(),
        button: getBtn(),
        side:   getSide(),
        bodyClasses: document.body.className,
      };
    },
  };

  function onActivate(e) {
    var t = e.target;
    var hit = t && t.closest && t.closest('#map-sidebar-toggle');
    if (hit) {
      e.preventDefault();
      e.stopPropagation();
      setOpen(!isOpen());
      return;
    }
    if (!isOpen()) return;
    if (t && t.closest && t.closest('.portal-side')) return;
    setOpen(false);
  }

  function wire() {
    if (!getBtn())  { console.warn('[map-sidebar] toggle button missing'); return; }
    if (!getSide()) { console.warn('[map-sidebar] .portal-side missing — nav will not render'); return; }
    console.log('[map-sidebar] wired — click chevron, or run MAP_SIDEBAR.toggle() in console');
    // Capture phase on document so nothing downstream can swallow
    // the event with stopPropagation before we react.
    document.addEventListener('click', onActivate, true);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen()) setOpen(false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
</script>

<script>
/* Coverage heatmap — operator picks a sector, fetches a GeoJSON grid
   of predicted RSSI cells, and drops them onto the map as a coloured
   layer. Toggle off to clear. */
(function () {
  function init() {
    if (!window.WIFIBER_MAP || typeof L === 'undefined') {
      return setTimeout(init, 300);
    }
    const map = window.WIFIBER_MAP;
    const dataEl = document.getElementById('map-data');
    let boot;
    try { boot = JSON.parse(dataEl.textContent); } catch (e) { return; }
    if (!boot || !boot.sectors || !boot.sectors.length) return;

    let layer = null;
    const colour = (rssi) => {
      if (rssi >= -55) return '#22c55e';
      if (rssi >= -65) return '#84cc16';
      if (rssi >= -75) return '#eab308';
      if (rssi >= -85) return '#f97316';
      return '#dc2626';
    };

    const ctrl = L.control({ position: 'topright' });
    ctrl.onAdd = function () {
      const div = L.DomUtil.create('div', 'leaflet-bar');
      div.style.background = '#fff';
      div.style.padding = '6px 8px';
      div.style.font = '12px sans-serif';
      div.innerHTML =
        '<label style="display:block;margin-bottom:4px;"><strong>Coverage</strong></label>' +
        '<select id="cov-pick" style="max-width:160px;">' +
          '<option value="">— sector —</option>' +
          boot.sectors.map(s => '<option value="' + s.id + '">' + s.name + '</option>').join('') +
        '</select> ' +
        '<button id="cov-clear" type="button">Clear</button>';
      L.DomEvent.disableClickPropagation(div);
      return div;
    };
    ctrl.addTo(map);

    document.addEventListener('change', function (e) {
      if (e.target && e.target.id === 'cov-pick') {
        const id = e.target.value;
        if (layer) { map.removeLayer(layer); layer = null; }
        if (!id) return;
        fetch('/admin/map.php?coverage_for=' + encodeURIComponent(id), { credentials: 'same-origin' })
          .then(r => r.json())
          .then(j => {
            if (!j || !j.ok) return;
            layer = L.geoJSON(j.grid, {
              style: f => ({
                color: colour(f.properties.rssi),
                fillColor: colour(f.properties.rssi),
                fillOpacity: 0.35,
                weight: 0,
              }),
            }).bindTooltip(f => f.feature.properties.rssi + ' dBm', { sticky: true });
            layer.addTo(map);
          });
      }
    });
    document.addEventListener('click', function (e) {
      if (e.target && e.target.id === 'cov-clear') {
        if (layer) { map.removeLayer(layer); layer = null; }
        const sel = document.getElementById('cov-pick');
        if (sel) sel.value = '';
      }
    });
  }
  init();
})();
</script>

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

<script>
/* Section-1 overlays:
     • Signal     — per-client SNR halo (live, from wireless_links)
     • RF noise   — passive-scan noise density per AP (rf_environment_samples)
     • Throughput — last-hour per-sector aggregate Mbps
     • Outage hist — 30/90-day outage hot-spots per tower
   Each layer is opt-in via its own toolbar toggle and lazy-fetched the
   first time it's enabled, then cached for the page lifetime. */
(function () {
  function init() {
    if (!window.WIFIBER_MAP || typeof L === 'undefined') return setTimeout(init, 250);
    const map = window.WIFIBER_MAP;

    const signalLayer     = L.layerGroup();
    const rfDensityLayer  = L.layerGroup();
    const throughputLayer = L.layerGroup();
    const outageHistLayer = L.layerGroup();

    let cache = { signal: null, rf: null, thr: null, hist: {} };

    /* ---------- colour helpers ---------- */
    function snrColour(snr_db, signal_dbm) {
      // Prefer SNR; fall back to signal strength.
      if (snr_db !== null && snr_db !== undefined) {
        if (snr_db >= 30) return '#22c55e';
        if (snr_db >= 22) return '#84cc16';
        if (snr_db >= 15) return '#eab308';
        if (snr_db >=  8) return '#f97316';
        return '#dc2626';
      }
      if (signal_dbm >= -55) return '#22c55e';
      if (signal_dbm >= -65) return '#84cc16';
      if (signal_dbm >= -75) return '#eab308';
      if (signal_dbm >= -85) return '#f97316';
      return '#dc2626';
    }
    function noiseColour(rssi) {
      // Higher RSSI in passive scan = noisier RF, bad.
      if (rssi >= -55) return '#dc2626';
      if (rssi >= -65) return '#f97316';
      if (rssi >= -75) return '#eab308';
      if (rssi >= -85) return '#84cc16';
      return '#22c55e';
    }
    function throughputColour(mbps) {
      if (mbps >= 200) return '#22c55e';
      if (mbps >= 100) return '#84cc16';
      if (mbps >=  50) return '#eab308';
      if (mbps >=  10) return '#f97316';
      return '#94a3b8';
    }
    function outageHeatColour(minutes) {
      // Total downtime over the window. >24 h is a red dot, >2 h orange.
      if (minutes >= 1440) return '#dc2626';
      if (minutes >= 360)  return '#f97316';
      if (minutes >= 60)   return '#eab308';
      return '#84cc16';
    }

    /* ---------- fetch helpers ---------- */
    async function fetchOverlay(kind, qs) {
      const url = '/admin/map.php?overlay=' + encodeURIComponent(kind) + (qs || '');
      try {
        const r = await fetch(url, { credentials: 'same-origin' });
        const j = await r.json();
        return j && j.ok ? j : null;
      } catch (e) { return null; }
    }

    /* ---------- Signal halo per client ---------- */
    async function buildSignal() {
      if (cache.signal) return cache.signal;
      const j = await fetchOverlay('client_signal');
      cache.signal = j;
      return j;
    }
    function renderSignal(j) {
      signalLayer.clearLayers();
      if (!j || !j.points) return;
      j.points.forEach(p => {
        const ring = L.circleMarker([p.lat, p.lng], {
          radius: 9,
          color: snrColour(p.snr_db, p.signal_dbm),
          weight: 2,
          fill: false,
          opacity: 0.85,
          interactive: true,
        });
        const snrTxt = (p.snr_db !== null) ? p.snr_db + ' dB SNR · ' : '';
        ring.bindTooltip(
          '<strong>' + (p.account_no || p.username) + '</strong><br>' +
          snrTxt + p.signal_dbm + ' dBm' +
          (p.health !== null ? ' · health ' + p.health : '') +
          (p.last_at ? '<br><small>' + p.last_at + '</small>' : ''),
          { sticky: true }
        );
        ring.addTo(signalLayer);
      });
    }

    /* ---------- RF noise density per AP ---------- */
    async function buildRf() {
      if (cache.rf) return cache.rf;
      const j = await fetchOverlay('rf_density');
      cache.rf = j;
      return j;
    }
    function renderRf(j) {
      rfDensityLayer.clearLayers();
      if (!j || !j.points) return;
      j.points.forEach(p => {
        // Radius scales with sample count, capped at 280 m.
        const radM = Math.min(280, 80 + Math.sqrt(p.samples) * 12);
        const c = noiseColour(p.avg_rssi);
        const ring = L.circle([p.lat, p.lng], {
          radius: radM,
          color: c,
          fillColor: c,
          fillOpacity: 0.18,
          weight: 2,
          opacity: 0.7,
        });
        ring.bindTooltip(
          '<strong>' + p.site_name + ' · ' + p.device_name + '</strong><br>' +
          'avg ' + p.avg_rssi + ' dBm · peak ' + p.peak_rssi + ' dBm<br>' +
          p.samples + ' scan samples in 24 h' +
          (p.last_scan_at ? '<br><small>last scan ' + p.last_scan_at + '</small>' : ''),
          { sticky: true }
        );
        ring.addTo(rfDensityLayer);
      });
    }

    /* ---------- Throughput contours per sector ---------- */
    async function buildThroughput() {
      if (cache.thr) return cache.thr;
      const j = await fetchOverlay('throughput');
      cache.thr = j;
      return j;
    }
    function renderThroughput(j) {
      throughputLayer.clearLayers();
      if (!j || !j.points) return;
      j.points.forEach(p => {
        // Drop a sized chip at the cone's centerline tip.
        const radM = Math.min(600, 120 + Math.sqrt(p.avg_mbps) * 22);
        const c = throughputColour(p.avg_mbps);
        const halo = L.circle([p.lat, p.lng], {
          radius: radM,
          color: c,
          fillColor: c,
          fillOpacity: 0.10,
          weight: 1.5,
          dashArray: '4 6',
          opacity: 0.6,
        });
        halo.bindTooltip(
          '<strong>' + p.sector_name + '</strong><br>' +
          'avg ' + p.avg_mbps + ' Mbps · peak ' + p.peak_mbps + ' Mbps<br>' +
          p.link_count + ' link' + (p.link_count === 1 ? '' : 's') +
          ' · ' + p.samples + ' samples (last 1 h)',
          { sticky: true }
        );
        halo.addTo(throughputLayer);
      });
    }

    /* ---------- Outage history hot-spots ---------- */
    async function buildHist(days) {
      if (cache.hist[days]) return cache.hist[days];
      const j = await fetchOverlay('outage_history', '&days=' + days);
      cache.hist[days] = j;
      return j;
    }
    function renderHist(j) {
      outageHistLayer.clearLayers();
      if (!j || !j.points) return;
      j.points.forEach(p => {
        const radM = Math.min(900, 200 + Math.sqrt(p.down_minutes) * 30);
        const c = outageHeatColour(p.down_minutes);
        const blob = L.circle([p.lat, p.lng], {
          radius: radM,
          color: c,
          fillColor: c,
          fillOpacity: 0.18,
          weight: 1,
          opacity: 0.55,
        });
        const hours = Math.floor(p.down_minutes / 60);
        const mins  = p.down_minutes % 60;
        blob.bindTooltip(
          '<strong>' + p.site_name + '</strong><br>' +
          p.event_count + ' outage' + (p.event_count === 1 ? '' : 's') +
          ' · ' + hours + 'h ' + mins + 'm down<br>' +
          (p.last_started ? '<small>last: ' + p.last_started + '</small>' : ''),
          { sticky: true }
        );
        blob.addTo(outageHistLayer);
      });
    }

    /* ---------- toolbar wiring ---------- */
    const toggleSignal = document.getElementById('toggle-signal');
    if (toggleSignal) toggleSignal.addEventListener('change', async (e) => {
      if (e.target.checked) {
        const j = await buildSignal();
        renderSignal(j);
        signalLayer.addTo(map);
      } else {
        map.removeLayer(signalLayer);
      }
    });

    const toggleRf = document.getElementById('toggle-rfdensity');
    if (toggleRf) toggleRf.addEventListener('change', async (e) => {
      if (e.target.checked) {
        const j = await buildRf();
        renderRf(j);
        rfDensityLayer.addTo(map);
      } else {
        map.removeLayer(rfDensityLayer);
      }
    });

    const toggleThr = document.getElementById('toggle-throughput');
    if (toggleThr) toggleThr.addEventListener('change', async (e) => {
      if (e.target.checked) {
        const j = await buildThroughput();
        renderThroughput(j);
        throughputLayer.addTo(map);
      } else {
        map.removeLayer(throughputLayer);
      }
    });

    const histPicker = document.getElementById('toggle-outage-history');
    if (histPicker) histPicker.addEventListener('change', async (e) => {
      const v = e.target.value;
      if (!v) { map.removeLayer(outageHistLayer); return; }
      const j = await buildHist(parseInt(v, 10));
      renderHist(j);
      outageHistLayer.addTo(map);
    });
  }
  init();
})();
</script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
