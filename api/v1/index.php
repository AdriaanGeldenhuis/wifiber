<?php
/**
 * REST API v1.
 *
 * Auth: Bearer token in the Authorization header (or ?api_key= for
 * legacy clients). Tokens are issued via /admin/integrations.php and
 * stored hashed in the api_tokens table. Per-token rate limit is 60
 * requests / minute; exceeding it returns 429 + Retry-After.
 *
 * Pagination is cursor-based. Pass ?limit= and ?cursor= and the
 * response gives back ?next_cursor=N (null on the last page).
 *
 * Scopes:
 *   read              every GET endpoint
 *   diag              POST /diagnostics
 *   write:customers   create/update users
 *   write:devices     create/update/delete devices
 *   write:sectors     create/update sectors
 *   write:sites       create/update sites
 *   write:invoices    create/mark-paid invoices
 *   write:products    create/update products
 *   write:payments    record payments
 *   write:webhooks    register inbound + outbound webhooks
 *
 * The full spec lives at /api/v1/openapi.yaml — point your client
 * generator at it and stop hand-typing requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../auth/helpers.php';
require_once __DIR__ . '/../../auth/api.php';
require_once __DIR__ . '/../../auth/wireless.php';
require_once __DIR__ . '/../../auth/diagnostics.php';

header('X-Wifiber-API: v1');

$session = api_authenticate();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path   = preg_replace('#^.*/api/v1#', '', $path);
$path   = '/' . trim((string)$path, '/');

/* ============================================================ READ paths */

if ($method === 'GET' && $path === '/links') {
    api_require_scope($session, 'read');
    $rows = wireless_links_all([]);
    api_ok(['links' => $rows]);
}

if ($method === 'GET' && preg_match('#^/links/(\d+)/samples$#', $path, $m)) {
    api_require_scope($session, 'read');
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
    api_require_scope($session, 'read');
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
    api_require_scope($session, 'read');
    require_once __DIR__ . '/../../auth/outages.php';
    $active = outages_all(['status' => 'active'], 100);
    $recent = outages_all(['status' => 'resolved'], 100);
    api_ok(['active' => $active, 'recent_resolved' => $recent]);
}

