<?php
/**
 * FreeRADIUS direct-DB importer.
 *
 * Reads from a *separate* FreeRADIUS MySQL/MariaDB database (the one
 * that's been authenticating PPPoE / hotspot users for years), and
 * upserts rows into our radcheck / radreply / radusergroup / radacct
 * tables.  Useful when:
 *
 *   • cutting over from a legacy FreeRADIUS install with no UI;
 *   • backfilling historical sessions for the bandwidth-usage report;
 *   • taking over a customer base that only has RADIUS records on file.
 *
 * Source DB credentials live in data/db.local.php (or data/db.php) under
 * the 'import_radius' key:
 *
 *   return [
 *     'host' => '...', 'db' => '...', 'user' => '...', 'pass' => '...',
 *     'port' => 3306,
 *     'import_radius' => [
 *       'host' => 'old-radius.example.com',
 *       'db'   => 'radius', 'user' => 'reader', 'pass' => '...',
 *       'port' => 3306,
 *     ],
 *   ];
 *
 * Usage:
 *   php bin/import-radius.php [--dry-run] [--limit=N]
 *                              [--only=radcheck,radreply,radusergroup,radacct]
 *                              [--since=YYYY-MM-DD]    # radacct cutoff
 *
 * Idempotent: radcheck/radreply/radusergroup are keyed on (username,
 * attribute) so re-running replaces in place; radacct is keyed on
 * acctuniqueid (the canonical FreeRADIUS unique-session id).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/importers.php';

const RADIUS_RESOURCES = ['radcheck', 'radreply', 'radusergroup', 'radacct'];

$opts = [
    'dry-run' => false,
    'limit'   => 0,
    'only'    => RADIUS_RESOURCES,
    'since'   => date('Y-m-d', strtotime('-90 days')),
];
$rest = importer_parse_common_args($argv, $opts);
foreach ($rest as $a) {
    if (preg_match('/^--since=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $opts['since'] = $m[1]; continue; }
    fwrite(STDERR, "unknown arg: $a\n"); exit(2);
}
$opts['only'] = array_values(array_intersect($opts['only'], RADIUS_RESOURCES));

$source = radius_source_pdo();
if ($source === null) {
    fwrite(STDERR, "Source RADIUS DB is not configured (data/db.php → 'import_radius').\n");
    exit(2);
}

echo "[radius-import] resources=" . implode(',', $opts['only']) . " since={$opts['since']}" . ($opts['dry-run'] ? ' (DRY RUN)' : '') . "\n";

if (in_array('radcheck',     $opts['only'], true)) radius_import_check($source,  $opts);
if (in_array('radreply',     $opts['only'], true)) radius_import_reply($source,  $opts);
if (in_array('radusergroup', $opts['only'], true)) radius_import_groups($source, $opts);
if (in_array('radacct',      $opts['only'], true)) radius_import_acct($source,   $opts);

echo "[radius-import] done.\n";

/* ============================================================ functions */

