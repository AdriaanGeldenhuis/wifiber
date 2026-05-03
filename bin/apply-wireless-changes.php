<?php
/**
 * Wireless config-change worker — picks queued wireless_change_jobs
 * and pushes them to the live radios.
 *
 * Workflow per job (sector scope):
 *   1. Mark status='applying'.
 *   2. Snapshot current radio config via vendor_snapshot_config().
 *   3. For coordinated frequency / channel-width moves on a PTMP sector
 *      we change the CPEs first (so they follow the AP into the new
 *      band), then the AP. For PTP we change CPE-end (remote) then AP.
 *   4. Wait up to 60s for telemetry to come back online (last
 *      device_health row newer than `started_at`). If any side never
 *      reconverges, revert from snapshot.
 *   5. Mark status='applied' or 'rolled_back', append wireless_change_log.
 *
 * Recommended cron (every 30s — runs twice a minute):
 *
 *   *_*_*_*_*  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/apply-wireless-changes.php --quiet >> ~/apply-wireless.log 2>&1
 *   *_*_*_*_*  sleep 30 && /usr/bin/php /usr/home/wifibfjedj/public_html/bin/apply-wireless-changes.php --quiet >> ~/apply-wireless.log 2>&1
 *
 * Flags:
 *   --quiet
 *   --once          (default — process the queue and exit)
 *   --dry-run       fetch jobs and show what would happen, don't push
 *   --max-jobs=N    safety cap (default 10)
 *   --timeout=N     seconds to wait for reconvergence (default 60, max 300)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/sectors.php';
require __DIR__ . '/../auth/outages.php';
require __DIR__ . '/../auth/vendors/airos.php';
require __DIR__ . '/../auth/vendors/routeros.php';
require __DIR__ . '/../auth/vendors/cambium.php';
require __DIR__ . '/../auth/vendors/mimosa.php';

$opts = [
    'quiet'    => false,
    'dry-run'  => false,
    'max-jobs' => 10,
    'timeout'  => 60,
];
foreach ($argv as $a) {
    if      ($a === '--quiet')     $opts['quiet']   = true;
    elseif  ($a === '--once')      { /* default */ }
    elseif  ($a === '--dry-run')   $opts['dry-run'] = true;
    elseif  (preg_match('/^--max-jobs=(\d+)$/', $a, $m)) $opts['max-jobs'] = max(1, min(100, (int)$m[1]));
    elseif  (preg_match('/^--timeout=(\d+)$/', $a, $m))  $opts['timeout']  = max(10, min(300, (int)$m[1]));
}

$lockfile = __DIR__ . '/../data/apply-wireless.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[apply-wireless] another run is in progress, exiting.\n");
    exit(0);
}

$jobs = wireless_change_jobs_pending($opts['max-jobs']);
if (!$jobs) {
    if (!$opts['quiet']) echo "[apply-wireless] queue is empty\n";
    flock($lh, LOCK_UN); fclose($lh);
    exit(0);
}

$start = microtime(true);
$applied = $rolled_back = $failed = 0;

