<?php
/**
 * Shared helpers for the REST API surface (api/v1/index.php and the
 * inbound-webhook landing pads).
 *
 *   api_error / api_ok      — JSON response helpers (also used by IPN/in.php)
 *   api_authenticate()      — bearer-token resolution + per-token rate limit
 *   api_require_scope()     — scope guard (read, diag, write:devices, …)
 *   api_parse_json_body()   — accept body or x-www-form-urlencoded
 *   api_cursor_paginate()   — keyset pagination over an INT autoincrement id
 *
 *   inbound_webhook_lookup()       — fetch a registered source by name
 *   inbound_webhook_verify()       — HMAC verify a body against a source's secret
 *   inbound_webhook_log_delivery() — write an inbound_deliveries row
 *
 * Pagination contract: clients pass ?limit=100&cursor=42 and we return
 *   { ok: true, data: [...], next_cursor: 99 }
 * where next_cursor is null when the page is the last one.  Cursor is
 * always the table's primary id, encoded as a plain integer (no
 * base64) so it shows up cleanly in the URL bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/webhooks.php';

const API_RATE_LIMIT_PER_MIN = 60;
const API_DEFAULT_PAGE_SIZE  = 50;
const API_MAX_PAGE_SIZE      = 200;

/**
 * Every API write scope.  When admin/integrations.php builds the
 * scope picker, it offers these in addition to the read-only `read`
 * and `diag` scopes that already shipped in Phase 16.
 */
const API_WRITE_SCOPES = [
    'write:customers' => 'Create / update customers',
    'write:devices'   => 'Create / update / delete devices',
    'write:sectors'   => 'Create / update sectors',
    'write:sites'     => 'Create / update sites',
    'write:invoices'  => 'Create / mark-paid invoices',
    'write:products'  => 'Create / update products',
    'write:payments'  => 'Record payments',
    'write:webhooks'  => 'Register webhooks (inbound + outbound)',
];

/* ---------------------------------------------------------------- output */

function api_error(int $http, string $message, array $extra = []): void {
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------------ auth */

/**
 * Resolve the bearer token from the Authorization header (or ?api_key
 * for legacy clients) and apply the per-token rate limit. Returns the
 * session array (user_id, scopes, token_id).
 */
function api_authenticate(): array {
    $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($bearer, 'Bearer ')) $bearer = substr($bearer, 7);
    if ($bearer === '' && !empty($_GET['api_key'])) $bearer = (string)$_GET['api_key'];

    if ($bearer === '') api_error(401, 'missing bearer token');
    $session = api_token_resolve($bearer);
    if ($session === null) api_error(401, 'invalid or expired token');

    // Rate-limit per resolved token (we re-look up the id since
    // api_token_resolve doesn't expose it). Cheap LIKE-free lookup.
    $hash = hash('sha256', trim($bearer));
    $tid_stmt = pdo()->prepare("SELECT id FROM api_tokens WHERE token_hash = ? LIMIT 1");
    $tid_stmt->execute([$hash]);
    $tid = (int)$tid_stmt->fetchColumn();
    if ($tid > 0) {
        $session['token_id'] = $tid;
        if (!rate_limit_check('api-token:' . $tid, API_RATE_LIMIT_PER_MIN, 60)) {
            header('Retry-After: 60');
            api_error(429, 'rate limit exceeded — ' . API_RATE_LIMIT_PER_MIN . ' requests per minute per token');
        }
    }
    return $session;
}

function api_require_scope(array $session, string $scope): void {
    $scopes = $session['scopes'] ?? [];
    if (!in_array($scope, $scopes, true)) {
        api_error(403, "token missing scope: $scope");
    }
}

/* ----------------------------------------------------------------- input */

function api_parse_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return $_POST ?: [];
    $data = json_decode($raw, true);
    if (!is_array($data)) api_error(400, 'request body is not valid JSON');
    return $data;
}

/* -------------------------------------------------------------- pagination */

/**
 * Run a SELECT against $table with an optional WHERE clause and
 * keyset pagination over the `id` column.
 *
 * @param string $table        bare table name (already schema-validated)
 * @param string $where        SQL fragment like "WHERE status = ?"
 * @param array  $args         positional params for $where
 * @param string $select       columns to project (default '*')
 * @param string $order_dir    'ASC' or 'DESC' on id (default 'ASC')
 * @return array               ['data' => rows, 'next_cursor' => ?int]
 */
function api_cursor_paginate(
    string $table,
    string $where = '',
    array $args = [],
    string $select = '*',
    string $order_dir = 'ASC'
): array {
    $limit  = max(1, min(API_MAX_PAGE_SIZE, (int)($_GET['limit'] ?? API_DEFAULT_PAGE_SIZE)));
    $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;
    $order_dir = strtoupper($order_dir) === 'DESC' ? 'DESC' : 'ASC';

    if ($cursor !== null && $cursor > 0) {
        $cmp = $order_dir === 'DESC' ? '<' : '>';
        $where = $where === ''
            ? "WHERE id $cmp ?"
            : "$where AND id $cmp ?";
        $args[] = $cursor;
    }
    $sql = "SELECT $select FROM `$table` $where ORDER BY id $order_dir LIMIT " . ($limit + 1);
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $next = null;
    if (count($rows) > $limit) {
        $extra = array_pop($rows);
        $next  = (int)$extra['id'];
    }
    return ['data' => $rows, 'next_cursor' => $next];
}

