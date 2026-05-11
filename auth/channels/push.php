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

    $trace = [];
    $access = _fcm_access_token($config, $trace);
    if ($access === null) {
        $why = _fcm_trace_summary($trace);
        return [
            'ok'        => false,
            'recipient' => 'fcm',
            'error'     => $why ?: 'fcm not configured (missing service account or project id)',
            'meta'      => ['trace' => $trace],
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
        $resp = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http >= 200 && $http < 300) {
            device_token_touch((int)$row['id']);
            $sent_to[] = (int)$row['id'];
            continue;
        }

        // Only revoke when FCM tells us the token itself is dead — see
        // _fcm_response_revokes_token() for the narrow set of errorCodes
        // we treat as "this token is gone, stop using it". A bare
        // INVALID_ARGUMENT from a malformed payload is NOT a token kill.
        if (_fcm_response_revokes_token($http, $resp)) {
            device_token_revoke((int)$row['id']);
            $revoked[] = (int)$row['id'];
        }
        $failed[] = [
            'id'   => (int)$row['id'],
            'http' => $http,
            'body' => mb_substr($resp, 0, 400),
        ];
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
 * Decide whether an FCM HTTP v1 error response means the device token
 * itself is permanently dead and should be revoked locally. Conservative
 * on purpose: a bare INVALID_ARGUMENT can come from a malformed message
 * (our bug, not the token's), so we only revoke when FCM names one of
 * the token-shaped errorCodes — UNREGISTERED, NOT_FOUND,
 * SENDER_ID_MISMATCH — or returns a clean 404/410.
 *
 * FCM v1 error body shape:
 *   { "error": { "code": 404, "status": "NOT_FOUND",
 *                "details": [{ "@type": "...FcmError",
 *                              "errorCode": "UNREGISTERED" }] } }
 */
function _fcm_response_revokes_token(int $http, string $body): bool {
    if ($http === 404 || $http === 410) return true;
    if ($body === '') return false;

    $codes = [];
    $j = json_decode($body, true);
    if (is_array($j)) {
        if (!empty($j['error']['status']))  $codes[] = (string)$j['error']['status'];
        if (!empty($j['error']['details']) && is_array($j['error']['details'])) {
            foreach ($j['error']['details'] as $d) {
                if (is_array($d) && !empty($d['errorCode'])) {
                    $codes[] = (string)$d['errorCode'];
                }
            }
        }
    }
    foreach ($codes as $c) {
        if (in_array($c, ['UNREGISTERED', 'NOT_FOUND', 'SENDER_ID_MISMATCH'], true)) {
            return true;
        }
    }

    // Last-resort substring check for malformed/non-JSON bodies.
    $blob = strtolower($body);
    return str_contains($blob, 'unregistered')
        || str_contains($blob, 'sender_id_mismatch');
}

/**
 * Mint (or reuse) an OAuth 2.0 access token for the FCM HTTP v1 API.
 *
 * On success: returns the access_token string and (if $trace was passed)
 *             appends a row per pipeline step describing what happened.
 * On failure: returns null. $trace's last row carries the reason — the
 *             "Debug push" UI in /admin/notifications.php?debug=1 reads
 *             it directly so the operator sees the actual failure (e.g.
 *             openssl_sign error, Google's "invalid_grant" body) instead
 *             of a generic "could not mint" message.
 *
 * The trace never echoes the private_key bytes — only metadata (length,
 * header, looks-pkcs8). client_email and project_id are safe to surface.
 */
function _fcm_access_token(array $config, ?array &$trace = null): ?string {
    if ($trace === null) $trace = [];

    $trace[] = [
        'step'   => 'config',
        'ok'     => !empty($config['enabled']) && !empty($config['project_id']),
        'detail' => [
            'enabled'    => !empty($config['enabled']),
            'project_id' => (string)($config['project_id'] ?? ''),
            'has_path'   => !empty($config['service_account']),
            'has_inline' => !empty($config['service_account_json']),
        ],
    ];
    if (empty($config['enabled'])) {
        $trace[count($trace) - 1]['error'] = 'push channel disabled in notify_push config';
        return null;
    }
    if (empty($config['project_id'])) {
        $trace[count($trace) - 1]['error'] = 'project_id missing from notify_push config';
        return null;
    }

    $sa_trace = null;
    $sa = _fcm_load_service_account($config, $sa_trace);
    $trace[] = $sa_trace;
    if ($sa === null) return null;

    if (empty($sa['client_email']) || empty($sa['private_key'])) {
        $trace[] = [
            'step'  => 'service_account_fields',
            'ok'    => false,
            'error' => 'service account JSON missing client_email or private_key',
        ];
        return null;
    }

    // Most common breakage: the private_key arrives with literal "\n"
    // (two chars) instead of real newlines because someone pasted the
    // service-account JSON into a PHP array or env var. openssl_sign()
    // returns false on that input with no helpful error. Normalize it.
    $key_raw  = (string)$sa['private_key'];
    $key_norm = _fcm_normalize_private_key($key_raw);
    $key_meta = [
        'length'        => strlen($key_norm),
        'header'        => _fcm_key_header($key_norm),
        'normalized'    => ($key_norm !== $key_raw),
        'has_real_lf'   => str_contains($key_norm, "\n"),
        'pem_complete'  => str_contains($key_norm, '-----BEGIN') && str_contains($key_norm, '-----END'),
    ];
    $trace[] = [
        'step'   => 'private_key',
        'ok'     => $key_meta['pem_complete'] && $key_meta['has_real_lf'],
        'detail' => $key_meta,
    ];
    if (!$key_meta['pem_complete'] || !$key_meta['has_real_lf']) {
        $trace[count($trace) - 1]['error'] =
            'private_key does not look like a valid PEM block — '
            . 'check the JSON has real newlines, not literal \\n';
        return null;
    }

    $cache_key = '_fcm_token_' . md5((string)$sa['client_email']);
    $cached = $_SESSION[$cache_key] ?? null;
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60) {
        $trace[] = [
            'step'   => 'cache_hit',
            'ok'     => true,
            'detail' => ['expires_in' => (int)$cached['exp'] - time()],
        ];
        return (string)$cached['access'];
    }

    // Back-date iat by a small skew margin so a server clock that is
    // slightly ahead of Google's doesn't produce a JWT that looks
    // "issued in the future". Google rejects those — sometimes with the
    // obvious "Token used too early" message, but sometimes with a
    // generic "Invalid JWT Signature" — so we always pay the 30 s cost.
    // exp is pinned to iat + 3600 to stay inside Google's hard 1-hour
    // JWT-lifetime ceiling.
    $now  = time();
    $skew = 30;
    $iat  = $now - $skew;
    $exp  = $iat + 3600;
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $iat,
        'exp'   => $exp,
    ];
    $segments = [
        _fcm_b64url(json_encode($header)),
        _fcm_b64url(json_encode($claims)),
    ];
    $signing_input = implode('.', $segments);
    $signature = '';
    // Drain any prior errors from the OpenSSL queue so we report a clean signal.
    while (openssl_error_string() !== false) { /* drain */ }
    $ok = openssl_sign($signing_input, $signature, $key_norm, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        $errs = [];
        while (($e = openssl_error_string()) !== false) $errs[] = $e;
        $trace[] = [
            'step'   => 'jwt_sign',
            'ok'     => false,
            'error'  => 'openssl_sign() failed — private_key cannot be parsed by OpenSSL',
            'detail' => ['openssl_errors' => $errs],
        ];
        return null;
    }
    $trace[] = [
        'step'   => 'jwt_sign',
        'ok'     => true,
        'detail' => [
            'alg'        => 'RS256',
            'sig_bytes'  => strlen($signature),
            'iat'        => $iat,
            'exp'        => $exp,
            'server_now' => $now,
            'skew_back'  => $skew,
        ],
    ];
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
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http === 0) {
        $trace[] = [
            'step'   => 'oauth_post',
            'ok'     => false,
            'error'  => 'network error talking to oauth2.googleapis.com: ' . $curl_err,
            'detail' => ['http' => $http],
        ];
        return null;
    }
    if ($http !== 200) {
        $trace[] = [
            'step'   => 'oauth_post',
            'ok'     => false,
            'error'  => 'Google rejected the JWT (HTTP ' . $http . ') — ' . _fcm_oauth_hint($resp),
            'detail' => [
                'http' => $http,
                'body' => mb_substr((string)$resp, 0, 800),
            ],
        ];
        return null;
    }
    $j = json_decode((string)$resp, true);
    if (empty($j['access_token'])) {
        $trace[] = [
            'step'   => 'oauth_post',
            'ok'     => false,
            'error'  => 'OAuth 200 but no access_token in body',
            'detail' => ['body' => mb_substr((string)$resp, 0, 800)],
        ];
        return null;
    }

    $_SESSION[$cache_key] = [
        'access' => $j['access_token'],
        'exp'    => $now + (int)($j['expires_in'] ?? 3600),
    ];
    $trace[] = [
        'step'   => 'oauth_post',
        'ok'     => true,
        'detail' => [
            'http'       => $http,
            'expires_in' => (int)($j['expires_in'] ?? 3600),
        ],
    ];
    return (string)$j['access_token'];
}

