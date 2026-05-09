<?php
/**
 * MikroTik RouterOS adapter.
 *
 * Talks the RouterOS REST API on v7+ (HTTPS, JSON). For v6 we'd need to
 * fall through to the binary API or SSH — left as a TODO; v6 boxes
 * should set device_credentials.scheme='ssh' for now and we'll cover
 * them in Phase 4 polish.
 *
 *   routeros_poll_device($device, $cred) → telemetry
 *   routeros_apply_config($device, $cred, $payload) → push wireless config
 *   routeros_revert_config(...) → re-PATCH from snapshot
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

const ROS_TIMEOUT_S  = 10;

function _ros_curl(string $url, string $method, ?array $body, array $cred) {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ROS_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'WifiberPoller/1.0',
        CURLOPT_USERPWD        => $cred['username'] . ':' . $cred['password'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => !empty($cred['verify_tls']),
        CURLOPT_SSL_VERIFYHOST => !empty($cred['verify_tls']) ? 2 : 0,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body_resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body_resp, 'http' => (int)($info['http_code'] ?? 0), 'err' => $err];
}

function _ros_base(array $device, array $cred): string {
    $port = $cred['port'] ?? $device['mgmt_port'] ?? null;
    return 'https://' . (string)$device['mgmt_ip'] . ($port ? ':' . (int)$port : '') . '/rest';
}

function _ros_get(array $device, array $cred, string $path): ?array {
    $r = _ros_curl(_ros_base($device, $cred) . $path, 'GET', null, $cred);
    if ($r['http'] !== 200) return null;
    $j = json_decode((string)$r['body'], true);
    return is_array($j) ? $j : null;
}

function routeros_poll_device(array $device, array $cred): array {
    $sysres   = _ros_get($device, $cred, '/system/resource');
    $sysid    = _ros_get($device, $cred, '/system/identity');
    $wifaces  = _ros_get($device, $cred, '/interface/wireless') ?? [];
    $regtable = _ros_get($device, $cred, '/interface/wireless/registration-table') ?? [];
    $eth      = _ros_get($device, $cred, '/interface/ethernet') ?? [];

    if ($sysres === null) {
        return ['ok' => false, 'error' => 'GET /system/resource failed (auth or REST disabled)'];
    }
    $sysres = $sysres[0] ?? $sysres;

    $cpu = isset($sysres['cpu-load']) ? (float)$sysres['cpu-load'] : null;
    $mem_total = (int)($sysres['total-memory'] ?? 0);
    $mem_free  = (int)($sysres['free-memory']  ?? 0);
    $mem_pct   = ($mem_total > 0) ? round(100 - 100 * $mem_free / $mem_total, 2) : null;
    $uptime_s  = isset($sysres['uptime']) ? _ros_parse_uptime((string)$sysres['uptime']) : null;

    $wif = $wifaces[0] ?? [];
    $links = [];
    foreach ($regtable as $reg) {
        if (!is_array($reg)) continue;
        $links[] = [
            'station_mac'         => strtoupper((string)($reg['mac-address'] ?? '')),
            'ap_mac'              => strtoupper((string)($wif['mac-address'] ?? '')),
            'signal_local_dbm'    => isset($reg['signal-strength']) ? (int)$reg['signal-strength'] : null,
            'noise_local_dbm'     => isset($reg['noise-floor'])     ? (int)$reg['noise-floor']     : null,
            'snr_local_db'        => isset($reg['signal-to-noise']) ? (int)$reg['signal-to-noise'] : null,
            'ccq_pct'             => isset($reg['tx-ccq']) ? (float)$reg['tx-ccq'] : null,
            'tx_rate_mbps'        => isset($reg['tx-rate']) ? _ros_parse_rate((string)$reg['tx-rate']) : null,
            'rx_rate_mbps'        => isset($reg['rx-rate']) ? _ros_parse_rate((string)$reg['rx-rate']) : null,
            'tx_power_dbm_local'  => isset($wif['tx-power']) ? (int)$wif['tx-power'] : null,
            'frequency_mhz'       => isset($wif['frequency']) ? (int)$wif['frequency'] : null,
            'channel_width_mhz'   => isset($wif['band']) ? _ros_parse_width((string)$wif['band']) : null,
            'wireless_mode'       => (string)($wif['wireless-protocol'] ?? '802.11ac'),
            'uptime_seconds'      => isset($reg['uptime']) ? _ros_parse_uptime((string)$reg['uptime']) : null,
            'tx_bytes'            => isset($reg['tx-bytes']) ? (int)$reg['tx-bytes'] : null,
            'rx_bytes'            => isset($reg['rx-bytes']) ? (int)$reg['rx-bytes'] : null,
            'remote_name'         => (string)($reg['comment'] ?? ''),
        ];
    }

    $ethernet = [];
    foreach ($eth as $e) {
        if (!is_array($e)) continue;
        $name = (string)($e['name'] ?? '');
        if ($name === '') continue;
        $ethernet = [
            'lan_port'        => $name,
            'link_speed_mbps' => isset($e['speed']) ? _ros_parse_rate((string)$e['speed']) : null,
            'duplex'          => (string)($e['full-duplex'] ?? 'unknown'),
        ];
        break;
    }

    return [
        'ok'       => true,
        'error'    => '',
        'device'   => [
            'status'         => 'online',
            'uptime_seconds' => $uptime_s,
            'cpu_pct'        => $cpu,
            'mem_pct'        => $mem_pct,
            'signal_dbm'     => null,
            'noise_dbm'      => null,
            'tx_rate_mbps'   => null,
            'rx_rate_mbps'   => null,
            'firmware'       => (string)($sysres['version'] ?? ''),
        ],
        'links'    => $links,
        'rf_env'   => [], // RouterOS has /interface/wireless/scan but it's disruptive — skip
        'ethernet' => $ethernet,
        'firmware' => (string)($sysres['version'] ?? ''),
        'mac'      => strtoupper((string)($wif['mac-address'] ?? '')),
        'serial'   => (string)($sysres['serial-number'] ?? ''),
        'model'    => (string)($sysres['board-name']    ?? ''),
    ];
}

/**
 * Reboot a MikroTik via the REST API. Same caveat as AirOS — the
 * device closes the connection as it reboots, so we accept that as
 * success.
 */