foreach ($jobs as $job) {
    $job_id   = (int)$job['id'];
    $payload  = json_decode((string)$job['payload_json'], true) ?: [];
    $scope    = (string)$job['scope'];
    $scope_id = (int)$job['scope_id'];
    $actor    = $job['requested_by'] !== null ? (int)$job['requested_by'] : null;

    if ($opts['dry-run']) {
        printf("[dry-run] would apply job #%d (%s #%d): %s\n",
            $job_id, $scope, $scope_id, json_encode($payload));
        continue;
    }

    if ($scope !== 'sector') {
        wireless_change_job_mark($job_id, 'failed', ['error' => 'only sector scope is implemented']);
        $failed++;
        continue;
    }

    $sector = sector_find($scope_id);
    if (!$sector || empty($sector['ap_device_id'])) {
        wireless_change_job_mark($job_id, 'failed', ['error' => 'sector or AP device not found']);
        $failed++;
        continue;
    }
    $ap = device_find((int)$sector['ap_device_id']);
    if (!$ap) {
        wireless_change_job_mark($job_id, 'failed', ['error' => 'AP device gone']);
        $failed++;
        continue;
    }

    wireless_change_job_mark($job_id, 'applying');

    // Pull credentials for the AP.
    $ap_creds = device_credentials_for((int)$ap['id'], 'https');
    if (!$ap_creds) $ap_creds = device_credentials_for((int)$ap['id']);
    if (!$ap_creds) {
        _apply_fail($job_id, 'AP has no credentials configured');
        $failed++;
        continue;
    }
    $ap_cred = device_credentials_unlock($ap_creds[0]);
    if ($ap_cred === null) {
        _apply_fail($job_id, 'AP credential decrypt failed (key missing?)');
        $failed++;
        continue;
    }

    // List CPEs on this sector — we'll bring them along on coordinated moves.
    $cpes = _apply_cpes_on_sector($scope_id);

    // Snapshot AP config first so we can revert.
    $snapshot = _apply_call($ap['vendor'], 'snapshot', $ap, $ap_cred);
    if (!$snapshot['ok']) {
        _apply_fail($job_id, 'AP snapshot failed: ' . $snapshot['error']);
        $failed++;
        continue;
    }
    wireless_change_job_mark($job_id, 'applying', [
        'snapshot_json' => ['ap' => $snapshot] + ['cpes' => []],
    ]);

    // For coordinated freq/width moves: push CPEs first.
    $coord = isset($payload['frequency_mhz']) || isset($payload['channel_width_mhz']);
    $cpe_results = [];
    if ($coord && $cpes) {
        foreach ($cpes as $cpe) {
            $cpe_creds = device_credentials_for((int)$cpe['id'], 'https')
                      ?: device_credentials_for((int)$cpe['id']);
            if (!$cpe_creds) {
                $cpe_results[$cpe['id']] = ['ok' => false, 'error' => 'no credentials'];
                continue;
            }
            $cpe_cred = device_credentials_unlock($cpe_creds[0]);
            if ($cpe_cred === null) {
                $cpe_results[$cpe['id']] = ['ok' => false, 'error' => 'decrypt failed'];
                continue;
            }
            $snap_cpe = _apply_call($cpe['vendor'], 'snapshot', $cpe, $cpe_cred);
            $cpe_results[$cpe['id']] = ['snapshot' => $snap_cpe, 'cred_idx' => 0];
            if ($snap_cpe['ok']) {
                $r = _apply_call($cpe['vendor'], 'apply', $cpe, $cpe_cred, $payload);
                $cpe_results[$cpe['id']]['apply'] = $r;
            }
            wireless_change_log_record([
                'job_id'    => $job_id, 'scope' => 'sector', 'scope_id' => $scope_id,
                'device_id' => (int)$cpe['id'], 'actor_user_id' => $actor,
                'action'    => 'apply_cpe', 'after' => $payload,
                'success'   => !empty($cpe_results[$cpe['id']]['apply']['ok']),
                'error'     => (string)($cpe_results[$cpe['id']]['apply']['error'] ?? $snap_cpe['error']),
            ]);
        }
    }

    // Push AP.
    $ap_apply = _apply_call($ap['vendor'], 'apply', $ap, $ap_cred, $payload);
    wireless_change_log_record([
        'job_id'    => $job_id, 'scope' => 'sector', 'scope_id' => $scope_id,
        'device_id' => (int)$ap['id'], 'actor_user_id' => $actor,
        'action'    => 'apply_ap', 'before' => $snapshot, 'after' => $payload,
        'success'   => !empty($ap_apply['ok']),
        'error'     => (string)($ap_apply['error'] ?? ''),
    ]);

    if (!$ap_apply['ok']) {
        // Try to revert anything we already pushed.
        _apply_revert_all($ap, $ap_cred, $snapshot, $cpes, $cpe_results);
        wireless_change_job_mark($job_id, 'rolled_back', [
            'error' => 'AP push failed, reverted: ' . $ap_apply['error'],
        ]);
        $rolled_back++;
        continue;
    }

    // Wait for reconvergence — we look for a fresh device_health row.
    $ok = _apply_wait_reconverge((int)$ap['id'], $opts['timeout']);
    if (!$ok && $cpes) {
        // For PTMP we don't need every CPE; majority is OK.
        $reconverged = 0;
        foreach ($cpes as $cpe) {
            if (_apply_wait_reconverge((int)$cpe['id'], 10)) $reconverged++;
        }
        $ok = $reconverged >= max(1, intdiv(count($cpes), 2));
    }

    if (!$ok) {
        _apply_revert_all($ap, $ap_cred, $snapshot, $cpes, $cpe_results);
        wireless_change_job_mark($job_id, 'rolled_back', [
            'error' => 'link did not reconverge within ' . $opts['timeout'] . 's; reverted',
        ]);
        // Open an outage if customers are now offline so the NOC sees it.
        if ($scope === 'sector') {
            outage_create('sector', $scope_id, 'Sector ' . $sector['name'],
                count($cpes), 'Frequency change rolled back automatically');
        }
        $rolled_back++;
        continue;
    }

    wireless_change_job_mark($job_id, 'applied');
    audit_log('wireless.change_applied', [
        'target_type' => $scope, 'target_id' => $scope_id, 'actor_user_id' => $actor,
        'meta' => ['job_id' => $job_id, 'payload_keys' => array_keys($payload)],
    ]);
    if (is_file(__DIR__ . '/../auth/webhooks.php')) {
        require_once __DIR__ . '/../auth/webhooks.php';
        webhook_fire('wireless.config_applied', [
            'job_id' => $job_id, 'scope' => $scope, 'scope_id' => $scope_id,
            'payload' => $payload, 'sector_name' => $sector['name'] ?? null,
        ]);
    }
    $applied++;
}