function radius_source_pdo(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg_file = is_file(DATA_DIR . '/db.local.php')
        ? DATA_DIR . '/db.local.php'
        : DATA_DIR . '/db.php';
    if (!is_file($cfg_file)) return null;
    $cfg = require $cfg_file;
    if (!is_array($cfg) || empty($cfg['import_radius']) || !is_array($cfg['import_radius'])) return null;
    $src = $cfg['import_radius'];
    if (empty($src['host']) || empty($src['db']) || empty($src['user'])) return null;

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $src['host'], (int)($src['port'] ?? 3306), $src['db']);
    try {
        $pdo = new PDO($dsn, $src['user'], (string)($src['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "could not connect to source RADIUS DB: {$e->getMessage()}\n");
        return null;
    }
    return $pdo;
}

function radius_import_check(PDO $src, array $opts): void {
    $run = importer_run_begin('radius', 'radcheck', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- radcheck ---\n";

    $sql = "SELECT username, attribute, op, value FROM radcheck";
    if ($opts['limit'] > 0) $sql .= " LIMIT " . (int)$opts['limit'];
    foreach ($src->query($sql) as $row) {
        $c->total++;
        if ($opts['dry-run']) { $c->created++; continue; }
        try {
            // Idempotent within (username, attribute): delete + insert.
            pdo()->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = ?")
                 ->execute([(string)$row['username'], (string)$row['attribute']]);
            pdo()->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)")
                 ->execute([
                     mb_substr((string)$row['username'], 0, 64),
                     mb_substr((string)$row['attribute'], 0, 64),
                     mb_substr((string)$row['op'], 0, 2),
                     mb_substr((string)$row['value'], 0, 253),
                 ]);
            $c->created++;
        } catch (Throwable $e) {
            $c->failed++;
            echo "  ! radcheck {$row['username']}/{$row['attribute']}: {$e->getMessage()}\n";
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function radius_import_reply(PDO $src, array $opts): void {
    $run = importer_run_begin('radius', 'radreply', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- radreply ---\n";

    $sql = "SELECT username, attribute, op, value FROM radreply";
    if ($opts['limit'] > 0) $sql .= " LIMIT " . (int)$opts['limit'];
    foreach ($src->query($sql) as $row) {
        $c->total++;
        if ($opts['dry-run']) { $c->created++; continue; }
        try {
            pdo()->prepare("DELETE FROM radreply WHERE username = ? AND attribute = ?")
                 ->execute([(string)$row['username'], (string)$row['attribute']]);
            pdo()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ?, ?)")
                 ->execute([
                     mb_substr((string)$row['username'], 0, 64),
                     mb_substr((string)$row['attribute'], 0, 64),
                     mb_substr((string)$row['op'], 0, 2),
                     mb_substr((string)$row['value'], 0, 253),
                 ]);
            $c->created++;
        } catch (Throwable $e) {
            $c->failed++;
            echo "  ! radreply {$row['username']}/{$row['attribute']}: {$e->getMessage()}\n";
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function radius_import_groups(PDO $src, array $opts): void {
    $run = importer_run_begin('radius', 'radusergroup', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- radusergroup ---\n";

    $sql = "SELECT username, groupname, priority FROM radusergroup";
    if ($opts['limit'] > 0) $sql .= " LIMIT " . (int)$opts['limit'];
    foreach ($src->query($sql) as $row) {
        $c->total++;
        if ($opts['dry-run']) { $c->created++; continue; }
        try {
            pdo()->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([(string)$row['username']]);
            pdo()->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, ?)")
                 ->execute([
                     mb_substr((string)$row['username'], 0, 64),
                     mb_substr((string)$row['groupname'], 0, 64),
                     (int)($row['priority'] ?? 1),
                 ]);
            $c->created++;
        } catch (Throwable $e) {
            $c->failed++;
            echo "  ! radusergroup {$row['username']}: {$e->getMessage()}\n";
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function radius_import_acct(PDO $src, array $opts): void {
    $run = importer_run_begin('radius', 'radacct', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- radacct (since {$opts['since']}) ---\n";

    $cols = "acctuniqueid, acctsessionid, username, realm, nasipaddress,
             acctstarttime, acctupdatetime, acctstoptime, acctsessiontime,
             acctauthentic, connectinfo_start, connectinfo_stop,
             acctinputoctets, acctoutputoctets,
             calledstationid, callingstationid, acctterminatecause,
             servicetype, framedprotocol, framedipaddress";

    $sql = "SELECT $cols FROM radacct WHERE COALESCE(acctstarttime, acctupdatetime) >= ?";
    if ($opts['limit'] > 0) $sql .= " LIMIT " . (int)$opts['limit'];
    $stmt = $src->prepare($sql);
    $stmt->execute([$opts['since']]);

    while ($row = $stmt->fetch()) {
        $c->total++;
        $uid = (string)($row['acctuniqueid'] ?? '');
        if ($uid === '') { $c->failed++; continue; }
        if ($opts['dry-run']) { $c->created++; continue; }

        try {
            // Upsert keyed on acctuniqueid (the FreeRADIUS-canonical id).
            $exists = pdo()->prepare("SELECT radacctid FROM radacct WHERE acctuniqueid = ? LIMIT 1");
            $exists->execute([$uid]);
            $existing_id = $exists->fetchColumn();
            $args = [
                $uid,
                mb_substr((string)$row['acctsessionid'], 0, 64),
                mb_substr((string)$row['username'],     0, 64),
                mb_substr((string)$row['realm'],        0, 64),
                mb_substr((string)$row['nasipaddress'], 0, 15),
                $row['acctstarttime']  ?: null,
                $row['acctupdatetime'] ?: null,
                $row['acctstoptime']   ?: null,
                $row['acctsessiontime'] !== null ? (int)$row['acctsessiontime'] : null,
                mb_substr((string)$row['acctauthentic'], 0, 32),
                mb_substr((string)$row['connectinfo_start'], 0, 50),
                mb_substr((string)$row['connectinfo_stop'],  0, 50),
                $row['acctinputoctets']  !== null ? (int)$row['acctinputoctets']  : null,
                $row['acctoutputoctets'] !== null ? (int)$row['acctoutputoctets'] : null,
                mb_substr((string)$row['calledstationid'],  0, 50),
                mb_substr((string)$row['callingstationid'], 0, 50),
                mb_substr((string)$row['acctterminatecause'], 0, 32),
                mb_substr((string)$row['servicetype'],    0, 32),
                mb_substr((string)$row['framedprotocol'], 0, 32),
                mb_substr((string)$row['framedipaddress'], 0, 15),
            ];
            if ($existing_id) {
                pdo()->prepare(
                    "UPDATE radacct
                        SET acctuniqueid=?, acctsessionid=?, username=?, realm=?, nasipaddress=?,
                            acctstarttime=?, acctupdatetime=?, acctstoptime=?, acctsessiontime=?,
                            acctauthentic=?, connectinfo_start=?, connectinfo_stop=?,
                            acctinputoctets=?, acctoutputoctets=?,
                            calledstationid=?, callingstationid=?, acctterminatecause=?,
                            servicetype=?, framedprotocol=?, framedipaddress=?
                      WHERE radacctid=?"
                )->execute(array_merge($args, [(int)$existing_id]));
                $c->updated++;
            } else {
                pdo()->prepare(
                    "INSERT INTO radacct
                        (acctuniqueid, acctsessionid, username, realm, nasipaddress,
                         acctstarttime, acctupdatetime, acctstoptime, acctsessiontime,
                         acctauthentic, connectinfo_start, connectinfo_stop,
                         acctinputoctets, acctoutputoctets,
                         calledstationid, callingstationid, acctterminatecause,
                         servicetype, framedprotocol, framedipaddress)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute($args);
                $c->created++;
            }
        } catch (Throwable $e) {
            $c->failed++;
            echo "  ! radacct $uid: {$e->getMessage()}\n";
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}
