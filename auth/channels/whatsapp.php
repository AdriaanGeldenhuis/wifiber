<?php
/**
 * WhatsApp channel — Twilio Business API (Meta Cloud API alternate
 * supported via the same provider key). Same auth as Twilio SMS but
 * the "From" is a whatsapp:+E164 sender.
 *
 *   'notify_whatsapp' => [
 *     'enabled'     => true,
 *     'provider'    => 'twilio',  // or 'meta'
 *     // Twilio
 *     'account_sid' => '...', 'auth_token' => '...',
 *     'from'        => '+27815551234',
 *     // Meta Cloud API
 *     'phone_id'    => '123456', 'access_token' => '...',
 *   ],
 */

declare(strict_types=1);

require_once __DIR__ . '/sms.php';   // for _sms_normalise_e164

function channel_whatsapp_send(array $user, string $template, array $tpl, array $config): array {
    $phone = _sms_normalise_e164(
        (string)($user['phone_e164'] ?? $user['phone'] ?? '')
    );
    if ($phone === '') {
        return ['ok' => false, 'recipient' => '', 'error' => 'no E.164 phone number'];
    }
    $body = (string)$tpl['body'];
    $provider = strtolower((string)($config['provider'] ?? 'twilio'));

    return match ($provider) {
        'twilio' => _wa_send_twilio($phone, $body, $config),
        'meta'   => _wa_send_meta($phone, $body, $config),
        default  => ['ok' => false, 'recipient' => $phone,
                     'error' => "unknown WhatsApp provider: $provider"],
    };
}

function _wa_send_twilio(string $to, string $body, array $cfg): array {
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
            'From' => 'whatsapp:' . (string)($cfg['from'] ?? ''),
            'To'   => 'whatsapp:' . $to,
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
        'cost_zar'  => isset($j['price']) ? (float)abs($j['price']) * 18 : null,
        'error'     => $http >= 200 && $http < 300 ? '' : "twilio whatsapp http $http",
        'meta'      => ['sid' => $j['sid'] ?? null],
    ];
}

function _wa_send_meta(string $to, string $body, array $cfg): array {
    $pid = (string)($cfg['phone_id']     ?? '');
    $tok = (string)($cfg['access_token'] ?? '');
    if ($pid === '' || $tok === '') {
        return ['ok' => false, 'recipient' => $to, 'error' => 'meta creds missing'];
    }
    $ch = curl_init("https://graph.facebook.com/v17.0/{$pid}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => preg_replace('/^\+/', '', $to),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tok,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    return [
        'ok'        => $http >= 200 && $http < 300,
        'recipient' => $to,
        'cost_zar'  => null,  // Meta doesn't return per-message cost
        'error'     => $http >= 200 && $http < 300 ? '' : "meta http $http: " . ($j['error']['message'] ?? ''),
        'meta'      => ['wamid' => $j['messages'][0]['id'] ?? null],
    ];
}
