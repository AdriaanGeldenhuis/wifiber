<?php
/**
 * Device polling cron — ICMP ping + status flip.
 *
 * Iterates every non-retired device with a management IP, pings them in
 * parallel via proc_open, writes a device_health row per result, and
 * flips devices.status if the last two samples agree (see
 * STATUS_FLIP_WINDOW in auth/devices.php).
 *
 * Vendor-specific polling (RouterOS API, AirOS SSH, SNMP) is NOT in
 * scope for this script — Phase 4 will add per-vendor adapters that
 * extend the device_health row beyond just RTT.
 *
 * Recommended cron entry (xneelo / cPanel — every 5 minutes):
 *
 *   *_/5 * * * *  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/poll-devices.php --quiet >> ~/poll-devices.log 2>&1
 *
 * (replace the `*_/5` above with `*` `/` `5` — markdown-safe form)
 *
 * Flags:
 *   --dry-run             list which devices would be polled, don't ping
 *   --quiet               suppress stdout on success (still prints on errors)
 *   --max-parallel=N      override concurrency (default 32, capped at 128)
 *   --retention=DAYS      device_health rows older than this are pruned
 *                         (default 30, capped at 3650)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';

$opts = [
    'dry-run'      => false,
    'quiet'        => false,
    'max-parallel' => 32,
    'retention'    => 30,
];
foreach ($argv as $a) {
    if ($a === '--dry-run') $opts['dry-run'] = true;
    elseif ($a === '--quiet') $opts['quiet'] = true;
    elseif (preg_match('/^--max-parallel=(\d+)$/', $a, $m)) $opts['max-parallel'] = max(1, min(128, (int)$m[1]));
    elseif (preg_match('/^--retention=(\d+)$/', $a, $m))    $opts['retention']    = max(1, min(3650, (int)$m[1]));
}

if (!function_exists('proc_open')) {
    fwrite(STDERR, "[poll-devices] proc_open is disabled on this host — talk to your host or run polling on another machine.\n");
    exit(2);
}

// Single-flight lock — if a previous run is still going (e.g. polling a
// huge fleet) skip this tick rather than piling up parallel writes.
$lockfile = __DIR__ . '/../data/poll-devices.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[poll-devices] another run is in progress, exiting.\n");
    exit(0);
}

$start = microtime(true);

$rows = pdo()->query(
    "SELECT id, name, mgmt_ip
       FROM devices
      WHERE status <> 'retired'
        AND mgmt_ip <> ''"
)->fetchAll();

if (!$rows) {
    if (!$opts['quiet']) echo "[poll-devices] no devices to poll\n";
    flock($lh, LOCK_UN); fclose($lh);
    exit(0);
}

if ($opts['dry-run']) {
    foreach ($rows as $r) printf("[dry-run] would ping #%d %s (%s)\n", $r['id'], $r['name'], $r['mgmt_ip']);
    flock($lh, LOCK_UN); fclose($lh);
    exit(0);
}

$reachable = $unreachable = $errors = 0;
$batches = array_chunk($rows, $opts['max-parallel']);

foreach ($batches as $batch) {
    $procs = [];
    foreach ($batch as $d) {
        $cmd = sprintf('ping -c 1 -W 2 %s 2>&1', escapeshellarg($d['mgmt_ip']));
        $pipes = [];
        $h = @proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($h)) { $errors++; continue; }
        foreach ($pipes as $p) stream_set_blocking($p, false);
        $procs[(int)$d['id']] = [
            'device' => $d,
            'handle' => $h,
            'pipes'  => $pipes,
            'output' => '',
        ];
    }

    while ($procs) {
        foreach ($procs as $id => $p) {
            $procs[$id]['output'] .= (string)stream_get_contents($p['pipes'][1]);
            $procs[$id]['output'] .= (string)stream_get_contents($p['pipes'][2]);
            $st = proc_get_status($p['handle']);
            if (!$st['running']) {
                // Drain any remaining output before closing.
                $procs[$id]['output'] .= (string)stream_get_contents($p['pipes'][1]);
                $procs[$id]['output'] .= (string)stream_get_contents($p['pipes'][2]);
                fclose($p['pipes'][0]);
                fclose($p['pipes'][1]);
                fclose($p['pipes'][2]);
                proc_close($p['handle']);

                $ok  = $st['exitcode'] === 0;
                $rtt = null;
                if ($ok && preg_match('/time=([0-9.]+)\s*ms/', $procs[$id]['output'], $m)) {
                    $rtt = (float)$m[1];
                }
                device_record_poll_result((int)$p['device']['id'], $ok, $rtt);
                $ok ? $reachable++ : $unreachable++;
                unset($procs[$id]);
            }
        }
        if ($procs) usleep(50000); // 50 ms between sweeps
    }
}

$deleted  = device_health_cleanup($opts['retention']);
$duration = round(microtime(true) - $start, 2);

if (!$opts['quiet']) {
    printf(
        "[poll-devices] online=%d offline=%d errors=%d cleaned=%d  %.2fs\n",
        $reachable, $unreachable, $errors, $deleted, $duration
    );
}

flock($lh, LOCK_UN);
fclose($lh);
exit($errors > 0 ? 1 : 0);
