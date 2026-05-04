<?php
/**
 * RADIUS session reconciler.
 *
 * Two responsibilities:
 *
 *   1. Update users.last_login when a customer's session shows fresh activity
 *      (acctupdatetime within the last 5 minutes). Lets the client portal
 *      and the customer-list "Last seen" column reflect real connectivity
 *      rather than just web logins.
 *
 *   2. Stale-session cleanup. NAS devices occasionally die without sending
 *      the closing Accounting-Stop, leaving rows with acctstoptime IS NULL
 *      that will never close. We mark anything whose acctupdatetime is older
 *      than --stale-minutes (default 30) as stopped, with terminate-cause
 *      'NAS-Reboot'. FreeRADIUS itself can do this with rlm_sql post-acct
 *      cleanup — we duplicate it here so the live-sessions list on
 *      /admin/radius.php doesn't lie when the operator hasn't deployed
 *      that snippet.
 *
 * Recommended cron (every minute):
 *
 *   *_*_*_*_*  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/radius-sessions.php --quiet >> ~/radius-sessions.log 2>&1
 *
 * (asterisks above are space-separated).
 *
 * Flags:
 *   --dry-run             show what would change, don't write
 *   --quiet               suppress stdout on success
 *   --stale-minutes=N     close sessions whose acctupdatetime is older (default 30)
 *   --activity-window=N   refresh users.last_login if any session updated
 *                         within the last N minutes (default 5)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/radius.php';

$opts = [
    'dry-run'         => false,
    'quiet'           => false,
    'stale-minutes'   => 30,
    'activity-window' => 5,
];
foreach (array_slice($argv, 1) as $a) {
    if      ($a === '--dry-run') $opts['dry-run'] = true;
    elseif  ($a === '--quiet')   $opts['quiet']   = true;
    elseif  (preg_match('/^--stale-minutes=(\d+)$/', $a, $m))   $opts['stale-minutes']   = max(1, (int)$m[1]);
    elseif  (preg_match('/^--activity-window=(\d+)$/', $a, $m)) $opts['activity-window'] = max(1, (int)$m[1]);
    else { fwrite(STDERR, "unknown arg: $a\n"); exit(2); }
}

$lockfile = __DIR__ . '/../data/radius-sessions.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[radius-sessions] another run is in progress, exiting.\n");
    exit(0);
}

$start = microtime(true);
$pdo   = pdo();

/* 1. last_login refresh from active sessions ---------------------------- */
$active_window = $opts['activity-window'];
$stmt = $pdo->prepare(
    "SELECT u.id, u.last_login, MAX(a.acctupdatetime) AS last_seen
       FROM users u
       JOIN radacct a
         ON a.username = u.username
         OR a.username = u.radius_username
      WHERE u.role = 'client'
        AND a.acctstoptime IS NULL
        AND a.acctupdatetime >= (NOW() - INTERVAL ? MINUTE)
      GROUP BY u.id, u.last_login"
);
$stmt->execute([$active_window]);
$rows = $stmt->fetchAll();

$last_login_updates = 0;
foreach ($rows as $r) {
    $prev = $r['last_login'] ? strtotime((string)$r['last_login']) : 0;
    $cur  = strtotime((string)$r['last_seen']);
    if ($cur > $prev + 60) {
        if (!$opts['dry-run']) {
            $pdo->prepare("UPDATE users SET last_login = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', $cur), (int)$r['id']]);
        }
        $last_login_updates++;
    }
}

/* 2. close stale orphan sessions ----------------------------------------- */
$stale_minutes = $opts['stale-minutes'];
$stmt = $pdo->prepare(
    "SELECT radacctid, username, acctstarttime, acctupdatetime
       FROM radacct
      WHERE acctstoptime IS NULL
        AND COALESCE(acctupdatetime, acctstarttime) < (NOW() - INTERVAL ? MINUTE)
      ORDER BY radacctid
      LIMIT 5000"
);
$stmt->execute([$stale_minutes]);
$stale = $stmt->fetchAll();

$closed = 0;
foreach ($stale as $s) {
    if ($opts['dry-run']) {
        echo "  [dry-run] would close session {$s['radacctid']} for {$s['username']} (last update {$s['acctupdatetime']})\n";
        continue;
    }
    $start_ts  = strtotime((string)$s['acctstarttime']);
    $update_ts = strtotime((string)$s['acctupdatetime']) ?: $start_ts;
    $duration  = $start_ts && $update_ts ? max(0, $update_ts - $start_ts) : null;
    $pdo->prepare(
        "UPDATE radacct
            SET acctstoptime       = COALESCE(acctupdatetime, acctstarttime, NOW()),
                acctsessiontime    = COALESCE(acctsessiontime, ?),
                acctterminatecause = 'NAS-Reboot'
          WHERE radacctid = ?
            AND acctstoptime IS NULL"
    )->execute([$duration, (int)$s['radacctid']]);
    $closed++;
}

$dur = round(microtime(true) - $start, 2);
if (!$opts['quiet']) {
    printf("[radius-sessions] last_login_refresh=%d stale_closed=%d  %.2fs\n",
        $last_login_updates, $closed, $dur);
}

flock($lh, LOCK_UN);
fclose($lh);
exit(0);