/* -------------------------------------------------- inbound webhook helpers */

function inbound_webhook_lookup(string $name): ?array {
    $stmt = pdo()->prepare("SELECT * FROM inbound_webhooks WHERE name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([trim($name)]);
    return $stmt->fetch() ?: null;
}

/**
 * HMAC-verify a raw body against a registered source's secret.
 *
 * Many gateways prefix the signature with the algo name
 * (e.g. GitHub: "sha256=abcd…"); we strip an optional `prefix`
 * configured per-source before comparing.
 *
 * Returns ['ok' => bool, 'reason' => string].
 */
function inbound_webhook_verify(array $source, string $supplied_signature, string $body): array {
    $secret = (string)($source['secret']  ?? '');
    $algo   = (string)($source['algo']    ?? 'sha256');
    $prefix = (string)($source['signature_prefix'] ?? '');
    if ($secret === '') return ['ok' => false, 'reason' => 'source has no secret'];

    $sig = trim($supplied_signature);
    if ($prefix !== '' && str_starts_with($sig, $prefix)) {
        $sig = substr($sig, strlen($prefix));
    }
    if ($sig === '') return ['ok' => false, 'reason' => 'missing signature header'];

    $expected = hash_hmac($algo, $body, $secret);
    if (!hash_equals($expected, $sig)) {
        return ['ok' => false, 'reason' => 'signature mismatch'];
    }
    return ['ok' => true, 'reason' => 'verified'];
}

function inbound_webhook_log_delivery(?int $source_id, string $source_name, string $event, string $body, string $status, string $reason, string $remote_ip): int {
    $stmt = pdo()->prepare(
        "INSERT INTO inbound_deliveries
            (inbound_id, source_name, event, body_sha256, status, reason, remote_ip, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $source_id,
        mb_substr($source_name, 0, 40),
        mb_substr($event, 0, 80),
        hash('sha256', $body),
        $status,
        mb_substr($reason, 0, 120),
        mb_substr($remote_ip, 0, 45),
        // Cap stored payload to ~64 KB so a flood of huge bodies can't blow up the row.
        mb_substr($body, 0, 65535),
    ]);
    if ($source_id !== null && $status === 'verified') {
        pdo()->prepare(
            "UPDATE inbound_webhooks
                SET last_received_at = NOW(),
                    delivery_count   = delivery_count + 1
              WHERE id = ?"
        )->execute([$source_id]);
    }
    return (int)pdo()->lastInsertId();
}

function inbound_webhook_save(array $data, ?int $id = null): int {
    $name   = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)($data['name'] ?? ''))));
    if ($name === '' || strlen($name) < 2) {
        throw new InvalidArgumentException('Source name is required (lowercase letters, digits, _- only).');
    }
    $secret = trim((string)($data['secret'] ?? '')) ?: bin2hex(random_bytes(24));
    $algo   = in_array($data['algo'] ?? 'sha256', ['sha256','sha1','md5'], true) ? $data['algo'] : 'sha256';
    $header = mb_substr(trim((string)($data['signature_header'] ?? 'X-Hub-Signature-256')), 0, 60);
    $prefix = mb_substr((string)($data['signature_prefix'] ?? ($algo === 'sha256' ? 'sha256=' : '')), 0, 20);
    $desc   = mb_substr(trim((string)($data['description'] ?? '')), 0, 200);
    $active = !empty($data['is_active']) ? 1 : 0;

    if ($id) {
        pdo()->prepare(
            "UPDATE inbound_webhooks
                SET name=?, description=?, secret=?, algo=?,
                    signature_header=?, signature_prefix=?, is_active=?
              WHERE id=?"
        )->execute([$name, $desc, $secret, $algo, $header, $prefix, $active, $id]);
        return $id;
    }
    pdo()->prepare(
        "INSERT INTO inbound_webhooks
            (name, description, secret, algo, signature_header, signature_prefix, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $name, $desc, $secret, $algo, $header, $prefix, $active,
        !empty($data['created_by']) ? (int)$data['created_by'] : null,
    ]);
    return (int)pdo()->lastInsertId();
}

function inbound_webhook_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM inbound_webhooks WHERE id = ?")->execute([$id]);
}

function inbound_webhooks_all(): array {
    return pdo()->query("SELECT * FROM inbound_webhooks ORDER BY name ASC")->fetchAll();
}

function inbound_deliveries_recent(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    return pdo()->query(
        "SELECT * FROM inbound_deliveries ORDER BY received_at DESC LIMIT $limit"
    )->fetchAll();
}
