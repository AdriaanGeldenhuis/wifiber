<?php
/**
 * Shared auth helpers for admin and client portals.
 *
 * Storage:
 *   MySQL          — users, throttle, password_resets (see data/schema.sql)
 *   data/db.php    — DB credentials (gitignored, see data/db.php.example)
 *   data/admin-ips.json — optional admin IP allowlist (empty = open)
 *
 * Sessions are HttpOnly + Secure (when over HTTPS) + SameSite=Lax.
 * CSRF tokens are required on all POSTs.
 */

declare(strict_types=1);

const DATA_DIR        = __DIR__ . '/../data';
const ADMIN_IPS_FILE  = DATA_DIR . '/admin-ips.json';

const MAX_LOGIN_FAILS = 5;
const LOCKOUT_SECS    = 900;   // 15 min
const PW_RESET_TTL    = 3600;  // 1 hour
const SESSION_NAME    = 'wfsess';

const POST_RATE_LIMIT_MAX    = 30;       // POSTs per IP
const POST_RATE_LIMIT_WINDOW = 60;       // seconds
const SESSION_LIFETIME       = 2592000;  // 30 days — applies to every user

/* --------------------------------------------------------------- session */

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name(SESSION_NAME);
    // Keep the server-side session file alive at least as long as the cookie,
    // otherwise PHP's GC reaps it within minutes and the cookie becomes useless.
    @ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ----------------------------------------------------------------- json */

function json_load(string $path, array $default = []): array {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $default;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
}

function json_save(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $bytes = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($bytes === false) return false;
    return @rename($tmp, $path);
}

/* ------------------------------------------------------------------ pdo */

function pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // data/db.local.php (gitignored) wins over data/db.php so a developer
    // can point the same code at a local MariaDB without touching prod creds.
    $cfg_file = is_file(DATA_DIR . '/db.local.php')
        ? DATA_DIR . '/db.local.php'
        : DATA_DIR . '/db.php';
    if (!is_file($cfg_file)) {
        http_response_code(500);
        die('Database is not configured. Copy data/db.php.example to data/db.php and fill in the credentials.');
    }
    $cfg = require $cfg_file;
    if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['db']) || empty($cfg['user'])) {
        http_response_code(500);
        die('data/db.php is incomplete. Expected keys: host, db, user, pass (port optional).');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'], (int)($cfg['port'] ?? 3306), $cfg['db']
    );
    try {
        $pdo = new PDO($dsn, $cfg['user'], (string)($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die('Could not connect to the database. Check data/db.php and that the database server is reachable.');
    }
    return $pdo;
}

/* -------------------------------------------------------- secret crypto */

/**
 * Encrypt a secret (radio password / SSH key / SNMP community / API token)
 * for at-rest storage in `device_credentials.*_enc` blobs.
 *
 * Uses libsodium's authenticated secretbox. The key must be 32 random bytes
 * configured in data/db.local.php as 'device_key' (raw binary or base64;
 * we accept either). Without that key the function returns NULL — callers
 * should refuse to enqueue config-push jobs in that case.
 *
 * On decrypt failure (corruption, wrong key, missing key) returns NULL.
 * Never throws — the caller decides how loud to be.
 */
function device_secret_key(): ?string {
    static $key = null;
    if ($key !== null) return $key === '' ? null : $key;
    $cfg_file = is_file(DATA_DIR . '/db.local.php')
        ? DATA_DIR . '/db.local.php'
        : DATA_DIR . '/db.php';
    if (!is_file($cfg_file)) { $key = ''; return null; }
    $cfg = require $cfg_file;
    $raw = is_array($cfg) ? ($cfg['device_key'] ?? '') : '';
    if (!is_string($raw) || $raw === '') { $key = ''; return null; }
    if (strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $raw = $decoded;
        } else {
            $key = '';
            return null;
        }
    }
    $key = $raw;
    return $key;
}

