<?php
/**
 * Periodic RADIUS reconciliation.
 *
 * Walks every client and re-runs radius_provision_user(). The work is
 * idempotent and cheap — radcheck/radreply rows are replaced in place,
 * radusergroup is rewritten only when the group changes, audit_log only
 * fires when a status flips. Works as a safety net for cases where the
 * synchronous hook didn't fire (e.g. a manual UPDATE in the DB, an old
 * cron path that bypassed admin/client-edit.php).
 *
 * Recommended cron (every 5 minutes):
 *
 *   *_/5_*_*_*_*  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/radius-sync.php --quiet >> ~/radius-sync.log 2>&1
 *
 * (slashes / asterisks above are space-separated.)
 *
 * Flags:
 *   --dry-run      list what would change without writing
 *   --quiet        suppress per-user output on success
 *   --only=ID      only sync this one user id (debug)
 *   --purge-stale  delete radcheck/radreply/radusergroup rows whose username
 *                  no longer matches any active client (handy after a bulk
 *                  rename). Off by default — operator opt-in.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/radius.php';

$opts = ['dry-run' => false, 'quiet' => false, 'only' => 0, 'purge-stale' => false];
foreach (array_slice($argv, 1) as $a) {
    if      ($a === '--dry-run')      $opts['dry-run']     = true;
    elseif  ($a === '--quiet')        $opts['quiet']       = true;
    elseif  ($a === '--purge-stale')  $opts['purge-stale'] = true;
    elseif  (preg_match('/^--only=(\d+)$/', $a, $m))   $opts['only']        = (int)$m[1];
    else { fwrite(STDERR, "unknown arg: $a\n"); exit(2); }
}

$lockfile = __DIR__ . '/../data/radius-sync.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[radius-sync] another run is in progress, exiting.\n");
    exit(0);
}

$start  = microtime(true);
$pdo    = pdo();

$sql  = "SELECT id, username, radius_username, status, radius_group
           FROM users
          WHERE role = 'client'";
$args = [];
if ($opts['only'] > 0) { $sql .= " AND id = ?"; $args[] = $opts['only']; }
$sql .= " ORDER BY id";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$users = $stmt->fetchAll();

$ok = 0; $changed = 0; $errors = 0;
$known_usernames = [];

foreach ($users as $u) {
    $username = trim((string)($u['radius_username'] ?? '')) ?: (string)$u['username'];
    $known_usernames[$username] = true;

    if ($opts['dry-run']) {
        $expected = match ((string)($u['status'] ?? 'active')) {
            'active'       => 'active',
            'suspended'    => 'suspended',
            'disconnected' => 'disconnected',
            default        => 'disconnected',
        };
        $now = (string)($u['radius_group'] ?? '?');
        if ($now !== $expected) {
            echo "  [dry-run] {$username}: {$now} → {$expected}\n";
        }
        $ok++;
        continue;
    }

    try {
        $prev = (string)($u['radius_group'] ?? '');
        radius_provision_user((int)$u['id']);
        $u_now = find_user_by_id((int)$u['id']);
        $now = (string)($u_now['radius_group'] ?? '');
        if ($prev !== $now) {
            $changed++;
            if (!$opts['quiet']) echo "  + {$username}: {$prev} → {$now}\n";
        }
        $ok++;
    } catch (Throwable $e) {
        $errors++;
        fprintf(STDERR, "  ! {$username}: %s\n", $e->getMessage());
    }
}

if ($opts['purge-stale'] && !$opts['dry-run']) {
    $names = $pdo->query("SELECT DISTINCT username FROM radcheck")->fetchAll(PDO::FETCH_COLUMN);
    $stale = array_diff($names, array_keys($known_usernames));
    foreach ($stale as $name) {
        radius_purge_user((string)$name);
        if (!$opts['quiet']) echo "  - purged stale {$name}\n";
    }
    if (!$opts['quiet']) echo "Stale RADIUS rows purged: " . count($stale) . "\n";
}

$dur = round(microtime(true) - $start, 2);
if (!$opts['quiet']) {
    printf("[radius-sync] users=%d changed=%d errors=%d  %.2fs\n", $ok, $changed, $errors, $dur);
}

flock($lh, LOCK_UN);
fclose($lh);
exit($errors > 0 ? 1 : 0);
