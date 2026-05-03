<?php
/**
 * Cambium ePMP / cnPilot adapter.
 *
 * Polls SNMPv2c when the php-snmp extension is present. Falls back to
 * the cnMaestro REST API when SNMP isn't available and a 'api' scheme
 * credential row is configured (api_token + base_url in notes).
 *
 *   cambium_poll_device($device, $cred)
 *   cambium_apply_config($device, $cred, $payload)
 *   cambium_revert_config($device, $cred, $snapshot)
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

const CAMBIUM_OID = [
    'sys_descr'   => '.1.3.6.1.2.1.1.1.0',
    'sys_uptime'  => '.1.3.6.1.2.1.1.3.0',
    'sys_serial'  => '.1.3.6.1.4.1.17713.21.1.1.1.0',
    'sys_model'   => '.1.3.6.1.4.1.17713.21.1.1.2.0',
    'sys_fw'      => '.1.3.6.1.4.1.17713.21.1.1.5.0',
    'sys_cpu'     => '.1.3.6.1.4.1.17713.21.1.1.10.0',
    'sys_mem'     => '.1.3.6.1.4.1.17713.21.1.1.11.0',
    'wl_freq'     => '.1.3.6.1.4.1.17713.21.3.1.7.0',
    'wl_chwidth'  => '.1.3.6.1.4.1.17713.21.3.1.8.0',
    'wl_txpower'  => '.1.3.6.1.4.1.17713.21.3.1.9.0',
    'wl_signal'   => '.1.3.6.1.4.1.17713.21.3.1.20.0',
    'wl_noise'    => '.1.3.6.1.4.1.17713.21.3.1.21.0',
    'wl_snr'      => '.1.3.6.1.4.1.17713.21.3.1.22.0',
    'wl_txrate'   => '.1.3.6.1.4.1.17713.21.3.1.30.0',
    'wl_rxrate'   => '.1.3.6.1.4.1.17713.21.3.1.31.0',
];

function cambium_poll_device(array $device, array $cred): array {
    if (!function_exists('snmpget') && empty($cred['api_token'])) {
        return ['ok' => false, 'error' => 'php-snmp extension not loaded and no API token configured'];
    }
    if (function_exists('snmpget') && !empty($cred['snmp_community'])) {
        return _cambium_poll_snmp($device, $cred);
    }
    if (!empty($cred['api_token'])) {
        return _cambium_poll_cnmaestro($device, $cred);
    }
    return ['ok' => false, 'error' => 'no usable credentials (need snmp_community or api_token)'];
}

function _cambium_snmp_get(string $ip, string $community, string $oid): ?string {
    $v = @snmpget($ip, $community, $oid, 1_000_000, 1);
    if ($v === false) return null;
    if (is_string($v) && str_starts_with($v, 'INTEGER: '))    return substr($v, 9);
    if (is_string($v) && str_starts_with($v, 'STRING: "'))    return substr($v, 9, -1);
    if (is_string($v) && str_starts_with($v, 'STRING: '))     return substr($v, 8);
    if (is_string($v) && str_starts_with($v, 'Counter32: '))  return substr($v, 11);
    if (is_string($v) && str_starts_with($v, 'Gauge32: '))    return substr($v, 9);
    if (is_string($v) && str_starts_with($v, 'Timeticks: ')) {
        if (preg_match('/\((\d+)\)/', $v, $m)) return (string)((int)$m[1] / 100);
    }
    return is_string($v) ? $v : (string)$v;
}

function _cambium_poll_snmp(array $device, array $cred): array {
    $ip   = (string)$device['mgmt_ip'];
    $comm = (string)$cred['snmp_community'];
    $g = function (string $key) use ($ip, $comm) {
        $oid = CAMBIUM_OID[$key] ?? null;
        if ($oid === null) return null;
        return _cambium_snmp_get($ip, $comm, $oid);
    };

    $descr = $g('sys_descr');
    if ($descr === null) {
        return ['ok' => false, 'error' => 'snmpget timed out'];
    }
    $signal = $g('wl_signal');
    $noise  = $g('wl_noise');
    $snr    = $g('wl_snr');
    $tx     = $g('wl_txrate');
    $rx     = $g('wl_rxrate');
    $freq   = $g('wl_freq');
    $width  = $g('wl_chwidth');
    $txp    = $g('wl_txpower');
    $cpu    = $g('sys_cpu');
    $mem    = $g('sys_mem');
    $uptime = $g('sys_uptime');

    $links = [[
        'signal_local_dbm'   => $signal !== null ? (int)$signal : null,
        'noise_local_dbm'    => $noise  !== null ? (int)$noise  : null,
        'snr_local_db'       => $snr    !== null ? (int)$snr    : null,
        'tx_rate_mbps'       => $tx     !== null ? (float)$tx   : null,
        'rx_rate_mbps'       => $rx     !== null ? (float)$rx   : null,
        'frequency_mhz'      => $freq   !== null ? (int)$freq   : null,
        'channel_width_mhz'  => $width  !== null ? (int)$width  : null,
        'tx_power_dbm_local' => $txp    !== null ? (int)$txp    : null,
        'wireless_mode'      => '802.11ac',
    ]];

    return [
        'ok' => true, 'error' => '',
        'device' => [
            'status'         => 'online',
            'uptime_seconds' => $uptime !== null ? (int)((float)$uptime) : null,
            'cpu_pct'        => $cpu !== null ? (float)$cpu : null,
            'mem_pct'        => $mem !== null ? (float)$mem : null,
            'signal_dbm'     => $signal !== null ? (int)$signal : null,
            'noise_dbm'      => $noise  !== null ? (int)$noise  : null,
            'tx_rate_mbps'   => $tx !== null ? (float)$tx : null,
            'rx_rate_mbps'   => $rx !== null ? (float)$rx : null,
            'firmware'       => (string)$g('sys_fw'),
        ],
        'links'    => $links,
        'rf_env'   => [],
        'ethernet' => [],
        'firmware' => (string)$g('sys_fw'),
        'mac'      => '',
        'serial'   => (string)$g('sys_serial'),
        'model'    => (string)$g('sys_model'),
    ];
}

function _cambium_poll_cnmaestro(array $device, array $cred): array {
    // cnMaestro REST: GET /api/v2/devices/{mac}/statistics
    $base  = trim((string)($cred['notes'] ?? 'https://cloud.cambiumnetworks.com'), " /");
    $token = (string)$cred['api_token'];
    $mac   = strtoupper(str_replace(':', '', (string)$device['mac']));
    if ($mac === '') return ['ok' => false, 'error' => 'device.mac is required for cnMaestro'];

    $ch = curl_init($base . '/api/v2/devices/' . urlencode($mac) . '/statistics');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) return ['ok' => false, 'error' => 'cnMaestro http ' . $http];

    $j = json_decode((string)$body, true);
    $d = $j['data'] ?? $j ?? [];

    return [
        'ok' => true, 'error' => '',
        'device' => [
            'status'         => 'online',
            'uptime_seconds' => isset($d['uptime']) ? (int)$d['uptime'] : null,
            'cpu_pct'        => isset($d['cpu'])    ? (float)$d['cpu']  : null,
            'mem_pct'        => isset($d['memory']) ? (float)$d['memory'] : null,
            'signal_dbm'     => isset($d['signal']) ? (int)$d['signal'] : null,
            'noise_dbm'      => isset($d['noise'])  ? (int)$d['noise']  : null,
            'tx_rate_mbps'   => isset($d['tx_rate']) ? (float)$d['tx_rate'] : null,
            'rx_rate_mbps'   => isset($d['rx_rate']) ? (float)$d['rx_rate'] : null,
            'firmware'       => (string)($d['firmware'] ?? ''),
        ],
        'links' => [], 'rf_env' => [], 'ethernet' => [],
        'firmware' => (string)($d['firmware'] ?? ''),
        'mac' => $mac, 'serial' => (string)($d['serial_number'] ?? ''),
        'model' => (string)($d['model'] ?? ''),
    ];
}

function cambium_snapshot_config(array $device, array $cred): array {
    return ['ok' => true, 'error' => '', 'snmp_settable' => false];
}

function cambium_apply_config(array $device, array $cred, array $payload): array {
    if (empty($cred['api_token'])) {
        return ['ok' => false, 'error' => 'cnMaestro API token required for config push'];
    }
    $base = trim((string)($cred['notes'] ?? 'https://cloud.cambiumnetworks.com'), " /");
    $mac  = strtoupper(str_replace(':', '', (string)$device['mac']));
    $body = [];
    if (isset($payload['frequency_mhz']))     $body['radio_frequency'] = (int)$payload['frequency_mhz'];
    if (isset($payload['channel_width_mhz'])) $body['radio_chan_width'] = (int)$payload['channel_width_mhz'];
    if (isset($payload['tx_power_dbm']))      $body['radio_tx_power']  = (int)$payload['tx_power_dbm'];
    if (isset($payload['ssid']))              $body['ssid']            = (string)$payload['ssid'];
    if (!$body) return ['ok' => true, 'error' => ''];

    $ch = curl_init($base . '/api/v2/devices/' . urlencode($mac) . '/config');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cred['api_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http >= 200 && $http < 300)
        ? ['ok' => true,  'error' => '']
        : ['ok' => false, 'error' => 'cnMaestro PATCH http ' . $http];
}

function cambium_revert_config(array $device, array $cred, array $snapshot): array {
    return cambium_apply_config($device, $cred, $snapshot['radio'] ?? []);
}