if ($method === 'POST' && $path === '/diagnostics') {
    api_require_scope($session, 'diag');
    $body = api_parse_json_body();
    $kind     = (string)($body['kind']     ?? '');
    $scope    = (string)($body['scope']    ?? 'device');
    $scope_id = (int)   ($body['scope_id'] ?? 0);
    $payload  = (array) ($body['payload']  ?? []);
    if (!in_array($kind, DIAG_KINDS, true))           api_error(400, 'invalid kind');
    if (!in_array($scope, ['link','device'], true))   api_error(400, 'invalid scope');
    if ($scope_id <= 0)                                api_error(400, 'scope_id required');
    try {
        $job_id = diagnostic_job_enqueue($kind, $scope, $scope_id,
            (int)($session['user_id'] ?? 0), $payload);
        api_ok(['job_id' => $job_id], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

/* ============================================================ list endpoints (cursor-paginated) */

if ($method === 'GET' && $path === '/customers') {
    api_require_scope($session, 'read');
    $page = api_cursor_paginate('users', "WHERE role = 'client'", []);
    foreach ($page['data'] as &$r) {
        unset($r['password_hash'], $r['totp_secret'], $r['totp_recovery_codes'], $r['service_password_enc']);
    }
    api_ok(['customers' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

if ($method === 'GET' && $path === '/devices') {
    api_require_scope($session, 'read');
    $page = api_cursor_paginate('devices', '', []);
    api_ok(['devices' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

if ($method === 'GET' && $path === '/sectors') {
    api_require_scope($session, 'read');
    $page = api_cursor_paginate('sectors', '', []);
    api_ok(['sectors' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

if ($method === 'GET' && $path === '/sites') {
    api_require_scope($session, 'read');
    $page = api_cursor_paginate('sites', '', []);
    api_ok(['sites' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

if ($method === 'GET' && $path === '/invoices') {
    api_require_scope($session, 'read');
    $where = '';
    $args  = [];
    if (!empty($_GET['status']) && in_array($_GET['status'], ['unpaid','paid','cancelled'], true)) {
        $where = 'WHERE status = ?';
        $args[] = $_GET['status'];
    }
    $page = api_cursor_paginate('invoices', $where, $args);
    api_ok(['invoices' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

if ($method === 'GET' && $path === '/products') {
    api_require_scope($session, 'read');
    $page = api_cursor_paginate('products', "WHERE is_active = 1", []);
    api_ok(['products' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

if ($method === 'GET' && $path === '/payments') {
    api_require_scope($session, 'read');
    $page = api_cursor_paginate('payments', '', [], '*', 'DESC');
    api_ok(['payments' => $page['data'], 'next_cursor' => $page['next_cursor']]);
}

/* ============================================================ WRITE — customers */

if ($method === 'POST' && $path === '/customers') {
    api_require_scope($session, 'write:customers');
    $body = api_parse_json_body();
    if (empty($body['username']) || empty($body['name']) || empty($body['surname'])) {
        api_error(400, 'username, name and surname are required');
    }
    try {
        $created = create_user(
            (string)$body['username'],
            (string)($body['password'] ?? bin2hex(random_bytes(8))),
            'client',
            (string)$body['name'],
            (string)($body['email'] ?? ''),
            [
                'phone'         => (string)($body['phone']   ?? ''),
                'address'       => (string)($body['address'] ?? ''),
                'package'       => (string)($body['package'] ?? ''),
                'surname'       => (string)$body['surname'],
                'customer_type' => (string)($body['customer_type'] ?? 'residential'),
            ]
        );
        if (!empty($body['product_id'])) {
            update_user((int)$created['id'], function (array $u) use ($body) {
                $u['product_id'] = (int)$body['product_id'];
                return $u;
            });
        }
        unset($created['password_hash'], $created['totp_secret']);
        api_ok(['customer' => $created], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'PATCH' && preg_match('#^/customers/(\d+)$#', $path, $m)) {
    api_require_scope($session, 'write:customers');
    $id   = (int)$m[1];
    $body = api_parse_json_body();
    if (!find_user_by_id($id)) api_error(404, 'customer not found');
    update_user($id, fn (array $u) => array_merge($u, array_intersect_key($body, array_flip([
        'name','surname','email','phone','address','customer_type','status',
        'service_start','billing_day','payment_method','product_id','package',
        'site_id','sector_id','equipment_mac','equipment_ip','notes','radius_username',
    ]))));
    $u = find_user_by_id($id);
    if ($u) unset($u['password_hash'], $u['totp_secret'], $u['service_password_enc']);
    api_ok(['customer' => $u]);
}

/* ============================================================ WRITE — devices */

if ($method === 'POST' && $path === '/devices') {
    api_require_scope($session, 'write:devices');
    require_once __DIR__ . '/../../auth/devices.php';
    $body = api_parse_json_body();
    try {
        $id = device_save($body);
        api_ok(['device' => device_find($id)], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'PATCH' && preg_match('#^/devices/(\d+)$#', $path, $m)) {
    api_require_scope($session, 'write:devices');
    require_once __DIR__ . '/../../auth/devices.php';
    $id   = (int)$m[1];
    if (!device_find($id)) api_error(404, 'device not found');
    try {
        device_save(api_parse_json_body(), $id);
        api_ok(['device' => device_find($id)]);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'DELETE' && preg_match('#^/devices/(\d+)$#', $path, $m)) {
    api_require_scope($session, 'write:devices');
    $id = (int)$m[1];
    pdo()->prepare("DELETE FROM devices WHERE id = ?")->execute([$id]);
    api_ok(['deleted' => $id]);
}

/* ============================================================ WRITE — sectors */

if ($method === 'POST' && $path === '/sectors') {
    api_require_scope($session, 'write:sectors');
    require_once __DIR__ . '/../../auth/sectors.php';
    $body = api_parse_json_body();
    try {
        $id = sector_save($body);
        api_ok(['sector' => sector_find($id)], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'PATCH' && preg_match('#^/sectors/(\d+)$#', $path, $m)) {
    api_require_scope($session, 'write:sectors');
    require_once __DIR__ . '/../../auth/sectors.php';
    $id = (int)$m[1];
    if (!sector_find($id)) api_error(404, 'sector not found');
    try {
        sector_save(api_parse_json_body(), $id);
        api_ok(['sector' => sector_find($id)]);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

/* ============================================================ WRITE — sites */

if ($method === 'POST' && $path === '/sites') {
    api_require_scope($session, 'write:sites');
    require_once __DIR__ . '/../../auth/sites.php';
    $body = api_parse_json_body();
    try {
        $id = site_save($body);
        api_ok(['site' => site_find($id)], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'PATCH' && preg_match('#^/sites/(\d+)$#', $path, $m)) {
    api_require_scope($session, 'write:sites');
    require_once __DIR__ . '/../../auth/sites.php';
    $id = (int)$m[1];
    if (!site_find($id)) api_error(404, 'site not found');
    try {
        site_save(api_parse_json_body(), $id);
        api_ok(['site' => site_find($id)]);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

/* ============================================================ WRITE — invoices */

if ($method === 'POST' && $path === '/invoices') {
    api_require_scope($session, 'write:invoices');
    require_once __DIR__ . '/../../auth/invoices.php';
    $body  = api_parse_json_body();
    $items = (array)($body['items'] ?? []);
    if (!$items) api_error(400, 'items[] required');
    try {
        $id = invoice_create($body, $items, (int)($session['user_id'] ?? 0));
        api_ok(['invoice' => invoice_find($id)], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'POST' && preg_match('#^/invoices/(\d+)/mark-paid$#', $path, $m)) {
    api_require_scope($session, 'write:invoices');
    require_once __DIR__ . '/../../auth/invoices.php';
    $id = (int)$m[1];
    if (!invoice_find($id)) api_error(404, 'invoice not found');
    invoice_set_status($id, 'paid');
    api_ok(['invoice' => invoice_find($id)]);
}

/* ============================================================ WRITE — products */

if ($method === 'POST' && $path === '/products') {
    api_require_scope($session, 'write:products');
    require_once __DIR__ . '/../../auth/products.php';
    try {
        $id = product_save(api_parse_json_body());
        api_ok(['product' => products_find($id)], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

if ($method === 'PATCH' && preg_match('#^/products/(\d+)$#', $path, $m)) {
    api_require_scope($session, 'write:products');
    require_once __DIR__ . '/../../auth/products.php';
    $id = (int)$m[1];
    if (!products_find($id)) api_error(404, 'product not found');
    try {
        product_save(api_parse_json_body(), $id);
        api_ok(['product' => products_find($id)]);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

/* ============================================================ WRITE — payments */

if ($method === 'POST' && $path === '/payments') {
    api_require_scope($session, 'write:payments');
    require_once __DIR__ . '/../../auth/payments.php';
    $body = api_parse_json_body();
    if (!isset($body['source'])) $body['source'] = 'api';
    try {
        $id = payment_record($body, (int)($session['user_id'] ?? 0));
        api_ok(['payment' => payment_find($id)], 201);
    } catch (Throwable $e) {
        api_error(400, $e->getMessage());
    }
}

/* ============================================================ ME */

if ($method === 'GET' && $path === '/me') {
    api_ok([
        'user_id' => $session['user_id'] ?? null,
        'scopes'  => $session['scopes']  ?? [],
        'token_id' => $session['token_id'] ?? null,
        'rate_limit' => [
            'per_minute' => API_RATE_LIMIT_PER_MIN,
        ],
    ]);
}

api_error(404, 'no route', ['method' => $method, 'path' => $path]);
