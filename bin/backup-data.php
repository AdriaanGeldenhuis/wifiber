<?php
/**
 * Daily backup of the WiFIBER data.
 *
 * Bundles into ~/backups/wifiber-YYYYMMDD-HHMMSS.tar.gz:
 *   - data/ (excluding db.php, db.local.php, db.php.example and
 *     anything matched by the in-script ignore list)
 *   - data/dump.sql  (mysqldump of the connected database)
 *
 * Optionally pushes the tarball to a private GitHub repo if
 * data/backup-config.php exists and provides 'git_remote' (an SSH or
 * HTTPS-with-token URL the server can already reach without prompting).
 *
 * Recommended cron (xneelo / cPanel — daily at 02:30):
 *
 *   30 2 * * *  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/backup-data.php >> ~/backup.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/helpers.php';

$home    = $_SERVER['HOME'] ?? '/tmp';
$cfg = [
    'local_dir' => $home . '/backups',
    'keep_days' => 30,
    'git_remote'        => null,
    'git_branch'        => 'main',
    'git_committer_name'  => 'wifiber-backup',
    'git_committer_email' => 'backup@wifiber.co.za',
];
$cfg_file = __DIR__ . '/../data/backup-config.php';
if (is_file($cfg_file)) {
    $loaded = require $cfg_file;
    if (is_array($loaded)) $cfg = array_merge($cfg, $loaded);
}

$src      = realpath(__DIR__ . '/../data');
$ts       = date('Ymd-His');
$work_dir = sys_get_temp_dir() . '/wifiber-backup-' . $ts;
$bundle   = "wifiber-{$ts}.tar.gz";

@mkdir($cfg['local_dir'], 0755, true);
@mkdir($work_dir,         0755, true);
@mkdir($work_dir . '/data', 0755, true);

echo "[backup] {$ts}\n";
echo "[backup] work dir: {$work_dir}\n";

/* 1. Copy data/ minus the sensitive / transient files. */
$ignore = [
    'db.php', 'db.local.php', 'db.php.example',
    'backup-config.php', 'backup-config.php.example',
    'throttle.json', 'password-resets.json',
];
$copied = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $path => $info) {
    /** @var SplFileInfo $info */
    $rel = ltrim(substr($path, strlen($src)), '/');
    if (in_array(basename($rel), $ignore, true))     continue;
    if (str_starts_with($rel, 'ticket-attachments')) {
        // attachments often big; copy but allow opt-out via cfg.
        if (!empty($cfg['skip_ticket_attachments']))  continue;
    }
    $dest = $work_dir . '/data/' . $rel;
    if ($info->isDir()) {
        @mkdir($dest, 0755, true);
    } else {
        @mkdir(dirname($dest), 0755, true);
        if (@copy($path, $dest)) $copied++;
    }
}
echo "[backup] copied {$copied} file(s) from data/\n";

/* 2. Dump MySQL to data/dump.sql inside the bundle. */
$cfg_db_file = is_file(DATA_DIR . '/db.local.php') ? DATA_DIR . '/db.local.php' : DATA_DIR . '/db.php';
$db = is_file($cfg_db_file) ? require $cfg_db_file : null;
if (is_array($db) && !empty($db['db'])) {
    $dump = $work_dir . '/data/dump.sql';
    $cmd = sprintf(
        'MYSQL_PWD=%s mysqldump --no-tablespaces --single-transaction --quick --skip-lock-tables -h %s -P %d -u %s %s > %s 2>&1',
        escapeshellarg((string)($db['pass'] ?? '')),
        escapeshellarg((string)$db['host']),
        (int)($db['port'] ?? 3306),
        escapeshellarg((string)$db['user']),
        escapeshellarg((string)$db['db']),
        escapeshellarg($dump)
    );
    exec($cmd, $out, $rc);
    if ($rc === 0 && is_file($dump) && filesize($dump) > 0) {
        echo "[backup] mysqldump ok (" . filesize($dump) . " bytes)\n";
    } else {
        echo "[backup] mysqldump FAILED rc={$rc}: " . implode("\n", $out) . "\n";
        @unlink($dump);
    }
} else {
    echo "[backup] no db config found, skipping dump\n";
}

/* 3. Tarball it. */
$tarball = $cfg['local_dir'] . '/' . $bundle;
$cmd = sprintf(
    'tar -czf %s -C %s data 2>&1',
    escapeshellarg($tarball),
    escapeshellarg($work_dir)
);
exec($cmd, $out, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "[backup] tar FAILED rc={$rc}: " . implode("\n", $out) . "\n");
    exec('rm -rf ' . escapeshellarg($work_dir));
    exit(1);
}
echo "[backup] wrote {$tarball} (" . filesize($tarball) . " bytes)\n";
@chmod($tarball, 0600);

/* 4. Optionally push to a private GitHub backup repo. */
if (!empty($cfg['git_remote'])) {
    $repo = sys_get_temp_dir() . '/wifiber-backup-repo-' . bin2hex(random_bytes(4));
    $branch = (string)$cfg['git_branch'];
    $clone = sprintf(
        'git clone --depth 1 --branch %s %s %s 2>&1',
        escapeshellarg($branch),
        escapeshellarg((string)$cfg['git_remote']),
        escapeshellarg($repo)
    );
    exec($clone, $out, $rc);
    if ($rc !== 0) {
        // Branch might not exist yet; clone without --branch and create it.
        exec('rm -rf ' . escapeshellarg($repo));
        exec(sprintf('git clone %s %s 2>&1',
            escapeshellarg((string)$cfg['git_remote']),
            escapeshellarg($repo)
        ), $out, $rc);
    }
    if ($rc === 0) {
        copy($tarball, $repo . '/' . $bundle);
        $push = sprintf(
            'cd %s && git checkout -B %s && git add -A && '
          . 'git -c user.name=%s -c user.email=%s commit -m %s --allow-empty && '
          . 'git push -u origin %s 2>&1',
            escapeshellarg($repo),
            escapeshellarg($branch),
            escapeshellarg((string)$cfg['git_committer_name']),
            escapeshellarg((string)$cfg['git_committer_email']),
            escapeshellarg("backup {$ts}"),
            escapeshellarg($branch)
        );
        exec($push, $out, $rc);
        if ($rc === 0) {
            echo "[backup] pushed {$bundle} to {$cfg['git_remote']} ({$branch})\n";
        } else {
            echo "[backup] git push FAILED rc={$rc}: " . implode("\n", array_slice($out, -10)) . "\n";
        }
    } else {
        echo "[backup] git clone FAILED rc={$rc}: " . implode("\n", array_slice($out, -10)) . "\n";
    }
    exec('rm -rf ' . escapeshellarg($repo));
}

/* 5. Cleanup work dir + prune old local backups. */
exec('rm -rf ' . escapeshellarg($work_dir));
$keep = max(1, (int)$cfg['keep_days']);
exec(sprintf(
    "find %s -maxdepth 1 -name 'wifiber-*.tar.gz' -mtime +%d -delete",
    escapeshellarg($cfg['local_dir']),
    $keep - 1
));
echo "[backup] kept last {$keep} day(s) in {$cfg['local_dir']}\n";
echo "[backup] done.\n";