function encrypt_secret(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    $key = device_secret_key();
    if ($key === null) return null;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return $nonce . $cipher;
}

function decrypt_secret(?string $blob): ?string {
    if ($blob === null || $blob === '') return null;
    $key = device_secret_key();
    if ($key === null) return null;
    if (strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        return null;
    }
    $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? null : $plain;
}

/* ----------------------------------------------------------------- csrf */

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_check(): bool {
    $supplied = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    return !empty($_SESSION['_csrf'])
        && is_string($supplied)
        && hash_equals($_SESSION['_csrf'], $supplied);
}

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!csrf_check()) {
        http_response_code(419);
        die('CSRF token invalid. Please refresh and try again.');
    }
}

/* ---------------------------------------------------------------- users */

const USER_COLUMNS = [
    'account_no','username','email','name','surname','id_number','vat_number',
    'role','customer_type','status','service_start','billing_day','payment_method',
    'phone','phone_e164','address','lat','lng','alt_contact_name','alt_contact_phone',
    'package','product_id','site_id','sector_id','equipment_mac','equipment_ip','equipment_serial','equipment_model',
    'notes','notify_prefs',
    'password_hash','totp_secret','totp_enabled','totp_recovery_codes',
    'totp_enabled_at','last_login',
];

const CUSTOMER_TYPES   = ['residential', 'business'];
const CUSTOMER_STATUS  = ['active', 'lead', 'suspended', 'disconnected'];
const PAYMENT_METHODS  = ['eft', 'debit_order', 'cash', 'card'];

function row_to_user(?array $row): ?array {
    if (!$row) return null;
    $row['id']           = (int)$row['id'];
    $row['totp_enabled'] = !empty($row['totp_enabled']);
    $codes = $row['totp_recovery_codes'] ?? null;
    if (is_string($codes) && $codes !== '') {
        $decoded = json_decode($codes, true);
        $row['totp_recovery_codes'] = is_array($decoded) ? $decoded : [];
    } else {
        $row['totp_recovery_codes'] = [];
    }
    return $row;
}

function load_users(): array {
    $stmt = pdo()->query("SELECT * FROM users ORDER BY id ASC");
    $out = [];
    foreach ($stmt as $row) $out[] = row_to_user($row);
    return $out;
}

function find_user_by_username(string $username): ?array {
    $stmt = pdo()->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    return row_to_user($stmt->fetch() ?: null);
}

function find_user_by_username_or_email(string $needle): ?array {
    $needle = trim($needle);
    if ($needle === '') return null;
    $stmt = pdo()->prepare(
        "SELECT * FROM users WHERE username = ? OR (email <> '' AND email = ?) LIMIT 1"
    );
    $stmt->execute([$needle, $needle]);
    return row_to_user($stmt->fetch() ?: null);
}

function find_user_by_id(int $id): ?array {
    $stmt = pdo()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return row_to_user($stmt->fetch() ?: null);
}

