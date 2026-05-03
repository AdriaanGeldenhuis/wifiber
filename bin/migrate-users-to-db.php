<?php
/**
 * One-off migration: import data/users.json into the users table.
 *
 * Usage on the production server:
 *
 *   cd ~/public_html
 *   php bin/migrate-users-to-db.php
 *
 * Safe to re-run — usernames already in the DB are skipped, so a partial
 * run can be retried. The script reads users.json and never deletes it,
 * so you can verify the imported rows in phpMyAdmin before archiving the
 * JSON file by hand.
 *
 * Requires:
 *   - data/db.php with valid MySQL credentials
 *   - data/schema.sql already applied (see README)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/helpers.php';

$json_path = __DIR__ . '/../data/users.json';
if (!is_file($json_path)) {
    fwrite(STDERR, "No data/users.json found — nothing to migrate.\n");
    exit(0);
}

$raw = file_get_contents($json_path);
$data = json_decode((string)$raw, true);
$users = is_array($data) ? ($data['users'] ?? []) : [];

if (!$users) {
    echo "users.json is empty — nothing to migrate.\n";
    exit(0);
}

echo "Found " . count($users) . " user(s) in data/users.json\n";

$insert = pdo()->prepare(
    "INSERT INTO users
        (username, email, name, role, phone, address, package,
         password_hash, totp_secret, totp_enabled, totp_recovery_codes,
         totp_enabled_at, created_at, last_login)
     VALUES
        (:username, :email, :name, :role, :phone, :address, :package,
         :password_hash, :totp_secret, :totp_enabled, :totp_recovery_codes,
         :totp_enabled_at, :created_at, :last_login)"
);

$imported = 0;
$skipped  = 0;

pdo()->beginTransaction();
try {
    foreach ($users as $u) {
        $username = (string)($u['username'] ?? '');
        if ($username === '') {
            echo "  skip: row without username\n";
            $skipped++;
            continue;
        }
        if (find_user_by_username($username)) {
            echo "  skip (exists in DB): {$username}\n";
            $skipped++;
            continue;
        }

        $codes = $u['totp_recovery_codes'] ?? null;
        $codes = (is_array($codes) && $codes) ? json_encode(array_values($codes)) : null;

        $insert->execute([
            ':username'            => $username,
            ':email'               => (string)($u['email']   ?? ''),
            ':name'                => (string)($u['name']    ?? ''),
            ':role'                => in_array($u['role'] ?? '', ['admin','client'], true) ? $u['role'] : 'client',
            ':phone'               => (string)($u['phone']   ?? ''),
            ':address'             => (string)($u['address'] ?? ''),
            ':package'             => (string)($u['package'] ?? ''),
            ':password_hash'       => (string)($u['password_hash'] ?? ''),
            ':totp_secret'         => $u['totp_secret'] ?? null,
            ':totp_enabled'        => !empty($u['totp_enabled']) ? 1 : 0,
            ':totp_recovery_codes' => $codes,
            ':totp_enabled_at'     => iso_to_datetime($u['totp_enabled_at'] ?? null),
            ':created_at'          => iso_to_datetime($u['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
            ':last_login'          => iso_to_datetime($u['last_login'] ?? null),
        ]);
        echo "  imported: {$username} ({$u['role']})\n";
        $imported++;
    }
    pdo()->commit();
} catch (Throwable $e) {
    pdo()->rollBack();
    fwrite(STDERR, "Migration FAILED, no rows kept: {$e->getMessage()}\n");
    exit(1);
}

echo "\nDone. {$imported} imported, {$skipped} skipped.\n";
echo "data/users.json is left intact. Once you've verified the users in phpMyAdmin,\n";
echo "you can archive it with:  mv data/users.json data/users.json.migrated\n";

function iso_to_datetime($v): ?string {
    if ($v === null || $v === '') return null;
    $ts = is_int($v) ? $v : strtotime((string)$v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
