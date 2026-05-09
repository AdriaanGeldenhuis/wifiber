<?php
/**
 * PTP backbone alignment meter — two-ended signal display for aiming a
 * site-to-site radio link. Same vendor-adapter trick as
 * /admin/align.php (poll the AP in-process every couple of seconds),
 * but renders both directions side-by-side: the polled side's view of
 * the far end (signal_local_dbm) and the far end's reported view back
 * (signal_remote_dbm). Two techs on opposite roofs each watch their
 * own column.
 *
 * Two roles in one file:
 *   GET ?id=X                   → renders the alignment UI
 *   GET ?action=poll&id=X       → JSON snapshot {near, far, ...}
 *
 * Auth: admin_can_write (super_admin / admin / technician).
 */
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';

require_admin_ip();
$user = require_role(acl_staff_roles(), '/admin/login.php');
if (!admin_can_write()) {
    http_response_code(403);
    die('Alignment requires admin / super_admin / technician.');
}

$id        = (int)($_GET['id'] ?? 0);
$site_link = $id ? site_link_find($id) : null;
if (!$site_link) {
    http_response_code(404);
    die('Backbone link not found.');
}
$from_site = site_find((int)$site_link['from_site_id']);
$to_site   = site_find((int)$site_link['to_site_id']);
if (!$from_site || !$to_site) {
    http_response_code(404);
    die('One or both endpoint sites are missing.');
}

/* Find the wireless_links row bridging these two sites. The poll
   endpoint hits the AP-side device and pulls both directions out of
   the station entry. */
$wl_stmt = pdo()->prepare(
    "SELECT wl.*,
            ap.id AS ap_id, ap.name AS ap_name, ap.site_id AS ap_site,
            ap.vendor AS ap_vendor, ap.mac AS ap_mac,
            cpe.id AS cpe_id, cpe.name AS cpe_name, cpe.site_id AS cpe_site,
            cpe.vendor AS cpe_vendor, cpe.mac AS cpe_mac
       FROM wireless_links wl
       JOIN devices ap       ON ap.id = wl.ap_device_id
       LEFT JOIN devices cpe ON cpe.id = wl.cpe_device_id
      WHERE (ap.site_id = ? AND cpe.site_id = ?)
         OR (ap.site_id = ? AND cpe.site_id = ?)
      ORDER BY (wl.health_score IS NULL), wl.health_score DESC
      LIMIT 1"
);
$wl_stmt->execute([
    (int)$site_link['from_site_id'], (int)$site_link['to_site_id'],
    (int)$site_link['to_site_id'],   (int)$site_link['from_site_id'],
]);
$wl = $wl_stmt->fetch() ?: null;

/* Map "near / far" labels onto the from / to sites so the column the
   tech is looking at lines up with the building they're standing in.
   The polled side is the wireless_link's ap_device — its `site_id`
   tells us which endpoint that is. */
$near_site = $from_site;
$far_site  = $to_site;
if ($wl && (int)$wl['ap_site'] === (int)$site_link['to_site_id']) {
    // The ap_device is at the to-site, so flip labels.
    $near_site = $to_site;
    $far_site  = $from_site;
}

/* ============================================================== JSON poll */
if (($_GET['action'] ?? '') === 'poll') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $reply = function (array $payload) {
        echo json_encode($payload + ['ts' => time()]);
        exit;
    };

    if (!$wl) {
        $reply(['ok' => false, 'error' => 'No wireless_links row bridges these two sites yet — once the polling worker sees the radios associate, alignment will work here.']);
    }

    $ap = device_find((int)$wl['ap_device_id']);
    if (!$ap) {
        $reply(['ok' => false, 'error' => 'AP-side device record missing.']);
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
    if (!function_exists($vendor_fn) && is_file($vendor_file)) {
        require_once $vendor_file;
    }
    if (!function_exists($vendor_fn)) {
        $reply(['ok' => false, 'error' => 'Vendor adapter file missing.']);
    }
    if (device_secret_key() === null) {
        $reply(['ok' => false, 'error' => 'No device_key configured — cannot decrypt credentials.']);
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
    try {
        $result = $vendor_fn($ap, $cred);
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => $e->getMessage()];
    }
    $elapsed_ms = (int)round((microtime(true) - $started) * 1000);

    if (empty($result['ok'])) {
        $reply(['ok' => false, 'error' => $result['error'] ?? 'AP poll failed.', 'ms' => $elapsed_ms]);
    }

    /* Match the station entry on either the saved station_mac or the
       far-side device's MAC. PTP links normally have only one station
       on the master, so a single match is the common case. */
    $target = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)($wl['station_mac'] ?? '')));
    if ($target === '' && !empty($wl['cpe_mac'])) {
        $target = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)$wl['cpe_mac']));
    }
    $stations = $result['links'] ?? [];
    $hit = null;
    foreach ($stations as $s) {
        $mac = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)($s['station_mac'] ?? '')));
        if ($mac !== '' && $target !== '' && $mac === $target) { $hit = $s; break; }
    }
    if (!$hit && count($stations) === 1) {
        // PTP master with a single client — that's the one we want.
        $hit = $stations[0];
    }

    if (!$hit) {
        $reply([
            'ok' => true,
            'station_found' => false,
            'all_stations'  => array_map(function ($s) {
                return [
                    'mac'    => $s['station_mac'] ?? null,
                    'signal' => $s['signal_local_dbm'] ?? null,
                    'snr'    => $s['snr_local_db']    ?? null,
                ];
            }, $stations),
            'ms' => $elapsed_ms,
        ]);
    }

    $reply([
        'ok'             => true,
        'station_found'  => true,
        'near'           => [
            'site'       => $near_site['name'],
            'signal_dbm' => $hit['signal_local_dbm']  ?? null,
            'noise_dbm'  => $hit['noise_local_dbm']   ?? null,
            'snr_db'     => $hit['snr_local_db']      ?? null,
            'tx_mbps'    => $hit['tx_rate_mbps']      ?? null,
        ],
        'far'            => [
            'site'       => $far_site['name'],
            'signal_dbm' => $hit['signal_remote_dbm'] ?? null,
            'noise_dbm'  => $hit['noise_remote_dbm']  ?? null,
            'snr_db'     => $hit['snr_remote_db']     ?? null,
            'tx_mbps'    => $hit['rx_rate_mbps']      ?? null,
        ],
        'ccq_pct'        => $hit['ccq_pct']           ?? null,
        'freq_mhz'       => $hit['frequency_mhz']     ?? null,
        'channel_width'  => $hit['channel_width_mhz'] ?? null,
        'station_mac'    => $hit['station_mac']       ?? null,
        'ap_name'        => $ap['name'],
        'ms'             => $elapsed_ms,
    ]);
}

