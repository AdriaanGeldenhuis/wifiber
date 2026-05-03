<?php
/**
 * Diagnostic-jobs worker — Phase 12.
 *
 * Picks queued diagnostic_jobs rows and runs them via the device's
 * existing SSH credentials. Speed-test results are also recorded in
 * link_speedtests so admin/link-view.php can chart Mbps over time.
 *
 * Recommended cron (every minute):
 *
 *   *  *  *  *  *  /usr/bin/php ~/public_html/bin/run-diagnostic.php --quiet >> ~/run-diagnostic.log 2>&1
 *
 * Single-flight via flock matches the other workers.
 *
 * Flags:
 *   --quiet
 *   --max-jobs=N    safety cap (default 5)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/diagnostics.php';

$opts = ['quiet' => false, 'max-jobs' => 5];
foreach ($argv as $a) {
    if      ($a === '--quiet') $opts['quiet'] = true;
    elseif  (preg_match('/^--max-jobs=(\d+)$/', $a, $m)) $opts['max-jobs'] = max(1, min(50, (int)$m[1]));
}

$lockfile = __DIR__ . '/../data/run-diagnostic.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[run-diagnostic] another run is in progress, exiting.\n");
    exit(0);
}

$jobs = diagnostic_jobs_pending($opts['max-jobs']);
if (!$jobs) {
    if (!$opts['quiet']) echo "[run-diagnostic] queue empty\n";
    flock($lh, LOCK_UN); fclose($lh); exit(0);
}

$ok = $fail = 0;
foreach ($jobs as $job) {
    $job_id = (int)$job['id'];
    diagnostic_job_mark($job_id, 'running');

    $payload = json_decode((string)$job['payload_json'], true) ?: [];
    $scope   = (string)$job['scope'];
    $scope_id = (int)$job['scope_id'];

    // Resolve which device to SSH into.
    $device_id = $scope === 'device'
        ? $scope_id
        : (int)pdo()->query("SELECT cpe_device_id FROM wireless_links WHERE id = " . $scope_id)->fetchColumn();
    if ($device_id <= 0) {
        diagnostic_job_mark($job_id, 'failed', null, 'no device for job');
        $fail++; continue;
    }
    $device = device_find($device_id);
    if (!$device) {
        diagnostic_job_mark($job_id, 'failed', null, 'device gone');
        $fail++; continue;
    }
    $creds = device_credentials_for($device_id, 'ssh') ?: device_credentials_for($device_id);
    if (!$creds) {
        diagnostic_job_mark($job_id, 'failed', null, 'no credentials for device');
        $fail++; continue;
    }
    $cred = device_credentials_unlock($creds[0]);
    if ($cred === null) {
        diagnostic_job_mark($job_id, 'failed', null, 'credential decrypt failed');
        $fail++; continue;
    }

    $kind = (string)$job['kind'];
    $fn = 'diagnostic_' . $kind . '_run';
    if (!function_exists($fn)) {
        diagnostic_job_mark($job_id, 'failed', null, "no handler: $fn");
        $fail++; continue;
    }
    $r = $fn($device, $cred, $payload);
    if (!empty($r['ok'])) {
        diagnostic_job_mark($job_id, 'done', [
            'output' => mb_substr((string)($r['output'] ?? ''), 0, 8000),
            'parsed' => $r['parsed'] ?? null,
        ]);
        if ($kind === 'iperf3' && $scope === 'link' && !empty($r['parsed'])) {
            link_speedtest_record($scope_id, $job_id, $r['parsed']);
        }
        $ok++;
    } else {
        diagnostic_job_mark($job_id, 'failed', [
            'output' => mb_substr((string)($r['output'] ?? ''), 0, 8000),
        ], (string)($r['error'] ?? 'unknown'));
        $fail++;
    }
}

flock($lh, LOCK_UN); fclose($lh);
if (!$opts['quiet']) printf("[run-diagnostic] ok=%d fail=%d\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
