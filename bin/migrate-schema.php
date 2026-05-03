<?php
/**
 * Apply data/schema.sql (baseline) and every file in data/migrations/*.sql
 * to the configured database, in filename order.
 *
 * Idempotent — every CREATE uses IF NOT EXISTS, every ALTER uses ADD COLUMN /
 * ADD KEY IF NOT EXISTS, and seed data uses INSERT IGNORE. Re-running after a
 * `git pull` is safe whether or not new tables/columns were added. Run from
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

function apply_sql_file(PDO $pdo, string $path): array {
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "could not read $path\n");
        return [0, 1];
    }

    // Drop SQL line comments so the splitter doesn't trip over commented-out
    // statements that happen to contain a semicolon.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // Split on `;\n` — fine for our hand-crafted SQL (no semicolons inside
    // values). Keeps us off mysql CLI so the script works without the binary.
    $statements = preg_split('/;\s*(?:\r?\n)/', $sql, -1, PREG_SPLIT_NO_EMPTY);

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
    return [$ok, $err];
}

$pdo = pdo();
$total_ok = 0; $total_err = 0;

echo "[migrate] applying data/schema.sql\n";
[$ok, $err] = apply_sql_file($pdo, __DIR__ . '/../data/schema.sql');
$total_ok += $ok; $total_err += $err;

$migrations_dir = __DIR__ . '/../data/migrations';
if (is_dir($migrations_dir)) {
    $files = glob($migrations_dir . '/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        echo "\n[migrate] applying " . basename($file) . "\n";
        [$ok, $err] = apply_sql_file($pdo, $file);
        $total_ok += $ok; $total_err += $err;
    }
}

echo "\n[migrate] done. {$total_ok} statement(s) ok, {$total_err} error(s).\n";
exit($total_err > 0 ? 1 : 0);