function update_user(int $id, callable $patch): bool {
    $current = find_user_by_id($id);
    if (!$current) return false;
    $patched = $patch($current);
    if (!is_array($patched)) return false;

    $set  = [];
    $args = [];
    foreach (USER_COLUMNS as $c) {
        if (!array_key_exists($c, $patched)) continue;
        $v = $patched[$c];

        if ($c === 'totp_recovery_codes') {
            $v = (is_array($v) && $v) ? json_encode(array_values($v)) : null;
        } elseif ($c === 'totp_enabled') {
            $v = $v ? 1 : 0;
        } elseif (in_array($c, ['totp_enabled_at','last_login'], true)) {
            if ($v === null || $v === '') {
                $v = null;
            } else {
                $ts = is_int($v) ? $v : strtotime((string)$v);
                $v  = $ts ? date('Y-m-d H:i:s', $ts) : null;
            }
        } elseif ($c === 'service_start') {
            if ($v === null || $v === '') {
                $v = null;
            } else {
                $ts = is_int($v) ? $v : strtotime((string)$v);
                $v  = $ts ? date('Y-m-d', $ts) : null;
            }
        } elseif ($c === 'billing_day') {
            $v = ($v === null || $v === '' || !is_numeric($v)) ? null : max(1, min(31, (int)$v));
        } elseif (in_array($c, ['lat','lng'], true)) {
            $v = ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
        } elseif ($c === 'customer_type') {
            $v = in_array($v, CUSTOMER_TYPES, true) ? $v : 'residential';
        } elseif ($c === 'status') {
            $v = in_array($v, CUSTOMER_STATUS, true) ? $v : 'active';
        } elseif ($c === 'payment_method') {
            $v = in_array($v, PAYMENT_METHODS, true) ? $v : 'eft';
        } elseif ($c === 'product_id' || $c === 'site_id' || $c === 'sector_id') {
            $v = ($v === null || $v === '' || !is_numeric($v) || (int)$v <= 0) ? null : (int)$v;
        }

        $set[]  = "`$c` = ?";
        $args[] = $v;
    }
    if (!$set) return true;

    $args[] = $id;
    $sql = "UPDATE users SET " . implode(', ', $set) . " WHERE id = ?";
    $stmt = pdo()->prepare($sql);
    return $stmt->execute($args);
}

function create_user(string $username, string $password, string $role, string $name, string $email = '', array $extra = []): array {
    if (!in_array($role, ['admin', 'client'], true)) {
        throw new InvalidArgumentException("role must be admin or client");
    }
    if (find_user_by_username($username)) {
        throw new RuntimeException("A user with that username already exists.");
    }

    $surname       = trim((string)($extra['surname'] ?? ''));
    $customer_type = in_array($extra['customer_type'] ?? '', CUSTOMER_TYPES, true)
                       ? $extra['customer_type'] : 'residential';

    // Account numbers are client-only — admin staff don't get one.
    $account_no = null;
    if ($role === 'client') {
        if ($surname === '') {
            throw new RuntimeException("Surname is required so we can issue an account number.");
        }
        $account_no = generate_account_no($surname);
    }

    $stmt = pdo()->prepare(
        "INSERT INTO users
            (account_no, username, email, name, surname, role, customer_type,
             phone, address, package, password_hash, created_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $account_no,
        $username,
        $email,
        $name,
        $surname,
        $role,
        $customer_type,
        trim((string)($extra['phone']   ?? '')),
        trim((string)($extra['address'] ?? '')),
        trim((string)($extra['package'] ?? '')),
        password_hash($password, PASSWORD_DEFAULT),
    ]);
    $id = (int)pdo()->lastInsertId();
    audit_log('user.create', [
        'target_type' => 'user',
        'target_id'   => $id,
        'meta'        => ['username' => $username, 'role' => $role, 'email' => $email, 'account_no' => $account_no],
    ]);
    return find_user_by_id($id) ?? [];
}

/**
 * Issue the next account number for a given surname.
 *
 * Format: first three letters of the surname (uppercase, A-Z only, padded
 * with X if the surname is shorter than three letters) followed by a
 * zero-padded 4-digit sequence — e.g. "Geldenhuis" -> GEL0001, GEL0002, ...
 *
 * The sequence is per-prefix. Two surnames that share the same prefix
 * share a counter (SMITH and SMILE both produce SMI####).
 */
