<?php
/**
 * Email channel — wraps PHP's mail() with consistent From / Reply-To
 * headers. The site already uses mail() everywhere (see
 * auth/invoices.php, helpers.php::send_welcome_email, etc.); this
 * lifts the boilerplate into one place so notify_send can route
 * uniformly.
 */

declare(strict_types=1);

function channel_email_send(array $user, string $template, array $tpl, array $config): array {
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'recipient' => $email, 'error' => 'no valid email'];
    }
    $from = (string)($config['from']    ?? 'no-reply@wifiber.co.za');
    $reply = (string)($config['reply_to'] ?? $from);
    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$reply}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($email, (string)$tpl['subject'], (string)$tpl['body'], $headers);
    return [
        'ok'         => (bool)$sent,
        'recipient'  => $email,
        'subject'    => (string)$tpl['subject'],
        'cost_zar'   => 0.0,
        'error'      => $sent ? '' : 'mail() returned false',
        'meta'       => ['from' => $from],
    ];
}
