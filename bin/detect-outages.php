<?php
/**
 * Outage detection cron.
 *
 * Scans every sector that has an AP device assigned and either opens
 * a new outage (AP offline) or resolves an existing one (AP online).
 *
 * Runs cheaply (one sweep query, then per-row inserts/updates) so a
 * 1-minute interval is fine.
 *
 * Recommended cron (xneelo / cPanel — every minute):
 *
 *   * * * * *  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/detect-outages.php --quiet >> ~/detect-outages.log 2>&1
 *
 * Flags:
 *   --quiet      suppress stdout on no-op; still prints when something changes
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/outages.php';

$quiet = in_array('--quiet', $argv, true);

// Single-flight lock so a slow run can't pile up.
$lockfile = __DIR__ . '/../data/detect-outages.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$quiet) fwrite(STDERR, "[detect-outages] another run is in progress, exiting.\n");
    exit(0);
}

$start  = microtime(true);
$result = outage_detect();
$dur    = round(microtime(true) - $start, 2);

$changed = $result['opened'] > 0 || $result['closed'] > 0 || $result['updated'] > 0;
if ($changed || !$quiet) {
    printf(
        "[detect-outages] opened=%d closed=%d updated=%d  %.2fs\n",
        $result['opened'], $result['closed'], $result['updated'], $dur
    );
}

flock($lh, LOCK_UN);
fclose($lh);