function generate_account_no(string $surname): string {
    $prefix = preg_replace('/[^A-Z]/', '', strtoupper($surname));
    $prefix = substr($prefix . 'XXX', 0, 3);

    $stmt = pdo()->prepare(
        "SELECT account_no
           FROM users
          WHERE account_no LIKE ?
          ORDER BY LENGTH(account_no) DESC, account_no DESC
          LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $next = 1;
    if ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function delete_user(int $id): bool {
    $u  = find_user_by_id($id);
    $ok = pdo()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    if ($ok && $u) {
        audit_log('user.delete', [
            'target_type' => 'user',
            'target_id'   => $id,
            'meta'        => ['username' => $u['username'] ?? '', 'role' => $u['role'] ?? ''],
        ]);
    }
    return $ok;
}

/* --------------------------------------------------------------- login */

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_locked_out(string $ip): bool {
    $stmt = pdo()->prepare("SELECT fails, last_fail FROM throttle WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if ((int)$row['fails'] < MAX_LOGIN_FAILS) return false;
    if (time() - (int)$row['last_fail'] > LOCKOUT_SECS) {
        $del = pdo()->prepare("DELETE FROM throttle WHERE ip = ?");
        $del->execute([$ip]);
        return false;
    }
    return true;
}

function record_login_fail(string $ip): void {
    $stmt = pdo()->prepare(
        "INSERT INTO throttle (ip, fails, last_fail) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE fails = fails + 1, last_fail = VALUES(last_fail)"
    );
    $stmt->execute([$ip, time()]);
}

function reset_login_fails(string $ip): void {
    $stmt = pdo()->prepare("DELETE FROM throttle WHERE ip = ?");
    $stmt->execute([$ip]);
}

function attempt_login(string $username, string $password, string $required_role): ?array {
    $ip = client_ip();
    if (is_locked_out($ip)) return null;
    $user = find_user_by_username($username);
    $valid = $user && password_verify($password, $user['password_hash'] ?? '');
    if (!$valid || ($user['role'] ?? '') !== $required_role) {
        record_login_fail($ip);
        audit_log('login.fail', ['meta' => ['username' => $username, 'role' => $required_role]]);
        return null;
    }
    reset_login_fails($ip);
    audit_log('login.success', [
        'user_id'     => (int)$user['id'],
        'username'    => (string)$user['username'],
        'target_type' => 'user',
        'target_id'   => (int)$user['id'],
        'meta'        => ['role' => $user['role']],
    ]);

    // Re-hash if PHP's defaults have moved on
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        update_user((int)$user['id'], function (array $u) use ($password) {
            $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            return $u;
        });
    }

    session_regenerate_id(true);
    $_SESSION['user_id']        = (int)$user['id'];
    $_SESSION['user_role']      = $user['role'];
    $_SESSION['user_name']      = $user['name'];
    $_SESSION['logged_in_at']   = time();

    update_user((int)$user['id'], function (array $u) {
        $u['last_login'] = date('c');
        return $u;
    });

    return $user;
}

function logout(): void {
    if (!empty($_SESSION['user_id'])) {
        audit_log('logout', [
            'user_id'     => (int)$_SESSION['user_id'],
            'target_type' => 'user',
            'target_id'   => (int)$_SESSION['user_id'],
        ]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return find_user_by_id((int)$_SESSION['user_id']);
}

const ACL_STAFF_ROLES_FALLBACK = [
    'super_admin','admin','billing','support','technician','noc_readonly','viewer',
];

function require_role($role, string $login_url): array {
    $user = current_user();
    if (!$user) {
        header('Location: ' . $login_url);
        exit;
    }
    $own_role = $user['role'] ?? '';
    $allowed  = is_array($role) ? $role : [$role];
    if (!in_array($own_role, $allowed, true)) {
        if (in_array($own_role, ACL_STAFF_ROLES_FALLBACK, true)) {
            header('Location: /admin/');   exit;
        }
        if ($own_role === 'client') { header('Location: /account/'); exit; }
        http_response_code(403);
        die('Access denied.');
    }
    return $user;
}

/**
 * Inside admin pages: returns true if the current operator can mutate
 * network state (push-to-radio, edit sectors, queue jobs, …). The
 * binary admin / noc_readonly check has been replaced with a role
 * allow-list — super_admin, admin and technician have write access by
 * default; other staff roles can still be granted finer capabilities
 * via auth/acl.php (acl_require).
 */
function admin_can_write(?array $user = null): bool {
    $user = $user ?? current_user();
    $role = (string)($user['role'] ?? '');
    return in_array($role, ['super_admin','admin','technician'], true);
}

function require_admin_write(): void {
    if (!admin_can_write()) {
        http_response_code(403);
        die('This action requires a write-capable role (admin / super_admin / technician). Read-only accounts can view but not change network state.');
    }
}

/* ---------------------------------------------------------------- flash */

/**
 * Append a `?v=<mtime>` cache-buster to a static-asset URL so a
 * deployment automatically invalidates the browser cache without
 * waiting for users to hard-refresh.
 *
 * Usage:  <link rel="stylesheet" href="<?= asset_url('/assets/css/portal.css') ?>">
 */
function asset_url(string $rel): string {
    $abs = __DIR__ . '/..' . $rel;
    $v   = @filemtime($abs);
    return $v ? $rel . '?v=' . $v : $rel;
}

function flash(string $type, string $message): void {
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function pop_flash(): ?array {
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

function render_flash(): string {
    $f = pop_flash();
    if (!$f) return '';
    $cls = $f['type'] === 'success' ? 'alert-success' : 'alert-error';
    return '<div class="alert ' . $cls . '">' . htmlspecialchars($f['message']) . '</div>';
}

/* ----------------------------------------------------------- admin IPs */

function admin_ip_list(): array {
    $d = json_load(ADMIN_IPS_FILE, ['ips' => []]);
    return $d['ips'] ?? [];
}

function save_admin_ips(array $ips): bool {
    return json_save(ADMIN_IPS_FILE, ['ips' => array_values(array_unique($ips))]);
}

function is_admin_ip_allowed(string $ip): bool {
    $list = admin_ip_list();
    if (empty($list)) return true; // empty list = no restriction
    return in_array($ip, $list, true);
}

function require_admin_ip(): void {
    $ip = client_ip();
    if (!is_admin_ip_allowed($ip)) {
        http_response_code(403);
        die('Admin access is restricted to allow-listed IP addresses. Your IP (' . htmlspecialchars($ip) . ') is not on the list.');
    }
}

/* ---------------------------------------------------------- bootstrap */

function any_admin_exists(): bool {
    $stmt = pdo()->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1");
    return (bool)$stmt->fetchColumn();
}

/* ---------------------------------------------------------------- email */

function load_site_settings(): array {
    $f = DATA_DIR . '/site.json';
    return is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
}

function send_welcome_email(array $user, string $temporary_password, ?string $base_url = null): array {
    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'no email on file'];
    }
    $site      = load_site_settings();
    $site_name = $site['name']    ?? 'WiFIBER';
    $support   = $site['email_support'] ?? 'support@wifiber.co.za';
    $phone     = $site['phone']   ?? '';
    $base      = $base_url ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za'));
    $login_url = rtrim($base, '/') . '/account/login.php';

    $name = $user['name'] ?? $user['username'];
    $body = "Hi {$name},\n\n"
          . "Welcome to {$site_name}! Your customer portal account is ready.\n\n"
          . "Sign in at: {$login_url}\n\n"
          . "Username: {$user['username']}\n"
          . "Temporary password: {$temporary_password}\n\n"
          . "Please log in and change your password as soon as you can.\n\n"
          . "Need a hand? Reply to this email or call us"
          . ($phone ? " on {$phone}" : '') . ".\n\n"
          . "— The {$site_name} team\n";

    $headers = "From: {$site_name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . "Reply-To: {$support}\r\n"
             . "X-Mailer: WiFIBER-Portal\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($user['email'], "Welcome to {$site_name}", $body, $headers);
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}

/* ------------------------------------------------------- password reset */
/*
 * Tokens use a selector + validator scheme. The full token (selector.validator)
 * is sent in the email link; only the selector and a SHA-256 hash of the
 * validator are persisted, so a leaked password_resets row cannot be replayed.
 */

function pw_reset_purge_expired(): void {
    $stmt = pdo()->prepare("DELETE FROM password_resets WHERE expires_at <= ?");
    $stmt->execute([time()]);
}

function pw_reset_create_token(int $user_id): string {
    pw_reset_purge_expired();
    // One outstanding link per user — drop any older tokens.
    $del = pdo()->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $del->execute([$user_id]);

    $selector  = bin2hex(random_bytes(8));   // 16 hex chars
    $validator = bin2hex(random_bytes(32));  // 64 hex chars
    $stmt = pdo()->prepare(
        "INSERT INTO password_resets (selector, validator_hash, user_id, expires_at, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$selector, hash('sha256', $validator), $user_id, time() + PW_RESET_TTL]);
    return $selector . '.' . $validator;
}

function pw_reset_lookup(string $token): ?array {
    $token = trim($token);
    if ($token === '' || strpos($token, '.') === false) return null;
    [$selector, $validator] = explode('.', $token, 2);
    if ($selector === '' || $validator === '') return null;

    pw_reset_purge_expired();
    $stmt = pdo()->prepare("SELECT * FROM password_resets WHERE selector = ? LIMIT 1");
    $stmt->execute([$selector]);
    $r = $stmt->fetch();
    if (!$r) return null;
    if (!hash_equals((string)$r['validator_hash'], hash('sha256', $validator))) return null;
    if ((int)$r['expires_at'] <= time()) return null;
    $user = find_user_by_id((int)$r['user_id']);
    if (!$user) return null;
    return ['reset' => $r, 'user' => $user];
}

function pw_reset_consume(string $token): ?array {
    $found = pw_reset_lookup($token);
    if (!$found) return null;
    $del = pdo()->prepare("DELETE FROM password_resets WHERE selector = ?");
    $del->execute([(string)$found['reset']['selector']]);
    return $found['user'];
}

function pw_reset_invalidate_for_user(int $user_id): void {
    $stmt = pdo()->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $stmt->execute([$user_id]);
}

function send_password_reset_email(array $user, string $token, string $reset_path, ?string $base_url = null): array {
    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'no email on file'];
    }
    $site      = load_site_settings();
    $site_name = $site['name']    ?? 'WiFIBER';
    $support   = $site['email_support'] ?? 'support@wifiber.co.za';
    $phone     = $site['phone']   ?? '';
    $base      = $base_url ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za'));
    $reset_url = rtrim($base, '/') . $reset_path . '?token=' . urlencode($token);

    $name = $user['name'] ?? $user['username'];
    $body = "Hi {$name},\n\n"
          . "Someone (hopefully you) asked to reset your {$site_name} password.\n\n"
          . "Click the link below to choose a new one. The link is good for 1 hour:\n"
          . "{$reset_url}\n\n"
          . "If you didn't ask for this, you can ignore this email — your\n"
          . "current password will keep working.\n\n"
          . "Need a hand? Reply to this email or call us"
          . ($phone ? " on {$phone}" : '') . ".\n\n"
          . "— The {$site_name} team\n";

    $headers = "From: {$site_name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . "Reply-To: {$support}\r\n"
             . "X-Mailer: WiFIBER-Portal\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($user['email'], "Reset your {$site_name} password", $body, $headers);
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}

/* ----------------------------------------------------------- audit log */
/*
 * Lightweight audit trail. Anything security- or money-relevant calls
 * audit_log() with a verb (e.g. "user.create", "invoice.paid") plus
 * optional structured context. The current user / IP are captured
 * automatically.
 */

function audit_log(string $action, array $opts = []): void {
    try {
        $user_id  = $opts['user_id']  ?? ($_SESSION['user_id'] ?? null);
        // Prefer an explicit username, then a DB lookup by id, then fall back to
        // the session's display name only if nothing else is known. This keeps
        // audit rows readable even when the call site only has the id.
        $username = $opts['username'] ?? null;
        if (!$username && $user_id !== null) {
            $u = find_user_by_id((int)$user_id);
            if ($u) $username = $u['username'];
        }
        if (!$username) $username = $_SESSION['user_name'] ?? null;
        $stmt = pdo()->prepare(
            "INSERT INTO audit_log
                (user_id, username, action, target_type, target_id, meta, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $meta = $opts['meta'] ?? null;
        $stmt->execute([
            $user_id ? (int)$user_id : null,
            $username ? mb_substr((string)$username, 0, 60) : null,
            mb_substr($action, 0, 60),
            isset($opts['target_type']) ? mb_substr((string)$opts['target_type'], 0, 40) : null,
            isset($opts['target_id'])   ? (int)$opts['target_id'] : null,
            ($meta && is_array($meta)) ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        // Auditing must never break the parent action; log to error_log and move on.
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function audit_recent(
    int $limit = 200,
    ?string $action_like = null,
    ?int $user_id = null,
    ?string $from_iso = null,
    ?string $to_iso = null
): array {
    $sql  = "SELECT * FROM audit_log";
    $args = [];
    $where = [];
    if ($action_like !== null && $action_like !== '') {
        $where[] = "action LIKE ?";
        $args[]  = '%' . $action_like . '%';
    }
    if ($user_id) {
        $where[] = "user_id = ?";
        $args[]  = $user_id;
    }
    if ($from_iso) {
        $where[] = "created_at >= ?";
        $args[]  = $from_iso . ' 00:00:00';
    }
    if ($to_iso) {
        $where[] = "created_at <= ?";
        $args[]  = $to_iso . ' 23:59:59';
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $limit = max(1, min(50000, $limit));
    $sql .= " ORDER BY id DESC LIMIT $limit";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------- rate limiting */
/*
 * Simple per-bucket counter with a fixed window. Used by
 * enforce_global_post_rate_limit() (called below for every POST) to cap how
 * many writes a single IP can do in a minute. Login throttling is separate
 * (see is_locked_out / record_login_fail above).
 */

function rate_limit_check(string $bucket, int $max, int $window_secs): bool {
    if ($max <= 0) return true;
    $now = time();
    try {
        $sel = pdo()->prepare("SELECT counter, window_at FROM rate_limit WHERE bucket = ?");
        $sel->execute([$bucket]);
        $row = $sel->fetch();
        if (!$row || ($now - (int)$row['window_at']) >= $window_secs) {
            // Fresh window — reset.
            pdo()->prepare(
                "INSERT INTO rate_limit (bucket, counter, window_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE counter = 1, window_at = VALUES(window_at)"
            )->execute([$bucket, $now]);
            return true;
        }
        if ((int)$row['counter'] >= $max) return false;
        pdo()->prepare("UPDATE rate_limit SET counter = counter + 1 WHERE bucket = ?")
            ->execute([$bucket]);
        return true;
    } catch (Throwable $e) {
        // If the rate-limit table is missing or DB is sad, fail open rather than
        // locking everyone out. The error will surface elsewhere.
        error_log('rate_limit_check failed: ' . $e->getMessage());
        return true;
    }
}

function enforce_global_post_rate_limit(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $ip = client_ip();
    if (!rate_limit_check('post-ip:' . $ip, POST_RATE_LIMIT_MAX, POST_RATE_LIMIT_WINDOW)) {
        audit_log('rate_limit.block', ['meta' => ['ip' => $ip, 'bucket' => 'post-ip']]);
        http_response_code(429);
        header('Retry-After: ' . POST_RATE_LIMIT_WINDOW);
        die('Too many requests. Please slow down and try again in a minute.');
    }
}

start_session();
enforce_global_post_rate_limit();
