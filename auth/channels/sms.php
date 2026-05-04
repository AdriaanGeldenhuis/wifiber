<?php
/**
 * SMS channel — multi-provider (Twilio / ClickSend / SMSPortal) HTTP
 * wrapper. Provider is chosen via data/db.php:
 *
 *   'notify_sms' => [
 *     'enabled'  => true,
 *     'provider' => 'twilio',         // or 'clicksend', 'smsportal'
 *     'from'     => '+27815551234',
 *     // Twilio
 *     'account_sid' => '...', 'auth_token' => '...',
 *     // ClickSend
 *     'username'    => '...', 'api_key'    => '...',
 *     // SMSPortal
 *     'client_id'   => '...', 'secret'     => '...',
 *   ],
 *
 * All providers return the same DTO so notify_send() doesn't care.
 */

declare(strict_types=1);

function channel_sms_send(array $user, string $template, array $tpl, array $config): array {
    $phone = _sms_normalise_e164(
        (string)($user['phone_e164'] ?? $user['phone'] ?? '')
    );
    if ($phone === '') {
        return ['ok' => false, 'recipient' => '', 'error' => 'no E.164 phone number'];
    }
    $body = (string)$tpl['body'];
    $provider = strtolower((string)($config['provider'] ?? 'twilio'));

    return match ($provider) {
        'twilio'    => _sms_send_twilio($phone, $body, $config),
        'clicksend' => _sms_send_clicksend($phone, $body, $config),
        'smsportal' => _sms_send_smsportal($phone, $body, $config),
        default     => ['ok' => false, 'recipient' => $phone,
                        'error' => "unknown SMS provider: $provider"],
    };
}

function _sms_normalise_e164(string $raw): string {
    $raw = preg_replace('/\s+/', '', $raw);
    if ($raw === '') return '';
    if (str_starts_with($raw, '+')) return $raw;
    // South African convention: 0XX XXX XXXX → +27XXX XXX XXXX
    if (str_starts_with($raw, '0') && strlen($raw) === 10) {
        return '+27' . substr($raw, 1);
    }
    if (str_starts_with($raw, '27') && strlen($raw) === 11) {
        return '+' . $raw;
    }
    return '';
}

function _sms_send_twilio(string $to, string $body, array $cfg): array {
    $sid = (string)($cfg['account_sid'] ?? '');
    $tok = (string)($cfg['auth_token']  ?? '');
    if ($sid === '' || $tok === '') {
        return ['ok' => false, 'recipient' => $to, 'error' => 'twilio creds missing'];
    }
    $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "{$sid}:{$tok}",
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'From' => (string)($cfg['from'] ?? ''),
            'To'   => $to,
            'Body' => $body,
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    return [
        'ok'        => $http >= 200 && $http < 300,
        'recipient' => $to,
        'cost_zar'  => isset($j['price']) ? (float)abs($j['price']) * 18 : null, // USD→ZAR rough
        'error'     => $http >= 200 && $http < 300 ? '' : "http $http: " . ($j['message'] ?? ''),
        'meta'      => ['sid' => $j['sid'] ?? null, 'status' => $j['status'] ?? null],
    ];
}

function _sms_send_clicksend(string $to, string $body, array $cfg): array {
    $user = (string)($cfg['username'] ?? '');
    $key  = (string)($cfg['api_key']  ?? '');
    if ($user === '' || $key === '') {
        return ['ok' => false, 'recipient' => $to, 'error' => 'clicksend creds missing'];
    }
    $payload = json_encode(['messages' => [[
        'from' => (string)($cfg['from'] ?? ''),
        'to'   => $to,
        'body' => $body,
    ]]]);
    $ch = curl_init('https://rest.clicksend.com/v3/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "{$user}:{$key}",
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    $msg = $j['data']['messages'][0] ?? [];
    return [
        'ok'        => $http >= 200 && $http < 300,
        'recipient' => $to,
        'cost_zar'  => isset($msg['message_price']) ? (float)$msg['message_price'] * 18 : null,
        'error'     => $http >= 200 && $http < 300 ? '' : "http $http",
        'meta'      => ['msg_id' => $msg['message_id'] ?? null, 'status' => $msg['status'] ?? null],
    ];
}

function _sms_send_smsportal(string $to, string $body, array $cfg): array {
    $cid = (string)($cfg['client_id'] ?? '');
    $sec = (string)($cfg['secret']    ?? '');
    if ($cid === '' || $sec === '') {
        return ['ok' => false, 'recipient' => $to, 'error' => 'smsportal creds missing'];
    }
    // 1. exchange basic auth → bearer token
    $ch = curl_init('https://rest.smsportal.com/v1/Authentication');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "{$cid}:{$sec}",
        CURLOPT_TIMEOUT        => 10,
    ]);
    $auth = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) {
        return ['ok' => false, 'recipient' => $to, 'error' => "smsportal auth http $http"];
    }
    $token = (string)(json_decode((string)$auth, true)['token'] ?? '');

    // 2. send
    $ch = curl_init('https://rest.smsportal.com/v1/BulkMessages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['messages' => [[
            'destination' => preg_replace('/^\+/', '', $to),
            'content'     => $body,
        ]]]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    $msg = $j['messages'][0] ?? [];
    return [
        'ok'        => $http >= 200 && $http < 300,
        'recipient' => $to,
        'cost_zar'  => isset($msg['cost']) ? (float)$msg['cost'] : null,
        'error'     => $http >= 200 && $http < 300 ? '' : "smsportal http $http",
        'meta'      => ['event_id' => $j['eventId'] ?? null],
    ];
}
