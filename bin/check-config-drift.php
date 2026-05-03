<?php
/**
 * Configuration drift detection — Phase 14.
 *
 * For every sector with an AP device that has credentials, compares
 * the live radio config (via vendor adapter snapshot/poll) against
 * the DB-of-record (sectors.frequency_mhz, channel_width_mhz,
 * tx_power_dbm, ssid). Mismatches → config_drift_alerts row.
 *
 * Catches the "someone logged into the AP and changed the channel
 * manually" class of bug — wifiber's DB still says 5180 but the
 * radio is actually on 5200.
 *
 * Recommended cron: nightly, after the wireless poll has had a chance
 * to refresh telemetry.
 *
 *   50 4 * * *  /usr/bin/php ~/public_html/bin/check-config-drift.php --quiet >> ~/check-drift.log 2>&1
 *
 * Flags:
 *   --quiet
 *   --dry-run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/sectors.php';
require __DIR__ . '/../auth/notifications.php';

$opts = ['quiet' => false, 'dry-run' => false];
foreach ($argv as $a) {
    if      ($a === '--quiet')   $opts['quiet']   = true;
    elseif  ($a === '--dry-run') $opts['dry-run'] = true;
}

$pdo = pdo();
$sectors = $pdo->query(
    "SELECT s.*, d.id AS device_id, d.vendor, d.mgmt_ip, d.name AS device_name
       FROM sectors s
       JOIN devices d ON d.id = s.ap_device_id
      WHERE d.status <> 'retired' AND d.mgmt_ip <> ''"
)->fetchAll();

$opened = $resolved = 0;
foreach ($sectors as $sec) {
    $sector_id = (int)$sec['id'];
    $device_id = (int)$sec['device_id'];

    // Pull the latest wireless_links row for this sector — it carries the
    // adapter-reported frequency_mhz / channel_width / SSID we wrote on
    // the last poll. Cheaper than re-running the adapter here.
    $obs = $pdo->prepare(
        "SELECT frequency_mhz, channel_width_mhz, ssid, tx_power_dbm_local
           FROM wireless_links
          WHERE ap_device_id = ?
          ORDER BY last_evaluated_at DESC
          LIMIT 1"
    );
    $obs->execute([$device_id]);
    $live = $obs->fetch();
    if (!$live) continue;

    $checks = [
        ['field' => 'frequency_mhz',     'expected' => $sec['frequency_mhz'],     'observed' => $live['frequency_mhz']],
        ['field' => 'channel_width_mhz', 'expected' => $sec['channel_width_mhz'], 'observed' => $live['channel_width_mhz']],
        ['field' => 'tx_power_dbm',      'expected' => $sec['tx_power_dbm'],      'observed' => $live['tx_power_dbm_local']],
        ['field' => 'ssid',              'expected' => $sec['ssid'],              'observed' => $live['ssid']],
    ];

    foreach ($checks as $c) {
        $exp = (string)($c['expected'] ?? '');
        $obs_v = (string)($c['observed'] ?? '');
        if ($exp === '' && $obs_v === '') continue; // both blank, nothing to compare
        if ($exp === '') continue; // DB hasn't set the expected value yet
        if ($exp === $obs_v) {
            $resolved += _drift_resolve($device_id, (string)$c['field'], $opts['dry-run']);
            continue;
        }
        if ($opts['dry-run']) {
            printf("  drift sector#%d %s: expected=%s observed=%s\n",
                $sector_id, $c['field'], $exp, $obs_v);
            $opened++;
            continue;
        }
        // Upsert active alert.
        $stmt = $pdo->prepare(
            "SELECT id FROM config_drift_alerts
              WHERE device_id = ? AND field = ? AND resolved_at IS NULL LIMIT 1"
        );
        $stmt->execute([$device_id, $c['field']]);
        if ($existing = $stmt->fetchColumn()) {
            $pdo->prepare(
                "UPDATE config_drift_alerts SET expected = ?, observed = ?, detected_at = NOW() WHERE id = ?"
            )->execute([$exp, $obs_v, (int)$existing]);
        } else {
            $pdo->prepare(
                "INSERT INTO config_drift_alerts
                    (device_id, sector_id, field, expected, observed)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$device_id, $sector_id, $c['field'], $exp, $obs_v]);
            audit_log('config_drift.open', [
                'target_type' => 'device', 'target_id' => $device_id,
                'meta' => ['field' => $c['field'], 'expected' => $exp, 'observed' => $obs_v],
            ]);
            // Page NOC.
            $site = load_site_settings();
            $noc = trim((string)($site['noc_email'] ?? ''));
            if ($noc !== '') {
                notify_send(['id' => 0, 'email' => $noc, 'name' => 'NOC'],
                    'cred.fail', [
                        'device_name' => $sec['device_name'] . ' [drift: ' . $c['field'] . ']',
                        'fails'       => 1,
                    ], ['email']);
            }
        }
        $opened++;
    }
}

if (!$opts['quiet']) {
    printf("[check-config-drift] opened=%d resolved=%d sectors=%d%s\n",
        $opened, $resolved, count($sectors), $opts['dry-run'] ? ' (dry-run)' : '');
}
exit(0);

function _drift_resolve(int $device_id, string $field, bool $dry_run): int {
    if ($dry_run) return 0;
    $stmt = pdo()->prepare(
        "UPDATE config_drift_alerts
            SET resolved_at = NOW()
          WHERE device_id = ? AND field = ? AND resolved_at IS NULL"
    );
    $stmt->execute([$device_id, $field]);
    return $stmt->rowCount();
}
