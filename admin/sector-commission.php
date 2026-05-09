<?php
/**
 * Sector AP commissioning meter — mobile-first dashboard for the tech
 * standing at the tower with a fresh AP install. Polls the AP device's
 * vendor adapter in-process every couple of seconds and shows only the
 * commissioning-relevant bits:
 *
 *   - AP online / firmware / uptime
 *   - Configured frequency vs actually-broadcast frequency
 *   - Live associated-station count and per-station signal
 *   - RF environment scan (top 5 nearby occupants by RSSI on each band)
 *
 * The "actually aim at a coverage area" step happens by driving a
 * test CPE to the edges and using /admin/align.php — this page is the
 * "AP is up, configured, and seeing clients" side of the same job.
 *
 * Two roles in one file:
 *   GET ?id=X                   → renders the commissioning UI
 *   GET ?action=poll&id=X       → JSON snapshot
 *
 * Auth: admin_can_write (super_admin / admin / technician).
 */
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/sites.php';

require_admin_ip();
$user = require_role(acl_staff_roles(), '/admin/login.php');
if (!admin_can_write()) {
    http_response_code(403);
    die('Commissioning requires admin / super_admin / technician.');
}

$id     = (int)($_GET['id'] ?? 0);
$sector = $id ? sector_find($id) : null;
if (!$sector) {
    http_response_code(404);
    die('Sector not found.');
}
$tower = !empty($sector['tower_id']) ? site_find((int)$sector['tower_id']) : null;
$ap    = !empty($sector['ap_device_id']) ? device_find((int)$sector['ap_device_id']) : null;

/* ============================================================== JSON poll */
if (($_GET['action'] ?? '') === 'poll') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $reply = function (array $payload) {
        echo json_encode($payload + ['ts' => time()]);
        exit;
    };
    if (!$ap) {
        $reply(['ok' => false, 'error' => 'No AP device linked to this sector. Set sector.ap_device_id first.']);
    }

    $vendor_map = [
        'ubiquiti' => ['airos_poll_device',    __DIR__ . '/../auth/vendors/airos.php'],
        'mikrotik' => ['routeros_poll_device', __DIR__ . '/../auth/vendors/routeros.php'],
        'cambium'  => ['cambium_poll_device',  __DIR__ . '/../auth/vendors/cambium.php'],
        'mimosa'   => ['mimosa_poll_device',   __DIR__ . '/../auth/vendors/mimosa.php'],
    ];
    $entry = $vendor_map[$ap['vendor']] ?? null;
    if (!$entry) {
        $reply(['ok' => false, 'error' => 'AP vendor "' . $ap['vendor'] . '" has no adapter.']);
    }
    [$vendor_fn, $vendor_file] = $entry;
    if (!function_exists($vendor_fn) && is_file($vendor_file)) require_once $vendor_file;
    if (!function_exists($vendor_fn)) {
        $reply(['ok' => false, 'error' => 'Vendor adapter file missing.']);
    }
    if (device_secret_key() === null) {
        $reply(['ok' => false, 'error' => 'No device_key configured.']);
    }
    $cred_rows = device_credentials_for((int)$ap['id']);
    if (!$cred_rows) {
        $reply(['ok' => false, 'error' => 'No saved credentials for AP "' . $ap['name'] . '".']);
    }
    $cred = device_credentials_unlock($cred_rows[0]);
    if (!$cred) {
        $reply(['ok' => false, 'error' => 'Could not decrypt credentials.']);
    }

    $started = microtime(true);
    try { $result = $vendor_fn($ap, $cred); }
    catch (Throwable $e) { $result = ['ok' => false, 'error' => $e->getMessage()]; }
    $elapsed_ms = (int)round((microtime(true) - $started) * 1000);

    if (empty($result['ok'])) {
        $reply(['ok' => false, 'error' => $result['error'] ?? 'AP poll failed.', 'ms' => $elapsed_ms]);
    }

    $stations = $result['links']  ?? [];
    $rf_env   = $result['rf_env'] ?? [];
    $dev      = $result['device'] ?? [];

    /* RF env summary — strongest 5 entries sorted by RSSI desc. The
       adapter returns one row per (freq_mhz, ssid) pair; we don't
       de-dupe here, the operator wants to see them all. */
    usort($rf_env, function ($a, $b) {
        return ((int)($b['rssi_dbm'] ?? -120)) - ((int)($a['rssi_dbm'] ?? -120));
    });
    $rf_top = array_slice($rf_env, 0, 5);

    $reply([
        'ok'             => true,
        'ap_status'      => $dev['status']         ?? 'unknown',
        'uptime_seconds' => $dev['uptime_seconds'] ?? null,
        'firmware'       => $dev['firmware']       ?? null,
        'cpu_pct'        => $dev['cpu_pct']        ?? null,
        'mem_pct'        => $dev['mem_pct']        ?? null,
        'frequency_mhz'  => isset($stations[0]['frequency_mhz']) ? (int)$stations[0]['frequency_mhz'] : null,
        'channel_width'  => isset($stations[0]['channel_width_mhz']) ? (int)$stations[0]['channel_width_mhz'] : null,
        'tx_power_dbm'   => isset($stations[0]['tx_power_dbm_local']) ? (int)$stations[0]['tx_power_dbm_local'] : null,
        'station_count'  => count($stations),
        'stations'       => array_map(function ($s) {
            return [
                'mac'    => $s['station_mac']      ?? null,
                'signal' => $s['signal_local_dbm'] ?? null,
                'snr'    => $s['snr_local_db']     ?? null,
                'tx'     => $s['tx_rate_mbps']     ?? null,
                'rx'     => $s['rx_rate_mbps']     ?? null,
            ];
        }, $stations),
        'rf_top'         => $rf_top,
        'configured'     => [
            'frequency_mhz' => isset($sector['frequency_mhz']) ? (int)$sector['frequency_mhz'] : null,
            'channel_width' => isset($sector['channel_width_mhz']) ? (int)$sector['channel_width_mhz'] : null,
            'azimuth_deg'   => isset($sector['azimuth_deg']) ? (int)$sector['azimuth_deg'] : null,
            'beamwidth_deg' => isset($sector['beamwidth_deg']) ? (int)$sector['beamwidth_deg'] : null,
            'band'          => $sector['band'] ?? null,
        ],
        'ms'             => $elapsed_ms,
    ]);
}

