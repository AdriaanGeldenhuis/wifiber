<?php
/**
 * Push registration endpoint for the native app.
 *
 * Auth: customer session cookie (the same one /account/* uses). The
 * native app signs in via /account/login.php (or a dedicated session-
 * issue endpoint we add later) and then POSTs here to register the
 * Firebase Cloud Messaging token returned by Firebase on app launch.
 *
 *   POST /account/api/push.php
 *     Content-Type: application/json
 *     Body: { "action": "register",
 *             "token": "<FCM token>",
 *             "platform": "android" | "ios" | "web",
 *             "app_version": "1.0.0",
 *             "device_label": "Pixel 8a" }
 *     -> 200 { ok: true, id: 42 }
 *
 *   POST /account/api/push.php
 *     Body: { "action": "unregister", "token": "..." }
 *     -> 200 { ok: true }
 *
 * On 401 the app should re-prompt for login. CSRF is required for
 * cookie-authed requests; the client can fetch a fresh token with
 * GET /account/api/push.php (returns { ok, csrf }).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../auth/helpers.php';
require_once __DIR__ . '/../../auth/notifications.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function _push_api_error(int $status, string $message, array $extra = []): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_SLASHES);
    exit;
}

function _push_api_ok(array $data): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'client') {
    _push_api_error(401, 'login required');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET — hand back a CSRF token so the app can include it on POST.
if ($method === 'GET') {
    _push_api_ok(['csrf' => csrf_token()]);
}

if ($method !== 'POST') {
    _push_api_error(405, 'method not allowed');
}

// Accept JSON or form-encoded body.
$raw = (string)file_get_contents('php://input');
$body = [];
if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) $body = $j;
}
if (!$body) $body = $_POST;

// CSRF: native apps include the token in either the X-CSRF header or
// the body field _csrf. Browser-based fetch() inside the portal will
// pick it up from <meta name="csrf-token">.
if (!csrf_check()) {
    _push_api_error(419, 'csrf token invalid — fetch a fresh one with GET');
}

$action   = (string)($body['action']   ?? 'register');
$token    = trim((string)($body['token'] ?? ''));
$platform = strtolower(trim((string)($body['platform'] ?? 'android')));

if ($token === '' || strlen($token) > 512) {
    _push_api_error(400, 'token required (max 512 chars)');
}
if (!in_array($platform, ['android', 'ios', 'web'], true)) {
    _push_api_error(400, 'platform must be android, ios or web');
}

if ($action === 'register') {
    try {
        $id = device_token_register((int)$user['id'], $platform, $token, [
            'app_version'  => (string)($body['app_version']  ?? ''),
            'device_label' => (string)($body['device_label'] ?? ''),
        ]);
        audit_log('push.token_register', [
            'target_type' => 'device_token',
            'target_id'   => $id,
            'meta'        => ['platform' => $platform],
        ]);
        _push_api_ok(['id' => $id]);
    } catch (Throwable $e) {
        _push_api_error(400, $e->getMessage());
    }
}

if ($action === 'unregister') {
    device_token_revoke_by_token($token);
    audit_log('push.token_unregister', ['meta' => ['platform' => $platform]]);
    _push_api_ok([]);
}

_push_api_error(400, "unknown action: $action");
