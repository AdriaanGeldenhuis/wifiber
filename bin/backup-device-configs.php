<?php
/**
 * Nightly device config-backup worker — Phase 24.
 *
 * Iterates every non-retired device that has credentials and an
 * appropriate vendor, calls the vendor adapter's snapshot_config
 * function, and stores the running config in `device_configs`.
 *
 * device_config_save() short-circuits when the new sha256 matches the
 * most recent snapshot, so a quiet device adds zero rows over time.
 * That gives us "did this config change?" as a simple row-count query
 * and feeds the drift detector in bin/check-config-drift.php.
 *
 * Recommended cron (nightly at 03:30):
 *
 *   30 3 * * *  /usr/bin/php ~/public_html/bin/backup-device-configs.php --quiet >> ~/backup-device-configs.log 2>&1
 *
 * Flags:
 *   --dry-run             show what we'd back up, touch nothing
 *   --quiet               suppress stdout on success
 *   --verbose             one line per device with sha + size
 *   --only-vendor=NAME    only back up devices with vendor=NAME
 *   --only-device=ID      only back up one device (debug)
 *   --retention-days=N    prune snapshots older than N days, keeping
 *                         the most recent per device (default 90)
 *   --max-parallel=N      reserved — adapters run sequentially
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/vendors/airos.php';
require __DIR__ . '/../auth/vendors/routeros.php';
require __DIR__ . '/../auth/vendors/cambium.php';
require __DIR__ . '/../auth/vendors/mimosa.php';

$opts = [
    'dry-run'        => false,
    'quiet'          => false,
    'verbose'        => false,
    'only-vendor'    => '',
    'only-device'    => 0,
    'retention-days' => 90,
];
foreach ($argv as $a) {
    if      ($a === '--dry-run') $opts['dry-run'] = true;
    elseif  ($a === '--quiet')   $opts['quiet']   = true;
    elseif  ($a === '--verbose') $opts['verbose'] = true;
    elseif  (preg_match('/^--only-vendor=(\w+)$/', $a, $m))    $opts['only-vendor']    = strtolower($m[1]);
    elseif  (preg_match('/^--only-device=(\d+)$/', $a, $m))    $opts['only-device']    = (int)$m[1];
    elseif  (preg_match('/^--retention-days=(\d+)$/', $a, $m)) $opts['retention-days'] = max(7, min(3650, (int)$m[1]));
}

// Single-flight lock so an overrunning backup doesn't overlap the next
// cron tick. Same pattern as the wireless poller.
$lockfile = __DIR__ . '/../data/backup-device-configs.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[backup-device-configs] another run is in progress.\n");
    exit(0);
}

if (device_secret_key() === null) {
    fwrite(STDERR, "[backup-device-configs] device_key not configured — refusing to run.\n");
    exit(1);
}

$vendor_map = [
    'ubiquiti' => 'airos_snapshot_config',
    'mikrotik' => 'routeros_snapshot_config',
    'cambium'  => 'cambium_snapshot_config',
    'mimosa'   => 'mimosa_snapshot_config',
];

// Pick targets. Only devices that (a) aren't retired, (b) have a known
// vendor adapter, (c) have credentials saved, and (d) have a
// management IP — anything else is unreachable by definition.
$where = ["d.status <> 'retired'", "d.mgmt_ip <> ''", "d.vendor IN ('" . implode("','", array_keys($vendor_map)) . "')"];
$args  = [];
if ($opts['only-vendor'] !== '') { $where[] = 'd.vendor = ?';     $args[] = $opts['only-vendor']; }
if ($opts['only-device'] > 0)    { $where[] = 'd.id = ?';         $args[] = $opts['only-device']; }
$sql = "SELECT d.* FROM devices d WHERE " . implode(' AND ', $where) . " ORDER BY d.id ASC";
$stmt = pdo()->prepare($sql);
$stmt->execute($args);
$devices = $stmt->fetchAll();

$snapshot_count = 0;
$unchanged      = 0;
$failed         = 0;
$skipped        = 0;
$start          = microtime(true);

foreach ($devices as $d) {
    $vendor_fn = $vendor_map[$d['vendor']] ?? null;
    if (!$vendor_fn || !function_exists($vendor_fn)) { $skipped++; continue; }

    // Pick the first credential row we can decrypt. Adapters all expect
    // the same shape from device_credentials_unlock().
    $creds = device_credentials_for((int)$d['id']);
    if (!$creds) { $skipped++; continue; }
    $cred  = device_credentials_unlock($creds[0]);
    if (!$cred) { $skipped++; continue; }

    if ($opts['dry-run']) {
        printf("  would snapshot device#%d %s (%s)\n", $d['id'], $d['name'], $d['vendor']);
        continue;
    }

    try {
        $r = $vendor_fn($d, $cred);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => $e->getMessage()];
    }

    if (empty($r['ok'])) {
        $failed++;
        device_credentials_record_attempt((int)$creds[0]['id'], false, (string)($r['error'] ?? 'snapshot failed'));
        if (!$opts['quiet']) {
            fprintf(STDERR, "  device#%d %s — snapshot failed: %s\n", $d['id'], $d['name'], (string)($r['error'] ?? '?'));
        }
        continue;
    }

    // Adapters return the running config in different keys depending
    // on what the radio gives back. Normalise to a single string body
    // so the sha256 is stable regardless of vendor.
    $body = '';
    if (!empty($r['config'])    && is_string($r['config']))   $body = $r['config'];
    elseif (!empty($r['snapshot']) && is_string($r['snapshot'])) $body = $r['snapshot'];
    elseif (!empty($r['raw'])   && is_string($r['raw']))     $body = $r['raw'];
    else $body = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $new_id = device_config_save((int)$d['id'], (string)$d['vendor'], $body, 'cron', null, '');
    if ($new_id === 0) {
        $unchanged++;
        if ($opts['verbose']) printf("  device#%d %s — unchanged (sha %s)\n", $d['id'], $d['name'], substr(hash('sha256', $body), 0, 12));
    } else {
        $snapshot_count++;
        device_credentials_record_attempt((int)$creds[0]['id'], true);
        if ($opts['verbose']) printf("  device#%d %s — snapshot#%d size=%d sha=%s\n", $d['id'], $d['name'], $new_id, strlen($body), substr(hash('sha256', $body), 0, 12));
    }
}

// Retention prune — keep the latest snapshot per device regardless of
// age, but drop older ones beyond the retention window.
$retention_days = $opts['retention-days'];
if (!$opts['dry-run']) {
    $del = pdo()->prepare(
        "DELETE c FROM device_configs c
            JOIN (
              SELECT device_id, MAX(captured_at) AS keep_at
                FROM device_configs
               GROUP BY device_id
            ) k ON k.device_id = c.device_id
          WHERE c.captured_at < (NOW() - INTERVAL ? DAY)
            AND c.captured_at < k.keep_at"
    );
    $del->execute([$retention_days]);
    $pruned = $del->rowCount();
} else {
    $pruned = 0;
}

$ms = (int)round((microtime(true) - $start) * 1000);
if (!$opts['quiet']) {
    printf(
        "[backup-device-configs] devices=%d snapshots=%d unchanged=%d failed=%d skipped=%d pruned=%d in %dms%s\n",
        count($devices), $snapshot_count, $unchanged, $failed, $skipped, $pruned, $ms,
        $opts['dry-run'] ? ' (dry-run)' : ''
    );
}
exit(0);
