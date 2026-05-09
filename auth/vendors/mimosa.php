<?php
/**
 * Mimosa B5 / C5 adapter.
 *
 * Mimosa exposes a JSON-over-HTTPS API at /cgi-bin/luci/api/<...> after
 * a session login. The newer cloud-managed boxes also have a Mimosa
 * Cloud REST API; we use the local box API by default and only call
 * the cloud API if device_credentials.api_token is set.
 *
 *   mimosa_poll_device($device, $cred)
 *   mimosa_apply_config($device, $cred, $payload)
 *   mimosa_revert_config($device, $cred, $snapshot)
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

const MIMOSA_TIMEOUT_S = 10;

function _mimosa_curl(string $url, array $opts, string $jar, bool $verify_tls = false) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => MIMOSA_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'WifiberPoller/1.0',
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_SSL_VERIFYPEER => $verify_tls,
        CURLOPT_SSL_VERIFYHOST => $verify_tls ? 2 : 0,
    ] + $opts);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'http' => (int)($info['http_code'] ?? 0), 'err' => $err];
}

function _mimosa_login(array $device, array $cred): array {
    $jar  = tempnam(sys_get_temp_dir(), 'mimosa_');
    $base = 'https://' . (string)$device['mgmt_ip'];
    $verify = !empty($cred['verify_tls']);

    $r = _mimosa_curl($base . '/cgi-bin/luci/login', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => (string)$cred['username'],
            'password' => (string)$cred['password'],
        ]),
    ], $jar, $verify);
    if ($r['http'] !== 200 && $r['http'] !== 302) {
        @unlink($jar);
        return ['ok' => false, 'error' => 'login: http ' . $r['http']];
    }
    return ['ok' => true, 'error' => '', 'jar' => $jar];
}

function _mimosa_get_json(string $base, string $path, string $jar, bool $verify): ?array {
    $r = _mimosa_curl($base . $path, [], $jar, $verify);
    if ($r['http'] !== 200) return null;
    $j = json_decode((string)$r['body'], true);
    return is_array($j) ? $j : null;
}

/**
 * Reboot a Mimosa box via the LuCI admin endpoint. The firmware is
 * OpenWrt-derived, so /cgi-bin/luci/admin/system/reboot is the
 * standard reboot path. Same connection-drop-as-success convention as
 * the other adapters.
 */
function mimosa_reboot_device(array $device, array $cred): array {
    $login = _mimosa_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];
    $jar    = $login['jar'];
    $base   = 'https://' . (string)$device['mgmt_ip'];
    $verify = !empty($cred['verify_tls']);

    $r = _mimosa_curl($base . '/cgi-bin/luci/admin/system/reboot', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => 'reboot=1',
        CURLOPT_TIMEOUT    => 6,
    ], $jar, $verify);
    @unlink($jar);

    if (in_array((int)$r['http'], [401, 403, 404, 405], true)) {
        return ['ok' => false, 'error' => 'reboot rejected: http ' . $r['http']];
    }
    return ['ok' => true, 'error' => ''];
}