function routeros_reboot_device(array $device, array $cred): array {
    $url = _ros_base($device, $cred) . '/system/reboot';
    $r = _ros_curl($url, 'POST', null, $cred);
    if (in_array((int)$r['http'], [401, 403], true)) {
        return ['ok' => false, 'error' => 'reboot rejected: http ' . $r['http']];
    }
    return ['ok' => true, 'error' => ''];
}

function routeros_snapshot_config(array $device, array $cred): array {
    $w = _ros_get($device, $cred, '/interface/wireless');
    if ($w === null) return ['ok' => false, 'error' => 'snapshot: /interface/wireless failed'];
    return ['ok' => true, 'error' => '', 'wireless' => $w];
}

function routeros_apply_config(array $device, array $cred, array $payload): array {
    $w = _ros_get($device, $cred, '/interface/wireless');
    if (!is_array($w) || !isset($w[0])) {
        return ['ok' => false, 'error' => 'no wireless interface found'];
    }
    $iface_id = (string)$w[0]['.id'];

    $body = [];
    if (isset($payload['frequency_mhz']))     $body['frequency']    = (int)$payload['frequency_mhz'];
    if (isset($payload['channel_width_mhz'])) $body['band']         = _ros_band_for($payload['channel_width_mhz']);
    if (isset($payload['tx_power_dbm']))      $body['tx-power']     = (int)$payload['tx_power_dbm'];
    if (isset($payload['ssid']))              $body['ssid']         = (string)$payload['ssid'];
    if (!$body) return ['ok' => true, 'error' => '']; // nothing to do

    $r = _ros_curl(_ros_base($device, $cred) . '/interface/wireless/' . urlencode($iface_id),
        'PATCH', $body, $cred);
    return $r['http'] === 200
        ? ['ok' => true,  'error' => '']
        : ['ok' => false, 'error' => 'apply: http ' . $r['http']];
}

function routeros_revert_config(array $device, array $cred, array $snapshot): array {
    if (empty($snapshot['wireless'][0])) {
        return ['ok' => false, 'error' => 'no snapshot'];
    }
    $w = $snapshot['wireless'][0];
    $iface_id = (string)$w['.id'];
    // Push back the same writable fields we'd push forward.
    $body = array_intersect_key($w, array_flip(['frequency','band','tx-power','ssid','security-profile']));
    $r = _ros_curl(_ros_base($device, $cred) . '/interface/wireless/' . urlencode($iface_id),
        'PATCH', $body, $cred);
    return $r['http'] === 200
        ? ['ok' => true,  'error' => '']
        : ['ok' => false, 'error' => 'revert: http ' . $r['http']];
}

function _ros_parse_uptime(string $u): int {
    // RouterOS uptime: "1w2d3h4m5s"
    if (preg_match_all('/(\d+)([wdhms])/', $u, $m, PREG_SET_ORDER) === 0) return 0;
    $secs = 0;
    foreach ($m as $part) {
        $n = (int)$part[1];
        switch ($part[2]) {
            case 'w': $secs += $n * 604800; break;
            case 'd': $secs += $n * 86400;  break;
            case 'h': $secs += $n * 3600;   break;
            case 'm': $secs += $n * 60;     break;
            case 's': $secs += $n;          break;
        }
    }
    return $secs;
}

function _ros_parse_rate(string $r): ?float {
    // "866.7Mbps", "1Gbps", "100M-baseT-full", "1000000000"
    if (preg_match('/([0-9.]+)\s*([KMG])/i', $r, $m)) {
        $n = (float)$m[1];
        $u = strtoupper($m[2]);
        if ($u === 'G') return $n * 1000;
        if ($u === 'M') return $n;
        if ($u === 'K') return $n / 1000;
    }
    if (is_numeric($r)) return (float)$r / 1e6;
    return null;
}

function _ros_parse_width(string $band): ?int {
    if (preg_match('/(\d+)mhz/i', $band, $m)) return (int)$m[1];
    return null;
}

function _ros_band_for(int $width): string {
    return '5ghz-' . match (true) {
        $width >= 80 => '80mhz',
        $width >= 40 => '40mhz',
        default      => '20mhz',
    };
}
