<?php
/**
 * Wireless polling cron — vendor-specific telemetry.
 *
 * Iterates every non-retired wireless device that has a credentials row,
 * dispatches to the right vendor adapter (auth/vendors/airos.php,
 * routeros.php, cambium.php, mimosa.php), and writes:
 *
 *   • A device_health row (uptime, CPU, mem, signal, noise, rates, ccq,
 *     airtime, throughput, capacity, firmware, tx/rx bytes).
 *   • One link_health_samples row per discovered station, plus an
 *     update to the parent wireless_links "current state" columns.
 *   • rf_environment_samples rows per scanned frequency (best-effort,
 *     only if the radio supports it without disrupting traffic).
 *   • An ethernet_health row (cable diag, link speed, duplex).
 *
 * Auto-creates wireless_links rows when an adapter reports a station
 * MAC that matches an existing CPE device.mac — so onboarding a new
 * customer is just "register the CPE in /admin/devices.php" and the
 * link materialises on the next poll.
 *
 * Recommended cron (every minute):
 *
 *   *_*_*_*_*  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/poll-wireless.php --quiet >> ~/poll-wireless.log 2>&1
 *
 * (the asterisks above are space-separated).
 *
 * Flags:
 *   --dry-run             list what we'd poll, don't touch radios or DB
 *   --quiet               suppress stdout on success
 *   --once                poll once and exit (default; here for symmetry)
 *   --verbose             extra detail per device
 *   --only-vendor=NAME    only poll devices with vendor=NAME
 *   --only-device=ID      only poll one device (handy for debugging)
 *   --retention-days=N    prune samples older than N days (default 7)
 *   --rf-retention=H      prune rf_environment_samples older than H hours (default 48)
 *   --max-parallel=N      reserved for future use; vendor adapters are
 *                         currently sequential to keep credential auth simple
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/outages.php';
require __DIR__ . '/../auth/notifications.php';
require __DIR__ . '/../auth/vendors/airos.php';
require __DIR__ . '/../auth/vendors/routeros.php';
require __DIR__ . '/../auth/vendors/cambium.php';
require __DIR__ . '/../auth/vendors/mimosa.php';

const POLL_WIRELESS_CRED_FAIL_THRESHOLD = 3;

$opts = [
    'dry-run'        => false,
    'quiet'          => false,
    'verbose'        => false,
    'only-vendor'    => '',
    'only-device'    => 0,
    'retention-days' => 7,
    'rf-retention'   => 48,
];
foreach ($argv as $a) {
    if      ($a === '--dry-run')   $opts['dry-run']  = true;
    elseif  ($a === '--quiet')     $opts['quiet']    = true;
    elseif  ($a === '--verbose')   $opts['verbose']  = true;
    elseif  ($a === '--once')      { /* no-op, default */ }
    elseif  (preg_match('/^--only-vendor=(\w+)$/', $a, $m))  $opts['only-vendor'] = strtolower($m[1]);
    elseif  (preg_match('/^--only-device=(\d+)$/', $a, $m))  $opts['only-device'] = (int)$m[1];
    elseif  (preg_match('/^--retention-days=(\d+)$/', $a, $m)) $opts['retention-days'] = max(1, min(365, (int)$m[1]));
    elseif  (preg_match('/^--rf-retention=(\d+)$/', $a, $m))   $opts['rf-retention']   = max(1, min(8760, (int)$m[1]));
}

$lockfile = __DIR__ . '/../data/poll-wireless.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[poll-wireless] another run is in progress, exiting.\n");
    exit(0);
}

$start = microtime(true);
$pdo   = pdo();

// Pull every device with a credentials row. The polling worker doesn't
// touch devices without creds (so manually-added bookkeeping rows don't
// generate spurious "auth failed" noise).
$sql = "SELECT d.*, dc.id AS cred_id, dc.scheme AS cred_scheme,
               dc.username AS cred_username, dc.port AS cred_port,
               dc.verify_tls AS cred_verify_tls,
               dc.password_enc, dc.ssh_key_enc, dc.snmp_community_enc, dc.api_token_enc,
               dc.notes AS cred_notes
          FROM devices d
          JOIN device_credentials dc ON dc.device_id = d.id
         WHERE d.status <> 'retired'
           AND d.mgmt_ip <> ''";
