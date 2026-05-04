<?php
/**
 * Ubiquiti AirOS adapter — NanoBeam, PowerBeam, LiteBeam, Rocket, etc.
 *
 * AirOS exposes a JSON API at https://<ip>/status.cgi after a cookie
 * login at /login.cgi. This adapter:
 *
 *   airos_poll_device($device, $cred) → array of telemetry rows for the
 *     polling worker to write into device_health, link_health_samples,
 *     rf_environment_samples, ethernet_health, and wireless_links.
 *
 *   airos_apply_config($device, $cred, $payload) → push freq / width /
 *     TX power / SSID / security / WPA key. Calls /sockets.cgi (newer
 *     firmware) or /cfg.cgi + /system.cgi?reboot for older.
 *
 *   airos_revert_config($device, $cred, $snapshot) → re-apply a snapshot.
 *
 * All HTTP I/O goes through curl with a 10s timeout and a per-call
 * cookie jar. We never trust the radio's TLS cert by default — APs ship
 * with self-signed certs. Set device_credentials.verify_tls=1 to enforce.
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../wireless.php';

const AIROS_TIMEOUT_S        = 10;
const AIROS_USER_AGENT       = 'WifiberPoller/1.0';

function _airos_cookie_jar(): string {
    return tempnam(sys_get_temp_dir(), 'airos_');
}

function _airos_curl(string $url, array $opts, string $cookie_jar, bool $verify_tls = false) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => AIROS_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => AIROS_USER_AGENT,
        CURLOPT_COOKIEJAR      => $cookie_jar,
        CURLOPT_COOKIEFILE     => $cookie_jar,
        CURLOPT_SSL_VERIFYPEER => $verify_tls,
        CURLOPT_SSL_VERIFYHOST => $verify_tls ? 2 : 0,
    ] + $opts);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'http' => (int)($info['http_code'] ?? 0), 'err' => $err];
}

function airos_base_url(array $device, ?int $port = null): string {
    $ip   = (string)($device['mgmt_ip'] ?? '');
    $port = $port ?? ($device['mgmt_port'] ?? null);
    return 'https://' . $ip . ($port ? ':' . (int)$port : '');
}

/**
 * Log into the radio and retain the auth cookie in $cookie_jar. Returns
 * ['ok'=>bool, 'error'=>string, 'jar'=>string].
 */
function airos_login(array $device, array $cred): array {
    $jar  = _airos_cookie_jar();
    $base = airos_base_url($device, $cred['port'] ?? null);
    $verify = !empty($cred['verify_tls']);

    // Touch /login.cgi first to get the session cookie + AIROS_SESSIONID.
    $r1 = _airos_curl($base . '/login.cgi', [], $jar, $verify);
    if ($r1['err']) {
        @unlink($jar);
        return ['ok' => false, 'error' => 'connect: ' . $r1['err'], 'jar' => ''];
    }

    $r2 = _airos_curl($base . '/login.cgi', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'uri'      => '/index.cgi',
            'username' => (string)$cred['username'],
            'password' => (string)$cred['password'],
        ]),
    ], $jar, $verify);

    if ($r2['err'] || $r2['http'] >= 500) {
        @unlink($jar);
        return ['ok' => false, 'error' => 'login: ' . ($r2['err'] ?: 'http ' . $r2['http']), 'jar' => ''];
    }
    return ['ok' => true, 'error' => '', 'jar' => $jar];
}

function airos_logout(string $jar): void {
    if ($jar !== '' && is_file($jar)) @unlink($jar);
}

/**
 * Poll one AirOS radio. Returns:
 *   ['ok'=>bool, 'error'=>string, 'device'=>device_health row,
 *    'links'=>[link sample row, …], 'rf_env'=>[freq/rssi rows],
 *    'ethernet'=>ethernet_health row, 'firmware'=>string,
 *    'mac'=>string, 'serial'=>string, 'model'=>string]
 */
