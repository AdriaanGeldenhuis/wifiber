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
 * Step-by-step FCM diagnostic that ALSO sends a real test push to every
 * active device-token row owned by $user_id. Used by the "Debug push"
 * panel on /admin/notifications.php so the operator can see exactly
 * which step of the pipeline is wrong (config missing? service account
 * unreadable? OAuth signing failed? no devices registered? FCM 401?).
 *
 * Returns:
 *   [
 *     'ok'       => bool,           // true iff at least one device got it
 *     'error'    => ?string,        // top-level reason if we bailed early
 *     'attempted'=> int,
 *     'sent'     => int,
 *     'failed'   => int,
 *     'log'      => [               // append-only step trace
 *        ['step' => 'config',      'enabled' => true, 'project_id' => '...'],
 *        ['step' => 'service_account', 'client_email' => '...'],
 *        ['step' => 'oauth_token',  'minted' => true],
 *        ['step' => 'device_tokens','count' => 1, 'ids' => [42]],
 *        ['step' => 'fcm_send',     'attempts' => 1],
 *     ],
 *     'results'  => [['token_id'=>42, 'http'=>200, 'response'=>'...'], ...],
 *   ]
 */
function push_debug(int $user_id, ?string $title = null, ?string $body = null): array {
    $log = [];

    /* 1. Config */
    $config = function_exists('notify_load_config') ? notify_load_config() : [];
    $push_cfg = (array)($config['push'] ?? []);
    $log[] = [
        'step'       => 'config',
        'enabled'    => !empty($push_cfg['enabled']),
        'project_id' => (string)($push_cfg['project_id'] ?? ''),
        'has_path'   => !empty($push_cfg['service_account']),
        'has_inline' => !empty($push_cfg['service_account_json']),
    ];
    if (empty($push_cfg['enabled'])) {
        return _push_debug_bail($log, "notify_push.enabled is false in data/db.php");
    }
    if (empty($push_cfg['project_id'])) {
        return _push_debug_bail($log, "notify_push.project_id is missing in data/db.php");
    }

    /* 2. Service account JSON */
    $sa = _fcm_load_service_account($push_cfg);
    if (!$sa) {
        $hint = !empty($push_cfg['service_account'])
            ? "tried to read " . $push_cfg['service_account'] . " — file missing, unreadable, or not valid JSON"
            : "no 'service_account' path or 'service_account_json' inline value found";
        return _push_debug_bail($log, "service-account JSON could not be loaded ($hint)");
    }
    if (empty($sa['client_email']) || empty($sa['private_key'])) {
        return _push_debug_bail($log, "service-account JSON is missing client_email or private_key");
    }
    $log[] = [
        'step'         => 'service_account',
        'client_email' => (string)$sa['client_email'],
        'project_id'   => (string)($sa['project_id'] ?? ''),
        'pk_preview'   => substr((string)$sa['private_key'], 0, 32) . '…',
    ];

    /* 3. OAuth token (signs the JWT and trades it for an access token) */
    $access = _fcm_access_token($push_cfg);
    if (!$access) {
        return _push_debug_bail($log, "could not mint OAuth access token — check the private_key is intact and the service account hasn't been revoked in Firebase Console");
    }
    $log[] = [
        'step'    => 'oauth_token',
        'minted'  => true,
        'preview' => substr($access, 0, 24) . '…',
    ];

    /* 4. Device tokens for the target user */
    $tokens = device_tokens_for_user($user_id);
    $log[] = [
        'step'  => 'device_tokens',
        'count' => count($tokens),
        'ids'   => array_map(fn($t) => (int)$t['id'], $tokens),
    ];
    if (!$tokens) {
        return _push_debug_bail($log, "no active device tokens for user $user_id — sign in to the native app on the target device first so PushTokenRegistrar can register a token");
    }

    /* 5. Send the test push to every device, one at a time, capturing
     *    the raw FCM response so the operator can read the failure. */
    $title = $title ?: 'WiFiber push test';
    $body  = $body  ?: 'If you can read this, the FCM pipeline is working end-to-end.';
    $project = (string)$push_cfg['project_id'];
    $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

    $results = [];
    $sent = 0;
    foreach ($tokens as $row) {
        $payload = json_encode([
            'message' => [
                'token'        => $row['token'],
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => ['template' => 'debug.test', 'user_id' => (string)$user_id],
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
        $resp = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        $ok = $http >= 200 && $http < 300;
        if ($ok) {
            $sent++;
            device_token_touch((int)$row['id']);
        }
        $results[] = [
            'token_id'      => (int)$row['id'],
            'platform'      => (string)$row['platform'],
            'device_label'  => (string)($row['device_label'] ?? ''),
            'token_preview' => substr((string)$row['token'], 0, 24) . '…',
            'http'          => $http,
            'response'      => mb_substr($resp, 0, 800),
            'curl_error'    => $err,
            'ok'            => $ok,
        ];
    }
    $log[] = ['step' => 'fcm_send', 'attempts' => count($results), 'sent' => $sent];

    /* Mirror the result into notification_log so the regular delivery
     *  table shows the test alongside real notifications. */
    if (function_exists('_notify_log')) {
        $user = function_exists('find_user_by_id') ? find_user_by_id($user_id) : null;
        if ($user) {
            _notify_log(
                $user, 'push', 'debug.test',
                'fcm:' . count($tokens) . ' devices', $title,
                $sent > 0 ? 'sent' : 'failed',
                $sent > 0 ? '' : 'all FCM deliveries failed (see debug panel)',
                0.0,
                ['debug' => true, 'results' => $results]
            );
        }
    }

    return [
        'ok'        => $sent > 0,
        'attempted' => count($results),
        'sent'      => $sent,
        'failed'    => count($results) - $sent,
        'log'       => $log,
        'results'   => $results,
    ];
}

function _push_debug_bail(array $log, string $error): array {
    return [
        'ok'        => false,
        'error'     => $error,
        'attempted' => 0,
        'sent'      => 0,
        'failed'    => 0,
        'log'       => $log,
        'results'   => [],
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
