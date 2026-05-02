<?php
/**
 * Shared auth helpers for admin and client portals.
 *
 * Storage:
 *   data/users.json       — all user accounts (role: admin | client)
 *   data/throttle.json    — login-failure tracking by IP
 *   data/admin-ips.json   — optional admin IP allowlist (empty = open)
 *
 * Sessions are HttpOnly + Secure (when over HTTPS) + SameSite=Lax.
 * CSRF tokens are required on all POSTs.
 */

declare(strict_types=1);

const DATA_DIR        = __DIR__ . '/../data';
const USERS_FILE      = DATA_DIR . '/users.json';
const THROTTLE_FILE   = DATA_DIR . '/throttle.json';
const ADMIN_IPS_FILE  = DATA_DIR . '/admin-ips.json';

const MAX_LOGIN_FAILS = 5;
const LOCKOUT_SECS    = 900;   // 15 min
const SESSION_NAME    = 'wfsess';

/* --------------------------------------------------------------- session */

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
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

function load_users(): array {
    $d = json_load(USERS_FILE, ['users' => []]);
    return $d['users'] ?? [];
}

function save_users(array $users): bool {
    return json_save(USERS_FILE, ['users' => array_values($users)]);
}

function find_user_by_username(string $username): ?array {
    foreach (load_users() as $u) {
        if (isset($u['username']) && strcasecmp($u['username'], $username) === 0) return $u;
    }
    return null;
}

function find_user_by_id(int $id): ?array {
    foreach (load_users() as $u) {
        if (($u['id'] ?? null) === $id) return $u;
    }
    return null;
}

function next_user_id(array $users): int {
    $max = 0;
    foreach ($users as $u) $max = max($max, (int)($u['id'] ?? 0));
    return $max + 1;
}

function update_user(int $id, callable $patch): bool {
    $users = load_users();
    $found = false;
    foreach ($users as &$u) {
        if (($u['id'] ?? null) === $id) {
            $u = $patch($u);
            $found = true;
            break;
        }
    }
    return $found ? save_users($users) : false;
}

function create_user(string $username, string $password, string $role, string $name, string $email = '', array $extra = []): array {
    if (!in_array($role, ['admin', 'client'], true)) {
        throw new InvalidArgumentException("role must be admin or client");
    }
    $users = load_users();
    if (find_user_by_username($username)) {
        throw new RuntimeException("A user with that username already exists.");
    }
    $user = [
        'id'            => next_user_id($users),
        'username'      => $username,
        'email'         => $email,
        'name'          => $name,
        'role'          => $role,
        'phone'         => trim((string)($extra['phone']   ?? '')),
        'address'       => trim((string)($extra['address'] ?? '')),
        'package'       => trim((string)($extra['package'] ?? '')),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at'    => date('c'),
        'last_login'    => null,
    ];
    $users[] = $user;
    save_users($users);
    return $user;
}

/* --------------------------------------------------------------- login */

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_locked_out(string $ip): bool {
    $t = json_load(THROTTLE_FILE);
    $e = $t[$ip] ?? null;
    if (!$e) return false;
    if (($e['fails'] ?? 0) < MAX_LOGIN_FAILS) return false;
    if (time() - ($e['last'] ?? 0) > LOCKOUT_SECS) {
        unset($t[$ip]);
        json_save(THROTTLE_FILE, $t);
        return false;
    }
    return true;
}

function record_login_fail(string $ip): void {
    $t = json_load(THROTTLE_FILE);
    $t[$ip] = [
        'fails' => (int)(($t[$ip]['fails'] ?? 0) + 1),
        'last'  => time(),
    ];
    json_save(THROTTLE_FILE, $t);
}

function reset_login_fails(string $ip): void {
    $t = json_load(THROTTLE_FILE);
    if (isset($t[$ip])) {
        unset($t[$ip]);
        json_save(THROTTLE_FILE, $t);
    }
}

function attempt_login(string $username, string $password, string $required_role): ?array {
    $ip = client_ip();
    if (is_locked_out($ip)) return null;
    $user = find_user_by_username($username);
    $valid = $user && password_verify($password, $user['password_hash'] ?? '');
    if (!$valid || ($user['role'] ?? '') !== $required_role) {
        record_login_fail($ip);
        return null;
    }
    reset_login_fails($ip);

    // Re-hash if PHP's defaults have moved on
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        update_user((int)$user['id'], function (array $u) use ($password) {
            $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            return $u;
        });
    }

    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$user['id'];
    $_SESSION['user_role']    = $user['role'];
    $_SESSION['user_name']    = $user['name'];
    $_SESSION['logged_in_at'] = time();

    update_user((int)$user['id'], function (array $u) {
        $u['last_login'] = date('c');
        return $u;
    });

    return $user;
}

function logout(): void {
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

function require_role(string $role, string $login_url): array {
    $user = current_user();
    if (!$user) {
        header('Location: ' . $login_url);
        exit;
    }
    if (($user['role'] ?? '') !== $role) {
        http_response_code(403);
        die('Access denied.');
    }
    return $user;
}

/* ---------------------------------------------------------------- flash */

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
    foreach (load_users() as $u) {
        if (($u['role'] ?? '') === 'admin') return true;
    }
    return false;
}

start_session();