function airos_poll_device(array $device, array $cred): array {
    $login = airos_login($device, $cred);
    if (!$login['ok']) {
        return ['ok' => false, 'error' => $login['error']];
    }
    $jar  = $login['jar'];
    $base = airos_base_url($device, $cred['port'] ?? null);
    $verify = !empty($cred['verify_tls']);

    $status_r = _airos_curl($base . '/status.cgi', [], $jar, $verify);
    $sta_r    = _airos_curl($base . '/sta.cgi',    [], $jar, $verify);
    $iflist_r = _airos_curl($base . '/iflist.cgi', [], $jar, $verify);
    $scan_r   = _airos_curl($base . '/scan.cgi',   [], $jar, $verify); // best-effort, may 404

    airos_logout($jar);

    $status = json_decode((string)$status_r['body'], true);
    if (!is_array($status)) {
        return ['ok' => false, 'error' => 'status.cgi: not JSON (http ' . $status_r['http'] . ')'];
    }

    return [
        'ok'       => true,
        'error'    => '',
        'device'   => _airos_parse_device($status, $iflist_r['body'] ?? ''),
        'links'    => _airos_parse_stations($status, $sta_r['body'] ?? '', $device),
        'rf_env'   => _airos_parse_rf_env($scan_r['body'] ?? ''),
        'ethernet' => _airos_parse_ethernet($iflist_r['body'] ?? ''),
        'firmware' => (string)($status['host']['fwversion'] ?? ''),
        'mac'      => strtoupper((string)($status['host']['device_id'] ?? $status['interfaces'][0]['hwaddr'] ?? '')),
        'serial'   => (string)($status['host']['device_id'] ?? ''),
        'model'    => (string)($status['host']['devmodel'] ?? ''),
    ];
}

function _airos_parse_device(array $status, $iflist_raw): array {
    $host = $status['host'] ?? [];
    $w    = $status['wireless'] ?? [];
    $iflist = is_string($iflist_raw) ? json_decode($iflist_raw, true) : null;

    $cpu_pct = isset($host['cpuload']) ? (float)$host['cpuload'] : null;
    if ($cpu_pct !== null && $cpu_pct <= 1.0) $cpu_pct *= 100; // older fw reports 0-1

    $mem_total = (int)($host['totalram'] ?? 0);
    $mem_free  = (int)($host['freeram']  ?? 0);
    $mem_pct   = ($mem_total > 0) ? round(100 - 100 * $mem_free / $mem_total, 2) : null;

    $tx_rate = isset($w['txrate']) ? (float)$w['txrate'] : null;
    $rx_rate = isset($w['rxrate']) ? (float)$w['rxrate'] : null;

    return [
        'status'         => 'online',
        'uptime_seconds' => isset($host['uptime']) ? (int)$host['uptime'] : null,
        'cpu_pct'        => $cpu_pct,
        'mem_pct'        => $mem_pct,
        'signal_dbm'     => isset($w['signal'])   ? (int)$w['signal']   : null,
        'noise_dbm'      => isset($w['noisef'])   ? (int)$w['noisef']   : null,
        'ccq_pct'        => isset($w['ccq'])      ? (float)$w['ccq']    : null,
        'tx_rate_mbps'   => $tx_rate,
        'rx_rate_mbps'   => $rx_rate,
        'client_count'   => isset($w['count'])    ? (int)$w['count']    : null,
        'airtime_pct'    => isset($w['airtime'])  ? (float)$w['airtime'] : null,
        'firmware'       => (string)($host['fwversion'] ?? ''),
        'tx_bytes'       => isset($w['tx_bytes']) ? (int)$w['tx_bytes'] : null,
        'rx_bytes'       => isset($w['rx_bytes']) ? (int)$w['rx_bytes'] : null,
    ];
}