function mimosa_poll_device(array $device, array $cred): array {
    $login = _mimosa_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];

    $jar  = $login['jar'];
    $base = 'https://' . (string)$device['mgmt_ip'];
    $verify = !empty($cred['verify_tls']);

    $sys   = _mimosa_get_json($base, '/cgi-bin/luci/api/system/status',     $jar, $verify) ?? [];
    $radio = _mimosa_get_json($base, '/cgi-bin/luci/api/wireless/status',   $jar, $verify) ?? [];
    $stas  = _mimosa_get_json($base, '/cgi-bin/luci/api/wireless/stations', $jar, $verify) ?? [];
    $eth   = _mimosa_get_json($base, '/cgi-bin/luci/api/network/ethernet',  $jar, $verify) ?? [];

    @unlink($jar);

    if (!$sys && !$radio) {
        return ['ok' => false, 'error' => 'all API endpoints failed (auth scope?)'];
    }

    $links = [];
    foreach (($stas['stations'] ?? $stas) as $s) {
        if (!is_array($s)) continue;
        $links[] = [
            'station_mac'         => strtoupper((string)($s['mac']     ?? '')),
            'ap_mac'              => strtoupper((string)($radio['mac'] ?? '')),
            'signal_local_dbm'    => isset($s['rssi'])    ? (int)$s['rssi']    : null,
            'signal_remote_dbm'   => isset($s['rssi_remote']) ? (int)$s['rssi_remote'] : null,
            'noise_local_dbm'     => isset($radio['noise']) ? (int)$radio['noise'] : null,
            'snr_local_db'        => isset($s['snr']) ? (int)$s['snr'] : null,
            'tx_rate_mbps'        => isset($s['tx_rate']) ? (float)$s['tx_rate'] : null,
            'rx_rate_mbps'        => isset($s['rx_rate']) ? (float)$s['rx_rate'] : null,
            'frequency_mhz'       => isset($radio['freq']) ? (int)$radio['freq'] : null,
            'channel_width_mhz'   => isset($radio['width']) ? (int)$radio['width'] : null,
            'tx_power_dbm_local'  => isset($radio['tx_power']) ? (int)$radio['tx_power'] : null,
            'wireless_mode'       => '802.11ac',
            'uptime_seconds'      => isset($s['uptime']) ? (int)$s['uptime'] : null,
            'tx_bytes'            => isset($s['tx_bytes']) ? (int)$s['tx_bytes'] : null,
            'rx_bytes'            => isset($s['rx_bytes']) ? (int)$s['rx_bytes'] : null,
        ];
    }

    $ethernet = [];
    foreach (($eth['interfaces'] ?? $eth) as $e) {
        if (!is_array($e)) continue;
        $ethernet = [
            'lan_port'        => (string)($e['name'] ?? 'eth0'),
            'link_speed_mbps' => isset($e['speed']) ? (int)$e['speed'] : null,
            'duplex'          => (string)($e['duplex'] ?? 'unknown'),
        ];
        break;
    }

    return [
        'ok'       => true,
        'error'    => '',
        'device'   => [
            'status'         => 'online',
            'uptime_seconds' => isset($sys['uptime']) ? (int)$sys['uptime'] : null,
            'cpu_pct'        => isset($sys['cpu_load']) ? (float)$sys['cpu_load'] : null,
            'mem_pct'        => isset($sys['mem_used_pct']) ? (float)$sys['mem_used_pct'] : null,
            'signal_dbm'     => isset($radio['signal']) ? (int)$radio['signal'] : null,
            'noise_dbm'      => isset($radio['noise'])  ? (int)$radio['noise']  : null,
            'tx_rate_mbps'   => isset($radio['tx_rate']) ? (float)$radio['tx_rate'] : null,
            'rx_rate_mbps'   => isset($radio['rx_rate']) ? (float)$radio['rx_rate'] : null,
            'firmware'       => (string)($sys['firmware'] ?? ''),
        ],
        'links'    => $links,
        'rf_env'   => [],
        'ethernet' => $ethernet,
        'firmware' => (string)($sys['firmware'] ?? ''),
        'mac'      => strtoupper((string)($radio['mac'] ?? '')),
        'serial'   => (string)($sys['serial'] ?? ''),
        'model'    => (string)($sys['model']  ?? ''),
    ];
}

function mimosa_snapshot_config(array $device, array $cred): array {
    $login = _mimosa_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];
    $jar = $login['jar'];
    $base = 'https://' . (string)$device['mgmt_ip'];
    $verify = !empty($cred['verify_tls']);
    $cfg = _mimosa_get_json($base, '/cgi-bin/luci/api/wireless/config', $jar, $verify);
    @unlink($jar);
    return $cfg !== null
        ? ['ok' => true, 'error' => '', 'config' => $cfg]
        : ['ok' => false, 'error' => 'snapshot: GET /api/wireless/config failed'];
}

function mimosa_apply_config(array $device, array $cred, array $payload): array {
    $login = _mimosa_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];
    $jar = $login['jar'];
    $base = 'https://' . (string)$device['mgmt_ip'];
    $verify = !empty($cred['verify_tls']);

    $body = [];
    if (isset($payload['frequency_mhz']))     $body['freq']     = (int)$payload['frequency_mhz'];
    if (isset($payload['channel_width_mhz'])) $body['width']    = (int)$payload['channel_width_mhz'];
    if (isset($payload['tx_power_dbm']))      $body['tx_power'] = (int)$payload['tx_power_dbm'];
    if (isset($payload['ssid']))              $body['ssid']     = (string)$payload['ssid'];

    $r = _mimosa_curl($base . '/cgi-bin/luci/api/wireless/config', [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS    => json_encode($body),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
    ], $jar, $verify);
    @unlink($jar);
    return ($r['http'] >= 200 && $r['http'] < 300)
        ? ['ok' => true,  'error' => '']
        : ['ok' => false, 'error' => 'apply: http ' . $r['http']];
}

function mimosa_revert_config(array $device, array $cred, array $snapshot): array {
    if (empty($snapshot['config'])) {
        return ['ok' => false, 'error' => 'no snapshot'];
    }
    return mimosa_apply_config($device, $cred, $snapshot['config']);
}