function _fcm_load_service_account(array $config, ?array &$trace = null): ?array {
    if (!empty($config['service_account_json'])) {
        $raw = (string)$config['service_account_json'];
        $j = json_decode($raw, true);
        $trace = [
            'step'   => 'service_account',
            'ok'     => is_array($j),
            'detail' => [
                'source'       => 'inline',
                'bytes'        => strlen($raw),
                'client_email' => is_array($j) ? (string)($j['client_email'] ?? '') : '',
                'project_id'   => is_array($j) ? (string)($j['project_id']   ?? '') : '',
            ],
        ];
        if (!is_array($j)) {
            $trace['error'] = 'service_account_json could not be parsed: '
                            . json_last_error_msg();
            return null;
        }
        return $j;
    }
    $path = (string)($config['service_account'] ?? '');
    $trace = [
        'step'   => 'service_account',
        'ok'     => false,
        'detail' => ['source' => 'path', 'path' => $path],
    ];
    if ($path === '') {
        $trace['error'] = 'no service_account path or service_account_json configured';
        return null;
    }
    if (!is_file($path)) {
        $trace['error'] = 'service_account file does not exist: ' . $path;
        return null;
    }
    if (!is_readable($path)) {
        $trace['error'] = 'service_account file is not readable by the web user: ' . $path;
        return null;
    }
    $raw = (string)@file_get_contents($path);
    $j = json_decode($raw, true);
    $trace['detail']['bytes'] = strlen($raw);
    if (!is_array($j)) {
        $trace['error'] = 'service_account file is not valid JSON: ' . json_last_error_msg();
        return null;
    }
    $trace['ok'] = true;
    $trace['detail']['client_email'] = (string)($j['client_email'] ?? '');
    $trace['detail']['project_id']   = (string)($j['project_id']   ?? '');
    return $j;
}

