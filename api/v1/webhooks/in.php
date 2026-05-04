<?php
/**
 * Inbound webhook landing pad.
 *
 *   POST /api/v1/webhooks/in.php?source=NAME
 *
 * Auth: HMAC signature in the source's configured header.  The
 * source-name → secret + algorithm + header mapping lives in the
 * inbound_webhooks table (Phase 30) — register a source via
 * /admin/integrations.php → Inbound webhooks.
 *
 * Verified deliveries are logged to inbound_deliveries and re-fired
 * internally as `inbound.<source>.<event>` so existing outbound
 * webhook subscribers and our own listeners can chain on them.
 *
 * Response is plain text:
 *   200 "ok"           — accepted and verified
 *   400 "<reason>"     — verification failed
 *   404 "no source"    — unknown ?source=
 *
 * The PayFast-specific gateway IPN already lives at
 * /api/v1/payments/ipn.php?gateway=payfast (Phase 28); this endpoint
 * is for everything else (Splynx event hooks, Zapier, custom
 * integrations, etc.).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/helpers.php';
require_once __DIR__ . '/../../../auth/api.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Wifiber-API: webhooks/in');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo "POST only";
    exit;
}

$source_name = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['source'] ?? '')));
if ($source_name === '') {
    http_response_code(400);
    echo "?source= required";
    exit;
}

$source = inbound_webhook_lookup($source_name);
$body   = file_get_contents('php://input') ?: '';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';

if (!$source) {
    inbound_webhook_log_delivery(null, $source_name, '', $body, 'rejected', 'unknown source', $ip);
    http_response_code(404);
    echo "no source: $source_name";
    exit;
}

// Header lookups in PHP land use HTTP_ prefix and dashes flipped to
// underscores. Cope with whatever the source happened to configure.
$header_key = strtoupper(str_replace('-', '_', (string)$source['signature_header']));
$supplied   = $_SERVER['HTTP_' . $header_key] ?? '';
if ($supplied === '') {
    inbound_webhook_log_delivery((int)$source['id'], $source_name, '', $body, 'rejected', "missing $header_key header", $ip);
    http_response_code(400);
    echo "missing signature header: " . (string)$source['signature_header'];
    exit;
}

$verified = inbound_webhook_verify($source, (string)$supplied, $body);

// Try to extract an event field (most senders include `event` or `type`
// in their JSON envelope) so the operator can filter audit log noise.
$event = '';
$json  = json_decode($body, true);
if (is_array($json)) {
    foreach (['event','type','event_type','action'] as $k) {
        if (!empty($json[$k]) && is_string($json[$k])) { $event = (string)$json[$k]; break; }
    }
}

if (!$verified['ok']) {
    inbound_webhook_log_delivery((int)$source['id'], $source_name, $event, $body, 'rejected', $verified['reason'], $ip);
    http_response_code(400);
    echo $verified['reason'];
    exit;
}

inbound_webhook_log_delivery((int)$source['id'], $source_name, $event, $body, 'verified', 'ok', $ip);

// Re-fire as an internal event so anything subscribed to
// inbound.<source>.* can react.  The payload is the parsed JSON when
// available, else the raw body.
$internal_event = 'inbound.' . $source_name . ($event !== '' ? '.' . $event : '');
webhook_fire($internal_event, [
    'source'  => $source_name,
    'event'   => $event,
    'body'    => is_array($json) ? $json : ['raw' => $body],
    'remote'  => $ip,
]);

audit_log('inbound_webhook.received', [
    'meta' => [
        'source' => $source_name,
        'event'  => $event,
        'bytes'  => strlen($body),
    ],
]);

echo "ok";