function _airos_parse_stations(array $status, $sta_raw, array $device): array {
    $sta = is_string($sta_raw) ? json_decode($sta_raw, true) : null;
    if (!is_array($sta)) {
        // Some firmwares put stations under status.wireless.sta
        $sta = $status['wireless']['sta'] ?? [];
    }
    $w = $status['wireless'] ?? [];
    $links = [];
    foreach ($sta as $s) {
        if (!is_array($s)) continue;
        $links[] = [
            'station_mac'         => strtoupper((string)($s['mac']      ?? '')),
            'ap_mac'              => strtoupper((string)($w['apmac']    ?? '')),
            'signal_local_dbm'    => isset($s['signal'])    ? (int)$s['signal']    : null,
            'signal_remote_dbm'   => isset($s['remote']['signal']) ? (int)$s['remote']['signal'] : null,
            'noise_local_dbm'     => isset($s['noisefloor']) ? (int)$s['noisefloor'] : (isset($w['noisef']) ? (int)$w['noisef'] : null),
            'noise_remote_dbm'    => isset($s['remote']['noisefloor']) ? (int)$s['remote']['noisefloor'] : null,
            'snr_local_db'        => isset($s['signal'], $s['noisefloor']) ? (int)$s['signal'] - (int)$s['noisefloor'] : null,
            'snr_remote_db'       => isset($s['remote']['signal'], $s['remote']['noisefloor']) ? (int)$s['remote']['signal'] - (int)$s['remote']['noisefloor'] : null,
            'ccq_pct'             => isset($s['ccq'])          ? (float)$s['ccq']          : null,
            'tx_rate_mbps'        => isset($s['tx'])           ? (float)$s['tx']           : (isset($s['txrate']) ? (float)$s['txrate'] : null),
            'rx_rate_mbps'        => isset($s['rx'])           ? (float)$s['rx']           : (isset($s['rxrate']) ? (float)$s['rxrate'] : null),
            'airtime_local_pct'   => isset($s['airtime_tx'])   ? (float)$s['airtime_tx']   : null,
            'airtime_remote_pct'  => isset($s['airtime_rx'])   ? (float)$s['airtime_rx']   : null,
            'throughput_local_mbps'  => isset($s['tx_throughput']) ? (float)$s['tx_throughput'] / 1e6 : null,
            'throughput_remote_mbps' => isset($s['rx_throughput']) ? (float)$s['rx_throughput'] / 1e6 : null,
            'capacity_local_mbps'    => isset($s['tx_capacity']) ? (float)$s['tx_capacity'] : null,
            'capacity_remote_mbps'   => isset($s['rx_capacity']) ? (float)$s['rx_capacity'] : null,
            'tx_power_dbm_local'  => isset($w['txpower'])      ? (int)$w['txpower']        : null,
            'tx_power_dbm_remote' => isset($s['remote']['txpower']) ? (int)$s['remote']['txpower'] : null,
            'frequency_mhz'       => isset($w['frequency'])    ? (int)$w['frequency']      : null,
            'channel_width_mhz'   => isset($w['chwidth'])      ? (int)$w['chwidth']        : null,
            'expected_rate_mbps'  => isset($s['rx_idx'])       ? (float)$s['rx_idx']       : null,
            'modulation'          => (string)($s['mcs'] ?? $s['rate'] ?? ''),
            'wireless_mode'       => (string)($w['mode'] ?? '802.11ac'),
            'uptime_seconds'      => isset($s['uptime']) ? (int)$s['uptime'] : null,
            'tx_bytes'            => isset($s['tx_bytes']) ? (int)$s['tx_bytes'] : null,
            'rx_bytes'            => isset($s['rx_bytes']) ? (int)$s['rx_bytes'] : null,
            'remote_ip'           => (string)($s['lastip'] ?? ''),
            'remote_name'         => (string)($s['name']   ?? ''),
        ];
    }
    return $links;
}

function _airos_parse_rf_env($scan_raw): array {
    $rows = is_string($scan_raw) ? json_decode($scan_raw, true) : null;
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $f = isset($r['freq']) ? (int)$r['freq'] : (isset($r['frequency']) ? (int)$r['frequency'] : 0);
        if ($f <= 0) continue;
        $rssi = isset($r['rssi']) ? (int)$r['rssi'] : (isset($r['signal']) ? (int)$r['signal'] : -100);
        $out[] = ['freq_mhz' => $f, 'rssi_dbm' => $rssi];
    }
    return $out;
}

function _airos_parse_ethernet($iflist_raw): array {
    $iflist = is_string($iflist_raw) ? json_decode($iflist_raw, true) : null;
    if (!is_array($iflist)) return [];
    foreach (($iflist['interfaces'] ?? $iflist) as $iface) {
        if (!is_array($iface)) continue;
        $name = (string)($iface['ifname'] ?? '');
        if (stripos($name, 'eth') === false && stripos($name, 'lan') === false) continue;
        $status = $iface['status'] ?? [];
        return [
            'lan_port'        => $name ?: 'eth0',
            'link_speed_mbps' => isset($status['speed']) ? (int)$status['speed'] : null,
            'duplex'          => (string)($status['duplex'] ?? 'unknown'),
            'cable_length_m'  => isset($status['cable_len']) ? (int)$status['cable_len'] : null,
            'cable_snr_db'    => isset($status['cable_snr']) ? (float)$status['cable_snr'] : null,
            'pair_a_status'   => (string)($status['pairs'][0] ?? 'unknown'),
            'pair_b_status'   => (string)($status['pairs'][1] ?? 'unknown'),
            'pair_c_status'   => (string)($status['pairs'][2] ?? 'unknown'),
            'pair_d_status'   => (string)($status['pairs'][3] ?? 'unknown'),
        ];
    }
    return [];
}

