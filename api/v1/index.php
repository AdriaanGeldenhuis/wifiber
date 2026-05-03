<?php
/**
 * REST API v1 — Phase 16.
 *
 * Single front-controller. Routes:
 *
 *   GET  /api/v1/links                     list wireless_links
 *   GET  /api/v1/links/{id}/samples        link_health_samples (Grafana)
 *   GET  /api/v1/devices/{id}/health       last 24h device_health
 *   GET  /api/v1/outages                   active + recent
 *   POST /api/v1/diagnostics               enqueue iperf3/traceroute (scope=diag)
 *
 * Auth: Bearer token in the Authorization header. Tokens are issued
 * via /admin/integrations.php and stored hashed in api_tokens. Scopes
 * 'read' (GETs) and 'diag' (POST diagnostics).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../auth/helpers.php';
require_once __DIR__ . '/../../auth/webhooks.php';
require_once __DIR__ . '/../../auth/wireless.php';
require_once __DIR__ . '/../../auth/diagnostics.php';

header('Content-Type: application/json');
header('X-Wifiber-API: v1');

function api_error(int $http, string $message, array $extra = []): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $message] + $extra);
    exit;
}

function api_ok(array $data): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth, 'Bearer ')) $auth = substr($auth, 7);
$session = api_token_resolve($auth);
if ($session === null) api_error(401, 'invalid or missing bearer token');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Strip the /api/v1 prefix so we can match cleanly.
$path = preg_replace('#^.*/api/v1#', '', $path);
$path = '/' . trim((string)$path, '/');

require_in_scope:
$require_scope = function (string $scope) use ($session) {
    if (!in_array($scope, $session['scopes'] ?? [], true)) {
        api_error(403, "token missing scope: $scope");
    }
};

if ($method === 'GET' && $path === '/links') {
    $require_scope('read');
    $rows = wireless_links_all([]);
    api_ok(['links' => $rows]);
}

if ($method === 'GET' && preg_match('#^/links/(\d+)/samples$#', $path, $m)) {
    $require_scope('read');
    $link_id = (int)$m[1];
    $from = isset($_GET['from']) ? (string)$_GET['from'] : '-7 days';
    $to   = isset($_GET['to'])   ? (string)$_GET['to']   : 'now';
    $from_ts = strtotime($from) ?: (time() - 7 * 86400);
    $to_ts   = strtotime($to)   ?: time();
    $stmt = pdo()->prepare(
        "SELECT * FROM link_health_samples
          WHERE link_id = ? AND polled_at BETWEEN ? AND ?
          ORDER BY polled_at ASC"
    );
    $stmt->execute([$link_id, date('Y-m-d H:i:s', $from_ts), date('Y-m-d H:i:s', $to_ts)]);
    api_ok(['link_id' => $link_id, 'from' => date('c', $from_ts),
            'to' => date('c', $to_ts), 'samples' => $stmt->fetchAll()]);
}

if ($method === 'GET' && preg_match('#^/devices/(\d+)/health$#', $path, $m)) {
    $require_scope('read');
    $device_id = (int)$m[1];
    $stmt = pdo()->prepare(
        "SELECT * FROM device_health
          WHERE device_id = ? AND polled_at >= NOW() - INTERVAL 24 HOUR
          ORDER BY polled_at DESC"
    );
    $stmt->execute([$device_id]);
    api_ok(['device_id' => $device_id, 'samples' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $path === '/outages') {
    $require_scope('read');
    require_once __DIR__ . '/../../auth/outages.php';
    $active = outages_all(['status' => 'active'], 100);
    $recent = outages_all(['status' => 'resolved'], 100);
    api_ok(['active' => $active, 'recent_resolved' => $recent]);
}

if ($method === 'POST' && $path === '/diagnostics') {
    $require_scope('diag');
    $raw  = file_get_contents('php://input') ?: '{}';
    $body = json_decode($raw, true) ?: [];
    $kind     = (string)($body['kind']     ?? '');
    $scope    = (string)($body['scope']    ?? 'device');
    $scope_id = (int)   ($body['scope_id'] ?? 0);
    $payload  = (array) ($body['payload']  ?? []);
    if (!in_array($kind, DIAG_KINDS, true)) api_error(400, 'invalid kind');
    if (!in_array($scope, ['link','device'], true)) api_error(400, 'invalid scope');
    if ($scope_id <= 0) api_error(400, 'scope_id required');
    try {
        $job_id = diagnostic_job_enqueue($kind, $scope, $scope_id,
            (int)($session['user_id'] ?? 0), $payload);
        api_ok(['job_id' => $job_id]);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

api_error(404, 'no route');