$args = [];
if ($opts['only-vendor'] !== '') { $sql .= ' AND d.vendor = ?'; $args[] = $opts['only-vendor']; }
if ($opts['only-device'] > 0)    { $sql .= ' AND d.id = ?';     $args[] = $opts['only-device']; }
$sql .= ' ORDER BY d.id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

if (!$rows) {
    if (!$opts['quiet']) echo "[poll-wireless] no eligible devices (need vendor + credentials + mgmt_ip)\n";
    flock($lh, LOCK_UN); fclose($lh);
    exit(0);
}

if ($opts['dry-run']) {
    foreach ($rows as $r) {
        printf("[dry-run] %s %s #%d %-18s %-15s scheme=%s\n",
            $r['vendor'], $r['model'] ?: '?', $r['id'],
            $r['name'], $r['mgmt_ip'], $r['cred_scheme']);
    }
    flock($lh, LOCK_UN); fclose($lh);
    exit(0);
}

$ok_devices = 0; $err_devices = 0; $links_touched = 0;

foreach ($rows as $row) {
    $device = $row;
    $cred = device_credentials_unlock([
        'id'                 => $row['cred_id'],
        'device_id'          => $row['id'],
        'scheme'             => $row['cred_scheme'],
        'username'           => $row['cred_username'],
        'password_enc'       => $row['password_enc'],
        'ssh_key_enc'        => $row['ssh_key_enc'],
        'snmp_community_enc' => $row['snmp_community_enc'],
        'api_token_enc'      => $row['api_token_enc'],
        'port'               => $row['cred_port'],
        'verify_tls'         => $row['cred_verify_tls'],
        'notes'              => $row['cred_notes'],
    ]);
    if ($cred === null) {
        if ($opts['verbose']) fprintf(STDERR, "  #%d %s: credential decrypt failed (key missing?)\n", $row['id'], $row['name']);
        device_credentials_record_attempt((int)$row['cred_id'], false, 'decrypt failed');
        $err_devices++;
        continue;
    }
    $cred['notes'] = $row['cred_notes'] ?? ''; // cnMaestro base URL etc.

    $vendor = strtolower((string)$row['vendor']);
    $result = match ($vendor) {
        'ubiquiti' => airos_poll_device($device, $cred),
        'mikrotik' => routeros_poll_device($device, $cred),
        'cambium'  => cambium_poll_device($device, $cred),
        'mimosa'   => mimosa_poll_device($device, $cred),
        default    => ['ok' => false, 'error' => "no adapter for vendor '$vendor'"],
    };

    if (!$result['ok']) {
        $err_devices++;
        device_credentials_record_attempt((int)$row['cred_id'], false, (string)$result['error']);
        if ($opts['verbose']) fprintf(STDERR, "  #%d %s: %s\n", $row['id'], $row['name'], $result['error']);

        // Auto-open a device-scope outage when credentials have failed
        // POLL_WIRELESS_CRED_FAIL_THRESHOLD times in a row, so a key
        // rotation or firmware change doesn't silently stop telemetry.
        // outage_active() returns the existing one if any so we don't
        // double-fire.
        $cred_after = device_credentials_for((int)$row['id'], (string)$row['cred_scheme']);
        $fails = isset($cred_after[0]) ? (int)$cred_after[0]['consecutive_fails'] : 0;
        if ($fails === POLL_WIRELESS_CRED_FAIL_THRESHOLD
            && outage_active('device', (int)$row['id']) === null) {
            outage_create('device', (int)$row['id'],
                $row['name'] . ' (credentials)',
                0,
                'Vendor adapter auth failed ' . $fails . 'x in a row: ' . $result['error']);
            // Page the NOC.
            $site = load_site_settings();
            $noc = trim((string)($site['noc_email'] ?? ''));
            if ($noc !== '') {
                notify_send(['id' => 0, 'email' => $noc, 'name' => 'NOC'],
                    'cred.fail', [
                        'device_name' => $row['name'],
                        'fails'       => $fails,
                    ], ['email']);
            }
        }
        continue;
    }

    // Auto-resolve the cred outage (if any) when a poll succeeds again.
    if ($active = outage_active('device', (int)$row['id'])) {
        outage_resolve((int)$active['id'], 'Credentials valid again on next poll.');
    }

    device_credentials_record_attempt((int)$row['cred_id'], true);
    $ok_devices++;

    // 1. device_health row
    $d = $result['device'] ?? [];
    $pdo->prepare(
        "INSERT INTO device_health
            (device_id, status, uptime_seconds, cpu_pct, mem_pct,
             signal_dbm, noise_dbm, ccq_pct, tx_rate_mbps, rx_rate_mbps, client_count,
             airtime_pct, capacity_mbps, throughput_mbps, firmware, tx_bytes, rx_bytes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        (int)$row['id'],
        $d['status'] ?? 'online',
        $d['uptime_seconds'] ?? null,
        $d['cpu_pct'] ?? null,
        $d['mem_pct'] ?? null,
        $d['signal_dbm']   ?? null,
        $d['noise_dbm']    ?? null,
        $d['ccq_pct']      ?? null,
        $d['tx_rate_mbps'] ?? null,
        $d['rx_rate_mbps'] ?? null,
        $d['client_count'] ?? null,
        $d['airtime_pct']  ?? null,
        $d['capacity_mbps']  ?? null,
        $d['throughput_mbps'] ?? null,
        (string)($d['firmware'] ?? ''),
        $d['tx_bytes'] ?? null,
        $d['rx_bytes'] ?? null,
    ]);
    // Update the device "current state" too (status flip + last_seen + firmware).
    $pdo->prepare(
        "UPDATE devices
            SET status = CASE WHEN status = 'retired' THEN status ELSE 'online' END,
                last_seen_at = NOW(),
                firmware = COALESCE(NULLIF(?, ''), firmware),
                mac      = COALESCE(NULLIF(?, ''), mac)
          WHERE id = ?"
    )->execute([
        (string)($result['firmware'] ?? ''),
        (string)($result['mac']      ?? ''),
        (int)$row['id'],
    ]);

    // 2. Link samples — one per station the radio sees.
    foreach (($result['links'] ?? []) as $link) {
        $sta_mac = $link['station_mac'] ?? '';
        if ($sta_mac === '') continue;
        // Match the station MAC against existing CPE device records.
        $cpe_stmt = $pdo->prepare("SELECT id FROM devices WHERE mac = ? LIMIT 1");
        $cpe_stmt->execute([$sta_mac]);
        $cpe_id = $cpe_stmt->fetchColumn();
        if (!$cpe_id) {
            // Auto-stash unknown stations in a "pending" device row so the
            // operator can claim them on /admin/devices.php. Vendor + role
            // default to ubiquiti / cpe — fixed up by the operator.
            $name = $link['remote_name'] !== '' ? $link['remote_name'] : ('CPE ' . $sta_mac);
            $pdo->prepare(
                "INSERT INTO devices (site_id, name, vendor, role, mac, mgmt_ip, status)
                 VALUES (NULL, ?, ?, 'cpe', ?, ?, 'unknown')"
            )->execute([
                substr($name, 0, 120),
                $vendor === 'ubiquiti' ? 'ubiquiti' : 'other',
                $sta_mac,
                (string)($link['remote_ip'] ?? ''),
            ]);
            $cpe_id = (int)$pdo->lastInsertId();
        }
        /* Resolve a customer_id for the new wireless_link row so the
           operator doesn't have to bind it manually after every fresh
           CPE association. Two sources, in order of confidence:
             1. devices.customer_id on the matched CPE device row
                (admin already linked it).
             2. users.equipment_mac matching this station MAC (sales
                captured it on the lead form).
           If neither hits, customer_id stays null and the operator can
           bind it on /admin/devices.php as before. */
        $auto_customer_id = null;
        $cstmt = $pdo->prepare("SELECT customer_id FROM devices WHERE id = ? LIMIT 1");
        $cstmt->execute([(int)$cpe_id]);
        $cv = $cstmt->fetchColumn();
        if ($cv !== false && $cv !== null) $auto_customer_id = (int)$cv;
        if ($auto_customer_id === null && $sta_mac !== '') {
            $ustmt = $pdo->prepare(
                "SELECT id FROM users
                  WHERE role = 'client'
                    AND UPPER(REPLACE(REPLACE(equipment_mac, ':', ''), '-', '')) = UPPER(REPLACE(REPLACE(?, ':', ''), '-', ''))
                  LIMIT 1"
            );
            $ustmt->execute([$sta_mac]);
            $uv = $ustmt->fetchColumn();
            if ($uv !== false && $uv !== null) {
                $auto_customer_id = (int)$uv;
                // Mirror it onto the device row so subsequent polls
                // resolve via path 1 (cheaper) and other UIs see the
                // binding immediately.
                $pdo->prepare("UPDATE devices SET customer_id = ? WHERE id = ? AND customer_id IS NULL")
                    ->execute([$auto_customer_id, (int)$cpe_id]);
            }
        }

        $link_extra = [
            'ssid'        => $link['ssid']        ?? '',
            'ap_mac'      => $link['ap_mac']      ?? '',
            'station_mac' => $link['station_mac'] ?? '',
        ];
        if ($auto_customer_id !== null) $link_extra['customer_id'] = $auto_customer_id;
        $link_id = wireless_link_upsert((int)$row['id'], (int)$cpe_id, $link_extra);
        /* upsert only sets customer_id on INSERT; for an existing row
           we backfill it conservatively (only when currently NULL) so
           we never overwrite a manual binding. */
        if ($auto_customer_id !== null) {
            $pdo->prepare(
                "UPDATE wireless_links SET customer_id = ?
                  WHERE id = ? AND customer_id IS NULL"
            )->execute([$auto_customer_id, (int)$link_id]);
        }
        // Backfill distance from site lat/lng pair if unset.
        if (empty($link['distance_km'])) {
            $link['distance_km'] = distance_between_devices_km((int)$row['id'], (int)$cpe_id);
        }
        wireless_link_record_sample($link_id, $link);
        $links_touched++;
    }

    // 3. RF environment scan rows.
    if (!empty($result['rf_env'])) {
        rf_environment_record((int)$row['id'], $result['rf_env']);
    }

    // 4. Ethernet diagnostics (matches the screenshot's footer).
    if (!empty($result['ethernet'])) {
        ethernet_health_record((int)$row['id'], $result['ethernet']);
    }

    if ($opts['verbose']) {
        printf("  ok #%d %-18s %-10s links=%d rf=%d eth=%s\n",
            $row['id'], $row['name'], $vendor,
            count($result['links'] ?? []),
            count($result['rf_env'] ?? []),
            !empty($result['ethernet']) ? 'y' : 'n');
    }
}

// 5. Retention.
$retention_days = $opts['retention-days'];
$pdo->prepare("DELETE FROM link_health_samples WHERE polled_at < (NOW() - INTERVAL ? DAY)")
    ->execute([$retention_days]);
$pdo->prepare("DELETE FROM ethernet_health     WHERE polled_at < (NOW() - INTERVAL ? DAY)")
    ->execute([$retention_days]);
rf_environment_cleanup($opts['rf-retention']);

$duration = round(microtime(true) - $start, 2);

if (!$opts['quiet']) {
    printf("[poll-wireless] ok=%d errors=%d links=%d  %.2fs\n",
        $ok_devices, $err_devices, $links_touched, $duration);
}

flock($lh, LOCK_UN);
fclose($lh);
exit($err_devices > 0 ? 1 : 0);
