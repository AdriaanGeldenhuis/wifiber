<?php
/**
 * UISP sync cron.
 *
 * Pulls sites, devices, data-links and CRM clients from UISP into the local
 * cache tables. Designed to run on a 5–15 minute schedule.
 *
 * Recommended cron entry (xneelo / cPanel — every 15 minutes):
 *
 *   0,15,30,45 * * * *  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/uisp-sync.php --quiet >> ~/uisp-sync.log 2>&1
 *
 * Flags:
 *   --dry-run   only test the connection; skip writes
 *   --quiet     suppress stdout on success (still prints on errors)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/uisp_sync.php';

$dryrun = in_array('--dry-run', $argv, true);
$quiet  = in_array('--quiet',   $argv, true);

if (!uisp_is_configured()) {
    fwrite(STDERR, "[uisp-sync] not configured — open /admin/uisp.php and save a base URL + API token.\n");
    exit(2);
}

if ($dryrun) {
    echo "[uisp-sync] DRY RUN — testing connection only.\n";
    $r = uisp_test_connection();
    echo $r['ok']
        ? ('OK' . ($r['version'] ? ' · UISP ' . $r['version'] : '') . "\n")
        : ('FAIL: ' . $r['message'] . "\n");
    exit($r['ok'] ? 0 : 1);
}

$start = microtime(true);
$res   = uisp_sync_all();
$dur   = round(microtime(true) - $start, 2);

$has_output = !$quiet || !$res['ok'];
if ($has_output) {
    $c = $res['counts'] ?? [];
    printf(
        "[uisp-sync] %s  sites=%d devices=%d links=%d clients=%d  %.2fs\n",
        $res['ok'] ? 'OK' : 'PARTIAL',
        $c['sites']      ?? 0,
        $c['devices']    ?? 0,
        $c['data_links'] ?? 0,
        $c['clients']    ?? 0,
        $dur
    );
    if (!empty($res['errors'])) {
        foreach ($res['errors'] as $e) echo "  ! $e\n";
    }
}
exit($res['ok'] ? 0 : 1);
