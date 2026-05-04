<?php
/**
 * Slack channel — incoming-webhook poster. Per-user opt-in works the
 * same as email/sms/whatsapp: notify_prefs[slack_<group>] gates the
 * send. The webhook URL can be a per-user override (users.slack_webhook
 * — added in phase31_roles.sql) or fall back to the channel default
 * configured in data/db.php:
 *
 *   'notify_slack' => [
 *     'enabled'     => true,
 *     'webhook_url' => 'https://hooks.slack.com/services/T.../B.../xxx',
 *     'username'    => 'WiFIBER',     // optional
 *     'icon_emoji'  => ':satellite:', // optional
 *   ],
 *
 * Templates render as plain Slack messages; the body comes from
 * notify_template() so wording stays consistent with other channels.
 */

declare(strict_types=1);

function channel_slack_send(array $user, string $template, array $tpl, array $config): array {
    $hook = trim((string)($user['slack_webhook'] ?? ''));
    if ($hook === '') $hook = (string)($config['webhook_url'] ?? '');
    if ($hook === '' || !preg_match('#^https://hooks\.slack\.com/services/#', $hook)) {
        return ['ok' => false, 'recipient' => '', 'error' => 'no slack webhook configured'];
    }

    $subject = (string)($tpl['subject'] ?? '');
    $body    = (string)($tpl['body']    ?? '');
    $text    = $subject !== '' ? "*{$subject}*\n{$body}" : $body;

    $payload = ['text' => $text];
    if (!empty($config['username']))   $payload['username']   = (string)$config['username'];
    if (!empty($config['icon_emoji'])) $payload['icon_emoji'] = (string)$config['icon_emoji'];

    $ch = curl_init($hook);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'        => $http >= 200 && $http < 300,
        'recipient' => preg_replace('#/[^/]+$#', '/…', $hook),
        'cost_zar'  => 0.0,
        'error'     => $http >= 200 && $http < 300 ? '' : "slack http $http: " . substr((string)$resp, 0, 200),
        'meta'      => ['http' => $http],
    ];
}
