<?php
/**
 * Push channel — Firebase Cloud Messaging (FCM) HTTP v1.
 *
 * Sends to every active device_tokens row for the user. Configure via
 * data/db.local.php:
 *
 *   'notify_push' => [
 *     'enabled'         => true,
 *     'project_id'      => 'wifiber-app',
 *     // Either:
 *     'service_account' => '/etc/wifiber/fcm-service-account.json',
 *     // or paste the raw JSON string in 'service_account_json' => '...'.
 *   ],
 *
 * Falls back to "channel not configured" with a clear error when the
 * service account is missing — matches the SMS / WhatsApp behaviour
 * so notify_send() degrades gracefully on a fresh install.
 *
 * Token life-cycle is owned by auth/notifications.php:
 *   device_token_register(), device_token_touch(), device_token_revoke().
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

function channel_push_send(array $user, string $template, array $tpl, array $config): array {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        return ['ok' => false, 'recipient' => '', 'error' => 'no user id'];
    }

    $tokens = device_tokens_for_user($uid);
    if (!$tokens) {
        return [
            'ok'        => false,
            'recipient' => '',
            'error'     => 'no active device tokens',
            'meta'      => ['count' => 0],
        ];
    }

    $access = _fcm_access_token($config);
    if ($access === null) {
        return [
            'ok'        => false,
            'recipient' => 'fcm',
            'error'     => 'fcm not configured (missing service account or project id)',
        ];
    }
    $project = (string)($config['project_id'] ?? '');
    $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

    $title = (string)($tpl['subject'] ?? '');
    $body  = (string)($tpl['body']    ?? '');

    $sent_to   = [];
    $failed    = [];
    $revoked   = [];

    foreach ($tokens as $row) {
        $payload = json_encode([
            'message' => [
                'token'        => $row['token'],
                'notification' => [
                    'title' => $title,
                    'body'  => mb_substr($body, 0, 4000),
                ],
                'data' => [
                    'template' => $template,
                    'user_id'  => (string)$uid,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http >= 200 && $http < 300) {
            device_token_touch((int)$row['id']);
            $sent_to[] = (int)$row['id'];
            continue;
        }

        // FCM hands back 404 / 400 with UNREGISTERED for stale tokens.
        // Mark them inactive so we stop hammering them — the next app
        // launch will re-register a fresh token anyway.
        $err_blob = strtolower((string)$resp);
        if ($http === 404
            || str_contains($err_blob, 'unregistered')
            || str_contains($err_blob, 'invalid_argument')) {
            device_token_revoke((int)$row['id']);
            $revoked[] = (int)$row['id'];
        }
        $failed[] = ['id' => (int)$row['id'], 'http' => $http];
    }

    $ok = !empty($sent_to);
    return [
        'ok'        => $ok,
        'recipient' => 'fcm:' . count($tokens) . ' devices',
        'subject'   => $title,
        'cost_zar'  => 0.0,
        'error'     => $ok ? '' : 'all FCM deliveries failed',
        'meta'      => [
            'sent'    => $sent_to,
            'failed'  => $failed,
            'revoked' => $revoked,
        ],
    ];
}

/**
 * Mint (or reuse) an OAuth 2.0 access token for the FCM HTTP v1 API.
 * The token is signed with the service-account private key and cached
 * in the session for its lifetime so we don't re-sign on every call.
 */
function _fcm_access_token(array $config): ?string {
    $sa = _fcm_load_service_account($config);
    if ($sa === null) return null;
    if (empty($sa['client_email']) || empty($sa['private_key'])) return null;

    $cache_key = '_fcm_token_' . md5((string)$sa['client_email']);
    $cached = $_SESSION[$cache_key] ?? null;
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60) {
        return (string)$cached['access'];
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $segments = [
        _fcm_b64url(json_encode($header)),
        _fcm_b64url(json_encode($claims)),
    ];
    $signing_input = implode('.', $segments);
    $signature = '';
    $ok = openssl_sign(
        $signing_input,
        $signature,
        (string)$sa['private_key'],
        OPENSSL_ALGO_SHA256
    );
    if (!$ok) return null;
    $jwt = $signing_input . '.' . _fcm_b64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) return null;
    $j = json_decode((string)$resp, true);
    if (empty($j['access_token'])) return null;

    $_SESSION[$cache_key] = [
        'access' => $j['access_token'],
        'exp'    => $now + (int)($j['expires_in'] ?? 3600),
    ];
    return (string)$j['access_token'];
}

function _fcm_load_service_account(array $config): ?array {
    if (!empty($config['service_account_json'])) {
        $j = json_decode((string)$config['service_account_json'], true);
        return is_array($j) ? $j : null;
    }
    $path = (string)($config['service_account'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) return null;
    $raw = (string)@file_get_contents($path);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function _fcm_b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