flock($lh, LOCK_UN);
fclose($lh);

$duration = round(microtime(true) - $start, 2);
if (!$opts['quiet']) {
    printf("[apply-wireless] applied=%d rolled_back=%d failed=%d  %.2fs\n",
        $applied, $rolled_back, $failed, $duration);
}
exit(($failed + $rolled_back) > 0 ? 1 : 0);

/* ----------------------------------------------------------- helpers */

function _apply_call(string $vendor, string $op, array $device, array $cred, array $payload = []): array {
    $vendor = strtolower($vendor);
    $fn = match ([$vendor, $op]) {
        ['ubiquiti','snapshot'] => 'airos_snapshot_config',
        ['ubiquiti','apply']    => 'airos_apply_config',
        ['ubiquiti','revert']   => 'airos_revert_config',
        ['mikrotik','snapshot'] => 'routeros_snapshot_config',
        ['mikrotik','apply']    => 'routeros_apply_config',
        ['mikrotik','revert']   => 'routeros_revert_config',
        ['cambium','snapshot']  => 'cambium_snapshot_config',
        ['cambium','apply']     => 'cambium_apply_config',
        ['cambium','revert']    => 'cambium_revert_config',
        ['mimosa','snapshot']   => 'mimosa_snapshot_config',
        ['mimosa','apply']      => 'mimosa_apply_config',
        ['mimosa','revert']     => 'mimosa_revert_config',
        default                 => null,
    };
    if ($fn === null || !function_exists($fn)) {
        return ['ok' => false, 'error' => "no $op handler for vendor $vendor"];
    }
    return $op === 'apply'
        ? $fn($device, $cred, $payload)
        : ($op === 'revert' ? $fn($device, $cred, $payload) : $fn($device, $cred));
}

function _apply_fail(int $job_id, string $error): void {
    wireless_change_job_mark($job_id, 'failed', ['error' => $error]);
    wireless_change_log_record([
        'job_id' => $job_id, 'action' => 'fail', 'success' => false, 'error' => $error,
    ]);
}

function _apply_cpes_on_sector(int $sector_id): array {
    $stmt = pdo()->prepare(
        "SELECT DISTINCT d.*
           FROM wireless_links wl
           JOIN devices d ON d.id = wl.cpe_device_id
          WHERE wl.sector_id = ?
            AND d.status <> 'retired'"
    );
    $stmt->execute([$sector_id]);
    return $stmt->fetchAll();
}

/**
 * Wait for `device_id` to send back a fresh device_health row newer
 * than `started_at` (now - timeout..now). Returns true if yes.
 */
function _apply_wait_reconverge(int $device_id, int $timeout_s): bool {
    $deadline = time() + $timeout_s;
    $cutoff   = date('Y-m-d H:i:s', time() - 5);
    while (time() < $deadline) {
        $stmt = pdo()->prepare(
            "SELECT 1 FROM device_health
              WHERE device_id = ? AND polled_at > ? AND status = 'online'
              LIMIT 1"
        );
        $stmt->execute([$device_id, $cutoff]);
        if ($stmt->fetchColumn()) return true;
        sleep(5);
    }
    return false;
}

function _apply_revert_all(array $ap, array $ap_cred, array $snapshot, array $cpes, array $cpe_results): void {
    _apply_call($ap['vendor'], 'revert', $ap, $ap_cred, $snapshot);
    foreach ($cpes as $cpe) {
        $r = $cpe_results[$cpe['id']] ?? null;
        if (!$r || empty($r['snapshot']['ok'])) continue;
        $cpe_cred = device_credentials_unlock(
            (device_credentials_for((int)$cpe['id'], 'https') ?: device_credentials_for((int)$cpe['id']))[0] ?? []
        );
        if ($cpe_cred === null) continue;
        _apply_call($cpe['vendor'], 'revert', $cpe, $cpe_cred, $r['snapshot']);
    }
}