/* ============================================================== HTML page */
$page_label = $sector['name'] . ($tower ? ' · ' . $tower['name'] : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <meta name="theme-color" content="#0a0e1a">
  <title>Commission · <?= htmlspecialchars($page_label) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('/assets/css/align.css')) ?>">
  <style>
    .sc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    @media (min-width: 700px) { .sc-grid { grid-template-columns: repeat(4, 1fr); } }
    .sc-tile {
      background: var(--panel); border: 1px solid var(--line);
      border-radius: 12px; padding: 12px; text-align: center;
    }
    .sc-tile .al-label { font-size: 10px; }
    .sc-tile-big { font-size: 32px; font-weight: 700; line-height: 1; margin-top: 6px;
                   font-variant-numeric: tabular-nums; }
    .sc-pill-ok   { color: var(--good); }
    .sc-pill-warn { color: var(--warn); }
    .sc-pill-bad  { color: var(--bad);  }
    .sc-stations, .sc-rf {
      background: var(--panel); border: 1px solid var(--line);
      border-radius: 12px; padding: 12px;
    }
    .sc-stations h3, .sc-rf h3 {
      font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase;
      color: var(--muted); margin: 0 0 8px;
    }
    .sc-stations ul, .sc-rf ul { list-style: none; padding: 0; margin: 0; }
    .sc-stations li, .sc-rf li {
      padding: 6px 0; font-size: 13px; font-variant-numeric: tabular-nums;
      border-bottom: 1px solid var(--line);
      display: flex; gap: 12px; align-items: baseline;
    }
    .sc-stations li:last-child, .sc-rf li:last-child { border-bottom: 0; }
    .sc-mac { font-family: ui-monospace, SF Mono, Menlo, monospace; font-size: 11px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  </style>
</head>
<body>
  <header class="al-bar">
    <a class="al-back" href="/admin/sector-view.php?id=<?= $id ?>" title="Back to sector">←</a>
    <div class="al-titles">
      <h1>Commission · <?= htmlspecialchars($sector['name']) ?></h1>
      <small><?= htmlspecialchars($tower['name'] ?? '—') ?> · <?= htmlspecialchars($ap ? $ap['name'] : 'no AP linked') ?></small>
    </div>
  </header>

  <main class="al-main">
    <div class="sc-grid">
      <div class="sc-tile">
        <div class="al-label">AP</div>
        <div class="sc-tile-big" id="sc-status">—</div>
      </div>
      <div class="sc-tile">
        <div class="al-label">Stations</div>
        <div class="sc-tile-big" id="sc-station-count">—</div>
      </div>
      <div class="sc-tile">
        <div class="al-label">Freq</div>
        <div class="sc-tile-big" id="sc-freq">—</div>
        <small class="muted">vs cfg <span id="sc-freq-cfg">—</span></small>
      </div>
      <div class="sc-tile">
        <div class="al-label">Width</div>
        <div class="sc-tile-big" id="sc-width">—</div>
        <small class="muted">vs cfg <span id="sc-width-cfg">—</span></small>
      </div>
    </div>

    <div class="sc-grid" style="margin-top:6px;">
      <div class="sc-tile">
        <div class="al-label">CPU</div>
        <div class="sc-tile-big" id="sc-cpu">—</div>
        <small class="muted">%</small>
      </div>
      <div class="sc-tile">
        <div class="al-label">Mem</div>
        <div class="sc-tile-big" id="sc-mem">—</div>
        <small class="muted">%</small>
      </div>
      <div class="sc-tile">
        <div class="al-label">Uptime</div>
        <div class="sc-tile-big" id="sc-uptime">—</div>
      </div>
      <div class="sc-tile">
        <div class="al-label">TX power</div>
        <div class="sc-tile-big" id="sc-tx">—</div>
        <small class="muted">dBm</small>
      </div>
    </div>

    <section class="sc-stations">
      <h3>Connected stations (<span id="sc-stations-count">0</span>)</h3>
      <ul id="sc-stations-list"><li class="muted">no data yet</li></ul>
    </section>

    <section class="sc-rf">
      <h3>Top 5 RF neighbours</h3>
      <ul id="sc-rf-list"><li class="muted">no scan yet</li></ul>
    </section>

    <section class="al-meta">
      <div><span class="al-meta-k">Configured azimuth</span><span class="al-meta-v"><?= isset($sector['azimuth_deg']) ? (int)$sector['azimuth_deg'] . '°' : '—' ?></span></div>
      <div><span class="al-meta-k">Configured beamwidth</span><span class="al-meta-v"><?= isset($sector['beamwidth_deg']) ? (int)$sector['beamwidth_deg'] . '°' : '—' ?></span></div>
      <div><span class="al-meta-k">Band</span><span class="al-meta-v"><?= htmlspecialchars($sector['band'] ?? '—') ?></span></div>
      <div><span class="al-meta-k">Max clients</span><span class="al-meta-v"><?= isset($sector['max_clients']) ? (int)$sector['max_clients'] : '—' ?></span></div>
    </section>

    <section class="al-status-row">
      <span id="al-status-dot" class="al-dot"></span>
      <span id="al-status-text">starting…</span>
      <span class="al-status-spacer"></span>
      <span id="al-status-ms" class="al-muted"></span>
    </section>
  </main>

  <script>
    window.SC_CONFIG = {
      pollUrl:  '/admin/sector-commission.php?action=poll&id=<?= $id ?>',
      interval: 3500,
    };
  </script>
  <script src="<?= htmlspecialchars(asset_url('/assets/js/sector-commission.js')) ?>" defer></script>
</body>
</html>
