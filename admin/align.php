<?php
/**
 * Antenna alignment meter — mobile-first page for an installer aiming a
 * customer CPE at the AP. Polls the AP's vendor adapter in-process every
 * couple of seconds, finds the station entry whose MAC matches the
 * customer's CPE, and pipes the signal/SNR/CCQ back to the page so the
 * tech sees instant feedback as they swing the dish.
 *
 * Two roles in one file:
 *   GET ?customer_id=X                  → renders the alignment UI
 *   GET ?action=poll&customer_id=X      → JSON snapshot {signal_dbm,
 *                                          snr_db, ccq_pct, …}
 *
 * No new role required — uses the existing technician+ write capability
 * (admin_can_write).
 */
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sectors.php';

require_admin_ip();
$user = require_role(acl_staff_roles(), '/admin/login.php');
if (!admin_can_write()) {
    http_response_code(403);
    die('Alignment requires admin / super_admin / technician.');
}

$customer_id = (int)($_GET['customer_id'] ?? 0);
$client = $customer_id ? find_user_by_id($customer_id) : null;
if (!$client || ($client['role'] ?? '') !== 'client') {
    http_response_code(404);
    die('Customer not found.');
}

/* Resolve the AP we'll poll, plus every MAC we'll accept as "this is
   the customer's CPE" — we try equipment_mac on the user, then any
   station_mac on existing wireless_links rows, then fall back to the
   strongest signal during install when nothing's saved yet. */
$ap = null;
$target_macs = [];

if (!empty($client['sector_id'])) {
    $sector = sector_find((int)$client['sector_id']);
    if ($sector && !empty($sector['ap_device_id'])) {
        $ap = device_find((int)$sector['ap_device_id']);
    }
}
if (!empty($client['equipment_mac'])) {
    $m = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)$client['equipment_mac']));
    if (strlen($m) === 12) $target_macs[] = $m;
}
$existing_links = wireless_links_all(['customer_id' => $customer_id]);
foreach ($existing_links as $l) {
    if (!empty($l['station_mac'])) {
        $m = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)$l['station_mac']));
        if (strlen($m) === 12) $target_macs[] = $m;
    }
    if (!$ap && !empty($l['ap_device_id'])) {
        $ap = device_find((int)$l['ap_device_id']);
    }
}
$target_macs = array_values(array_unique($target_macs));

/* ============================================================== JSON poll */
if (($_GET['action'] ?? '') === 'poll') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $reply = function (array $payload) {
        echo json_encode($payload + ['ts' => time()]);
        exit;
    };

    if (!$ap) {
        $reply(['ok' => false, 'error' => 'Customer has no sector / AP linked yet.']);
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
        $reply(['ok' => false, 'error' => 'No device_key configured — cannot decrypt AP credentials.']);
    }

    $cred_rows = device_credentials_for((int)$ap['id']);
    if (!$cred_rows) {
        $reply(['ok' => false, 'error' => 'No saved credentials for AP "' . $ap['name'] . '".']);
    }
    $cred = device_credentials_unlock($cred_rows[0]);
    if (!$cred) {
        $reply(['ok' => false, 'error' => 'Could not decrypt AP credentials.']);
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

    $stations = $result['links'] ?? [];
    $hit = null;
    foreach ($stations as $s) {
        $mac = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)($s['station_mac'] ?? '')));
        if ($mac !== '' && in_array($mac, $target_macs, true)) {
            $hit = $s;
            break;
        }
    }
    /* No saved MAC yet — pick the strongest unidentified station so the
       installer can still aim something. We surface this as a hint via
       `auto_pick`. */
    $auto_pick = false;
    if (!$hit && !$target_macs && $stations) {
        usort($stations, function ($a, $b) {
            return ((int)($b['signal_local_dbm'] ?? -120))
                 - ((int)($a['signal_local_dbm'] ?? -120));
        });
        $hit = $stations[0];
        $auto_pick = true;
    }

    $brief = array_map(function ($s) {
        return [
            'mac'    => $s['station_mac']        ?? null,
            'signal' => $s['signal_local_dbm']   ?? null,
            'snr'    => $s['snr_local_db']       ?? null,
        ];
    }, $stations);

    $reply([
        'ok'             => true,
        'station_found'  => $hit !== null,
        'auto_pick'      => $auto_pick,
        'station_mac'    => $hit['station_mac']        ?? null,
        'signal_dbm'     => $hit['signal_local_dbm']   ?? null,
        'noise_dbm'      => $hit['noise_local_dbm']    ?? null,
        'snr_db'         => $hit['snr_local_db']       ?? null,
        'ccq_pct'        => $hit['ccq_pct']            ?? null,
        'tx_mbps'        => $hit['tx_rate_mbps']       ?? null,
        'rx_mbps'        => $hit['rx_rate_mbps']       ?? null,
        'freq_mhz'       => $hit['frequency_mhz']      ?? null,
        'channel_width'  => $hit['channel_width_mhz']  ?? null,
        'all_stations'   => $brief,
        'targets'        => $target_macs,
        'ap_name'        => $ap['name'],
        'ms'             => $elapsed_ms,
    ]);
}