/* ============================================================== HTML page */
$title = ($site_link['label'] ?: ($from_site['name'] . ' ↔ ' . $to_site['name']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <meta name="theme-color" content="#0a0e1a">
  <title>Align · <?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('/assets/css/align.css')) ?>">
  <style>
    .al-ptp { display: grid; grid-template-columns: 1fr; gap: 14px; }
    @media (min-width: 700px) { .al-ptp { grid-template-columns: 1fr 1fr; } }
    .al-ptp .al-stat { padding: 14px; }
    .al-ptp .al-big  { font-size: 64px; }
    .al-ptp .al-side-title { font-size: 12px; letter-spacing:1px; text-transform:uppercase; color:#7a8499; margin-bottom:4px; }
    .al-ptp .al-side-name { font-size: 16px; font-weight: 600; margin-bottom: 8px; }
  </style>
</head>
<body>
  <header class="al-bar">
    <a class="al-back" href="/admin/site-link-view.php?id=<?= $id ?>" title="Back to backbone link">←</a>
    <div class="al-titles">
      <h1><?= htmlspecialchars($title) ?></h1>
      <small>PTP alignment · <?= htmlspecialchars($wl ? ($wl['ap_name'] . ' ↔ ' . ($wl['cpe_name'] ?: '?')) : 'no radio leg yet') ?></small>
    </div>
    <button id="al-beep-toggle" class="al-btn-ghost" type="button">🔇</button>
  </header>

  <main class="al-main">
    <div class="al-ptp">
      <section class="al-stat">
        <div class="al-side-title">Near end</div>
        <div class="al-side-name"><?= htmlspecialchars($near_site['name']) ?></div>
        <div class="al-label">Signal</div>
        <div class="al-big" id="al-near-sig">—</div>
        <div class="al-unit">dBm</div>
        <div class="al-bar"><div class="al-bar-fill" id="al-near-fill"></div></div>
        <div class="al-peak">SNR <span id="al-near-snr">—</span> dB · peak <span id="al-near-peak">—</span> dBm</div>
      </section>
      <section class="al-stat">
        <div class="al-side-title">Far end</div>
        <div class="al-side-name"><?= htmlspecialchars($far_site['name']) ?></div>
        <div class="al-label">Signal</div>
        <div class="al-big" id="al-far-sig">—</div>
        <div class="al-unit">dBm</div>
        <div class="al-bar"><div class="al-bar-fill" id="al-far-fill"></div></div>
        <div class="al-peak">SNR <span id="al-far-snr">—</span> dB · peak <span id="al-far-peak">—</span> dBm</div>
      </section>
    </div>

    <section class="al-meta">
      <div><span class="al-meta-k">CCQ</span><span class="al-meta-v" id="al-ccq">—</span></div>
      <div><span class="al-meta-k">Freq</span><span class="al-meta-v"><span id="al-freq">—</span> MHz</span></div>
      <div><span class="al-meta-k">Width</span><span class="al-meta-v"><span id="al-width">—</span> MHz</span></div>
      <div><span class="al-meta-k">Far MAC</span><span class="al-meta-v"><code id="al-mac">—</code></span></div>
    </section>

    <section class="al-status-row">
      <span id="al-status-dot" class="al-dot"></span>
      <span id="al-status-text">starting…</span>
      <span class="al-status-spacer"></span>
      <span id="al-status-ms" class="al-muted"></span>
    </section>
  </main>

  <script>
    window.AL_CONFIG = {
      pollUrl:  '/admin/site-link-align.php?action=poll&id=<?= $id ?>',
      interval: 2500,
      mode:     'ptp',
    };
  </script>
  <script src="<?= htmlspecialchars(asset_url('/assets/js/site-link-align.js')) ?>" defer></script>
</body>
</html>
