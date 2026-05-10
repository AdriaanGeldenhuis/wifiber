<?php
/**
 * Whoami — returns the role of the currently authenticated user so the
 * native app can render a role-appropriate nav set (clients see the
 * customer portal nav, staff see Installs / Tickets / Map / etc.).
 *
 *   GET /account/api/whoami.php
 *     Auth: customer or staff session cookie.
 *     -> 200 { ok: true,  role: "client"|"admin"|"technician"|...,
 *              user_id: 42, name: "Jane", username: "jdoe" }
 *     -> 200 { ok: false } when no session is present (no error — the
 *              app polls this every page load and a not-logged-in
 *              state is normal and quiet).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../auth/helpers.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok'       => true,
    'role'     => (string)($user['role']     ?? ''),
    'user_id'  => (int)   ($user['id']       ?? 0),
    'name'     => (string)($user['name']     ?? ''),
    'username' => (string)($user['username'] ?? ''),
], JSON_UNESCAPED_SLASHES);