/* ============================================================== HTML page */
$customer_label = trim((string)($client['name'] ?? '') . ' ' . (string)($client['surname'] ?? ''))
                  ?: ($client['username'] ?? '#' . $customer_id);
$ap_label = $ap ? $ap['name'] . ' · ' . ucfirst($ap['vendor']) : 'No AP linked';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <meta name="theme-color" content="#0a0e1a">
  <title>Align · <?= htmlspecialchars($customer_label) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('/assets/css/align.css')) ?>">
</head>
<body>
  <header class="al-bar">
    <a class="al-back" href="/admin/client-view.php?id=<?= $customer_id ?>" title="Back to customer">←</a>
    <div class="al-titles">
      <h1><?= htmlspecialchars($customer_label) ?></h1>
      <small><?= htmlspecialchars($ap_label) ?></small>
    </div>
    <button id="al-beep-toggle" class="al-btn-ghost" type="button">🔇</button>
  </header>

  <main class="al-main">
    <section class="al-stat">
      <div class="al-label">Signal</div>
      <div class="al-big" id="al-signal">—</div>
      <div class="al-unit">dBm</div>
      <div class="al-bar"><div class="al-bar-fill" id="al-signal-fill"></div></div>
      <div class="al-peak">peak <span id="al-signal-peak">—</span> dBm</div>
    </section>

    <section class="al-stat">
      <div class="al-label">SNR</div>
      <div class="al-big" id="al-snr">—</div>
      <div class="al-unit">dB</div>
      <div class="al-bar"><div class="al-bar-fill" id="al-snr-fill"></div></div>
      <div class="al-peak">peak <span id="al-snr-peak">—</span> dB</div>
    </section>

    <section class="al-meta">
      <div><span class="al-meta-k">CCQ</span><span class="al-meta-v" id="al-ccq">—</span></div>
      <div><span class="al-meta-k">TX / RX</span><span class="al-meta-v"><span id="al-tx">—</span> / <span id="al-rx">—</span> Mbps</span></div>
      <div><span class="al-meta-k">Freq</span><span class="al-meta-v"><span id="al-freq">—</span> MHz</span></div>
      <div><span class="al-meta-k">CPE MAC</span><span class="al-meta-v"><code id="al-mac">—</code></span></div>
    </section>

    <section class="al-status-row">
      <span id="al-status-dot" class="al-dot"></span>
      <span id="al-status-text">starting…</span>
      <span class="al-status-spacer"></span>
      <span id="al-status-ms" class="al-muted"></span>
    </section>

    <details class="al-debug">
      <summary>All stations on AP (<span id="al-stations-count">0</span>)</summary>
      <ul id="al-stations"></ul>
    </details>
  </main>

  <script>
    window.AL_CONFIG = {
      pollUrl:  '/admin/align.php?action=poll&customer_id=<?= $customer_id ?>',
      interval: 2500,
      targets:  <?= json_encode($target_macs) ?>,
    };
  </script>
  <script src="<?= htmlspecialchars(asset_url('/assets/js/align.js')) ?>" defer></script>
</body>
</html>