function _fcm_b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * Repair the most common ways a service-account private_key gets mangled
 * between Firebase Console and the running web server:
 *   - literal "\n" two-char sequences instead of real newlines (env vars,
 *     PHP single-quoted strings)
 *   - Windows CRLF line endings
 *   - UTF-8 BOM at the start of the file
 *   - leading / trailing whitespace
 */
function _fcm_normalize_private_key(string $key): string {
    if (str_starts_with($key, "\xEF\xBB\xBF")) $key = substr($key, 3);
    $key = str_replace(["\r\n", "\r"], "\n", $key);
    if (!str_contains($key, "\n") && str_contains($key, '\\n')) {
        $key = str_replace('\\n', "\n", $key);
    }
    return trim($key) . "\n";
}

function _fcm_key_header(string $key): string {
    foreach (explode("\n", $key) as $line) {
        $line = trim($line);
        if ($line !== '') return $line;
    }
    return '';
}

/**
 * Translate Google's OAuth error JSON into a one-line operator hint.
 * The full body is also stored in the trace for the curious.
 *
 * invalid_grant is split by sub-cause: the time-shaped descriptions
 * ("too early", "too late", "expired") really are clock skew, but
 * "Invalid JWT Signature" almost always means the private_key on disk
 * no longer matches the public key Firebase has on file — i.e. the
 * service-account key was rotated or revoked.
 */
function _fcm_oauth_hint(string $body): string {
    $j = json_decode($body, true);
    if (!is_array($j)) return 'see body';
    $code = (string)($j['error'] ?? '');
    $desc = (string)($j['error_description'] ?? '');
    if ($code === 'invalid_grant') {
        $d = strtolower($desc);
        if (str_contains($d, 'too early') || str_contains($d, 'future')) {
            return 'invalid_grant: JWT issued in the future — this server\'s clock is ahead of Google\'s, check NTP sync';
        }
        if (str_contains($d, 'too late') || str_contains($d, 'expired')) {
            return 'invalid_grant: JWT expired before Google read it — this server\'s clock is behind, check NTP sync';
        }
        if (str_contains($d, 'signature')) {
            return 'invalid_grant: Invalid JWT Signature — the service-account private_key on disk no longer matches the public key Firebase has, the key was almost certainly rotated/revoked in Firebase Console (clock skew does not cause this)';
        }
        return 'invalid_grant — ' . ($desc ?: 'check clock sync, or whether the service-account key was rotated/revoked in Firebase Console');
    }
    return match ($code) {
        'invalid_client' => 'invalid_client — the service account no longer exists or has been disabled',
        'invalid_scope'  => 'invalid_scope — JWT scope must be https://www.googleapis.com/auth/firebase.messaging',
        ''               => $desc ?: 'see body',
        default          => $code . ($desc ? ' — ' . $desc : ''),
    };
}

/**
 * Pull the human-readable failure reason out of a trace produced by
 * _fcm_access_token() / channel_push_send(). Used by the notification
 * log so a single sql query tells the operator why a push failed.
 */
function _fcm_trace_summary(array $trace): string {
    for ($i = count($trace) - 1; $i >= 0; $i--) {
        $row = $trace[$i];
        if (!empty($row['ok'])) continue;
        if (!empty($row['error'])) return (string)$row['error'];
    }
    return '';
}
