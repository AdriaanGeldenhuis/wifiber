<?php
/**
 * Webhooks — Phase 16.
 *
 * webhook_fire('event.name', $payload) creates a webhook_deliveries
 * row per matching subscription. bin/webhooks-fanout.php POSTs them
 * with an HMAC signature and retries on 5xx / network failure with
 * exponential backoff (1m, 5m, 30m, 2h, then 'giving_up').
 *
 * Subscription patterns support a single trailing wildcard:
 *   "outage.*"                  matches outage.opened + outage.resolved
 *   "wireless.config_applied"   exact match
 *   "*"                         everything (use sparingly)
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const WEBHOOK_BACKOFF_MINUTES = [1, 5, 30, 120, 720]; // attempt 1 .. 5

function webhook_fire(string $event, array $payload): int {
    $hooks = pdo()->query(
        "SELECT * FROM webhooks WHERE is_active = 1"
    )->fetchAll();
    $created = 0;
    foreach ($hooks as $h) {
        $events = json_decode((string)$h['events_json'], true) ?: [];
        if (!_webhook_matches($event, $events)) continue;
        pdo()->prepare(
            "INSERT INTO webhook_deliveries
                (webhook_id, event, payload_json, status, next_attempt_at)
             VALUES (?, ?, ?, 'queued', NOW())"
        )->execute([
            (int)$h['id'], $event,
            json_encode([
                'event' => $event,
                'fired_at' => date('c'),
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $created++;
    }
    return $created;
}

function _webhook_matches(string $event, array $patterns): bool {
    foreach ($patterns as $p) {
        $p = (string)$p;
        if ($p === '*' || $p === $event) return true;
        if (str_ends_with($p, '*') && str_starts_with($event, substr($p, 0, -1))) return true;
    }
    return false;
}

function webhook_save(array $data, ?int $id = null): int {
    $url = (string)($data['url'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid URL.');
    }
    $events = $data['events'] ?? [];
    if (is_string($events)) $events = array_filter(array_map('trim', explode(',', $events)));
    if (!is_array($events) || !$events) throw new InvalidArgumentException('Pick at least one event pattern.');
    $secret = trim((string)($data['secret'] ?? '')) ?: bin2hex(random_bytes(16));

    if ($id) {
        pdo()->prepare(
            "UPDATE webhooks SET url=?, secret=?, events_json=?, is_active=? WHERE id=?"
        )->execute([
            $url, $secret, json_encode($events, JSON_UNESCAPED_SLASHES),
            !empty($data['is_active']) ? 1 : 0, $id,
        ]);
        return $id;
    }
    pdo()->prepare(
        "INSERT INTO webhooks (url, secret, events_json, is_active, created_by)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $url, $secret, json_encode($events, JSON_UNESCAPED_SLASHES),
        !empty($data['is_active']) ? 1 : 0,
        !empty($data['created_by']) ? (int)$data['created_by'] : null,
    ]);
    return (int)pdo()->lastInsertId();
}

function webhook_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM webhooks WHERE id = ?")->execute([$id]);
}

function webhooks_all(): array {
    return pdo()->query("SELECT * FROM webhooks ORDER BY id ASC")->fetchAll();
}

/* --------------------------------------------------------- API tokens */

function api_token_create(int $user_id, string $label, array $scopes, ?string $expires_at = null): array {
    $plain = bin2hex(random_bytes(24)); // 48 chars, prefixed below
    $token = 'wfk_' . $plain;
    $hash  = hash('sha256', $token);
    pdo()->prepare(
        "INSERT INTO api_tokens (user_id, label, token_hash, scopes_json, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $user_id ?: null, mb_substr($label, 0, 80), $hash,
        json_encode($scopes, JSON_UNESCAPED_SLASHES),
        $expires_at ?: null,
    ]);
    $id = (int)pdo()->lastInsertId();
    return ['id' => $id, 'token' => $token]; // plaintext only returned at creation time
}

function api_token_revoke(int $id): bool {
    return pdo()->prepare("DELETE FROM api_tokens WHERE id = ?")->execute([$id]);
}

function api_tokens_for_user(int $user_id): array {
    $stmt = pdo()->prepare("SELECT id, label, scopes_json, expires_at, last_used_at, created_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Validate a bearer token and return [user_id, scopes] or null.
 * Updates last_used_at on every successful match.
 */
function api_token_resolve(string $bearer): ?array {
    $bearer = trim($bearer);
    if ($bearer === '') return null;
    $hash = hash('sha256', $bearer);
    $stmt = pdo()->prepare(
        "SELECT id, user_id, scopes_json, expires_at FROM api_tokens
          WHERE token_hash = ? LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) return null;
    pdo()->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?")
        ->execute([(int)$row['id']]);
    return [
        'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
        'scopes'  => json_decode((string)$row['scopes_json'], true) ?: [],
    ];
}
