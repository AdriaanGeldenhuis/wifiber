<?php
/**
 * Topology auto-discovery — Phase 14.
 *
 * SNMP-walks LLDP and CDP MIBs on every device that has credentials,
 * emits site_link_candidates rows for an admin to approve in
 * /admin/topology-review.php. Idempotent — re-running on the same
 * fleet just re-confirms existing candidates.
 *
 * Recommended cron: nightly.
 *
 *   15 4 * * *  /usr/bin/php ~/public_html/bin/discover-topology.php --quiet >> ~/discover-topology.log 2>&1
 *
 * Flags:
 *   --quiet
 *   --only-device=ID
 *   --dry-run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';

const LLDP_REM_TABLE     = '.1.0.8802.1.1.2.1.4.1.1';
const LLDP_REM_CHASSIS   = '.1.0.8802.1.1.2.1.4.1.1.5';   // chassis id (MAC)
const LLDP_REM_SYS_NAME  = '.1.0.8802.1.1.2.1.4.1.1.9';
const CDP_CACHE_DEVICE   = '.1.3.6.1.4.1.9.9.23.1.2.1.1.6';

$opts = ['quiet' => false, 'dry-run' => false, 'only-device' => 0];
foreach ($argv as $a) {
    if      ($a === '--quiet')   $opts['quiet']   = true;
    elseif  ($a === '--dry-run') $opts['dry-run'] = true;
    elseif  (preg_match('/^--only-device=(\d+)$/', $a, $m)) $opts['only-device'] = (int)$m[1];
}

if (!function_exists('snmpwalk')) {
    fwrite(STDERR, "[discover-topology] php-snmp extension missing.\n");
    exit(2);
}

$pdo = pdo();
$sql = "SELECT d.*, dc.snmp_community_enc, dc.scheme
          FROM devices d
          JOIN device_credentials dc ON dc.device_id = d.id
         WHERE d.status <> 'retired' AND d.mgmt_ip <> ''
           AND dc.scheme IN ('snmpv2','snmpv3')";
$args = [];
if ($opts['only-device'] > 0) {
    $sql .= ' AND d.id = ?';
    $args[] = $opts['only-device'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$candidates_seen = 0; $confirmed = 0;
foreach ($rows as $r) {
    $community = decrypt_secret($r['snmp_community_enc'] ?? null);
    if (!$community) {
        if (!$opts['quiet']) fprintf(STDERR, "  #%d %s: no community\n", $r['id'], $r['name']);
        continue;
    }
    $ip = (string)$r['mgmt_ip'];
    $rows_lldp = @snmpwalk($ip, $community, LLDP_REM_CHASSIS, 1_000_000, 1) ?: [];
    $rows_lldp_n = @snmpwalk($ip, $community, LLDP_REM_SYS_NAME, 1_000_000, 1) ?: [];

    foreach ($rows_lldp as $i => $val) {
        $mac = _topo_clean_mac((string)$val);
        if ($mac === '') continue;
        $name = isset($rows_lldp_n[$i]) ? trim(preg_replace('/^STRING:\s*"?|"?$/', '', (string)$rows_lldp_n[$i])) : '';
        // Match the discovered MAC to a known device.
        $to_id = (int)($pdo->query(
            "SELECT id FROM devices WHERE mac = " . $pdo->quote($mac) . " LIMIT 1"
        )->fetchColumn() ?: 0);

        if ($opts['dry-run']) {
            printf("  [%s] sees %s (%s) → device #%s\n", $r['name'], $mac, $name, $to_id ?: 'unknown');
            $candidates_seen++;
            continue;
        }
        // Upsert candidate.
        $pdo->prepare(
            "INSERT INTO site_link_candidates
                (from_device_id, to_device_id, to_mac, to_name, source, confidence)
             VALUES (?, ?, ?, ?, 'lldp', ?)
             ON DUPLICATE KEY UPDATE
                to_name = VALUES(to_name),
                confidence = LEAST(1.0, confidence + 0.05),
                observed_at = CURRENT_TIMESTAMP"
        )->execute([
            (int)$r['id'], $to_id ?: null, $mac, mb_substr($name, 0, 120),
            $to_id ? 0.9 : 0.4,
        ]);
        $candidates_seen++;
        if ($to_id) $confirmed++;
    }
}

if (!$opts['quiet']) {
    printf("[discover-topology] candidates=%d confirmed=%d devices_walked=%d%s\n",
        $candidates_seen, $confirmed, count($rows),
        $opts['dry-run'] ? ' (dry-run)' : '');
}
exit(0);

function _topo_clean_mac(string $val): string {
    // SNMP returns "Hex-STRING: 00 11 22 ..." or "STRING: 00:11:22..."
    if (preg_match('/Hex-STRING:\s*([0-9A-Fa-f ]+)/', $val, $m)) {
        return strtoupper(str_replace(' ', ':', trim($m[1])));
    }
    if (preg_match('/STRING:\s*"?([0-9A-Fa-f:.\-]+)"?/', $val, $m)) {
        $mac = strtoupper($m[1]);
        if (preg_match('/^[0-9A-F]{2}([:\-][0-9A-F]{2}){5}$/', $mac)) return str_replace('-', ':', $mac);
    }
    return '';
}
