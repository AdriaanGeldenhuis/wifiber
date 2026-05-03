<?php
/**
 * Apply data/schema.sql to the configured database.
 *
 * Idempotent — every CREATE in the schema uses IF NOT EXISTS, so re-running
 * after a `git pull` is safe whether or not new tables were added. Run from
 * bin/deploy.sh on every deploy so schema changes ship with the code.
 *
 * Usage:  php bin/migrate-schema.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/helpers.php';

$schema = file_get_contents(__DIR__ . '/../data/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "could not read data/schema.sql\n");
    exit(1);
}

// Drop SQL line comments so the splitter doesn't trip over commented-out
// statements that happen to contain a semicolon.
$schema = preg_replace('/^\s*--.*$/m', '', $schema);

// Split on `;\n` — fine for our hand-crafted schema (no semicolons inside
// values). Keeps us off mysql CLI so the script works without the binary.
$statements = preg_split('/;\s*(?:\r?\n)/', $schema, -1, PREG_SPLIT_NO_EMPTY);

$pdo = pdo();
$ok = 0; $err = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    $first_line = trim(strtok($stmt, "\n"));
    try {
        $pdo->exec($stmt);
        echo "  ok: " . substr($first_line, 0, 75) . "\n";
        $ok++;
    } catch (Throwable $e) {
        echo "  ERR ($first_line): " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "Done. {$ok} statement(s) ok, {$err} error(s).\n";
exit($err > 0 ? 1 : 0);