/**
 * Snapshot the current writable wireless config so we can revert if a
 * push fails. AirOS exposes the running config via /getcfg.cgi (newer
 * firmware) and /cfg.cgi (older). We try both.
 */
function airos_snapshot_config(array $device, array $cred): array {
    $login = airos_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];
    $jar  = $login['jar'];
    $base = airos_base_url($device, $cred['port'] ?? null);
    $verify = !empty($cred['verify_tls']);

    $r = _airos_curl($base . '/getcfg.cgi', [], $jar, $verify);
    if ($r['http'] === 200 && $r['body'] !== '') {
        airos_logout($jar);
        return ['ok' => true, 'error' => '', 'config_blob' => (string)$r['body']];
    }
    $r2 = _airos_curl($base . '/cfg.cgi', [], $jar, $verify);
    airos_logout($jar);
    if ($r2['http'] === 200 && $r2['body'] !== '') {
        return ['ok' => true, 'error' => '', 'config_blob' => (string)$r2['body']];
    }
    return ['ok' => false, 'error' => 'snapshot: cfg endpoints both failed'];
}

/**
 * Push a wireless config change. Payload keys (all optional):
 *   frequency_mhz, channel_width_mhz, tx_power_dbm,
 *   ssid, security ('open'|'wpa2'|'wpa3'), wpa_key
 *
 * Implementation: AirOS 8 exposes a granular JSON API; older firmware
 * needs a full /cfg.cgi POST + /system.cgi?reboot=. We attempt the
 * granular API first and fall back. Returns ok / error.
 */
function airos_apply_config(array $device, array $cred, array $payload): array {
    $login = airos_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];
    $jar  = $login['jar'];
    $base = airos_base_url($device, $cred['port'] ?? null);
    $verify = !empty($cred['verify_tls']);

    // Granular endpoint (AirOS 8.7+).
    $form = [];
    if (isset($payload['frequency_mhz']))     $form['radio.1.freq']     = (int)$payload['frequency_mhz'];
    if (isset($payload['channel_width_mhz'])) $form['radio.1.chanbw']   = (int)$payload['channel_width_mhz'];
    if (isset($payload['tx_power_dbm']))      $form['radio.1.txpower']  = (int)$payload['tx_power_dbm'];
    if (isset($payload['ssid']))              $form['wireless.1.ssid']  = (string)$payload['ssid'];
    if (isset($payload['security'])) {
        $sec = (string)$payload['security'];
        $form['wireless.1.security'] = $sec === 'open' ? 'none' : strtoupper($sec);
    }
    if (isset($payload['wpa_key'])) $form['wireless.1.wpa.psk']     = (string)$payload['wpa_key'];
    $form['change']    = 'wireless';
    $form['CHANGE']    = '1';
    $form['SUBMIT']    = 'apply';

    if ($form) {
        $r = _airos_curl($base . '/sockets.cgi', [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
        ], $jar, $verify);
        if ($r['http'] === 200) {
            airos_logout($jar);
            return ['ok' => true, 'error' => ''];
        }
        // fall through — older firmware
        $r2 = _airos_curl($base . '/cfg.cgi', [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
        ], $jar, $verify);
        if ($r2['http'] === 200) {
            // older firmware needs /system.cgi?reboot= to commit
            _airos_curl($base . '/system.cgi?reboot=', [], $jar, $verify);
            airos_logout($jar);
            return ['ok' => true, 'error' => ''];
        }
    }

    airos_logout($jar);
    return ['ok' => false, 'error' => 'apply: cfg endpoints rejected the change'];
}

function airos_revert_config(array $device, array $cred, array $snapshot): array {
    if (empty($snapshot['config_blob'])) {
        return ['ok' => false, 'error' => 'no snapshot to revert from'];
    }
    $login = airos_login($device, $cred);
    if (!$login['ok']) return ['ok' => false, 'error' => $login['error']];
    $jar  = $login['jar'];
    $base = airos_base_url($device, $cred['port'] ?? null);
    $verify = !empty($cred['verify_tls']);

    $r = _airos_curl($base . '/cfg.cgi', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $snapshot['config_blob'],
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
    ], $jar, $verify);
    _airos_curl($base . '/system.cgi?reboot=', [], $jar, $verify);
    airos_logout($jar);
    return $r['http'] === 200
        ? ['ok' => true,  'error' => '']
        : ['ok' => false, 'error' => 'revert: http ' . $r['http']];
}
