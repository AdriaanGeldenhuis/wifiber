<?php
/**
 * Customer notifications gateway.
 *
 * One uniform API:
 *
 *   notify_send($user, $template, $data, $channels = null)
 *
 * Routes by channel preference (users.notify_prefs JSON), falls back to
 * the channel defaults configured in data/db.php (or db.local.php). Each
 * delivery attempt writes a notification_log row regardless of outcome
 * so "did this customer get the SMS?" is one SQL query.
 *
 * Channels live in auth/channels/*.php and each implement:
 *
 *   channel_NAME_send(array $user, string $template, array $data,
 *                     array $config): array
 *     returns ['ok'=>bool, 'recipient'=>str, 'subject'=>str,
 *             'cost_zar'=>?float, 'error'=>str, 'meta'=>array]
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/channels/email.php';
require_once __DIR__ . '/channels/sms.php';
require_once __DIR__ . '/channels/whatsapp.php';
require_once __DIR__ . '/channels/slack.php';

const NOTIFY_CHANNELS = ['email', 'sms', 'whatsapp', 'slack'];

/**
 * Templates: subject + email/SMS body builders. Add new templates here
 * rather than constructing strings at the call site so the wording is
 * consistent across email + SMS + WhatsApp.
 *
 *   outage.opened        — sector or device went down
 *   outage.resolved      — all clear
 *   link.signal_drop     — signal dropped >6 dB / 24h (Phase 10)
 *   cable.snr_drop       — cable SNR regression (Phase 5 worker)
 *   cred.fail            — vendor adapter auth fail 3x (Phase 7)
 *   maintenance.upcoming — Phase 13 reminders
 */
function notify_template(string $template, array $data, string $channel): array {
    $site_name = (string)((load_site_settings()['name']) ?? 'WiFIBER');

    $subject = '';
    $body    = '';

    switch ($template) {
        case 'outage.opened':
            $subject = "Service issue affecting your {$site_name} connection";
            $body = "Hi " . ($data['name'] ?? '') . ",\n\n"
                  . "We've detected a service issue affecting your link"
                  . (!empty($data['scope_label']) ? " (" . $data['scope_label'] . ")" : "")
                  . ".\n\nWe're already on it — you don't need to log a ticket. "
                  . "Cause: " . ($data['cause'] ?? 'investigating') . "\n\n"
                  . "We'll send an all-clear when service is restored.\n— {$site_name}";
            break;
        case 'outage.resolved':
            $subject = "Service restored — {$site_name}";
            $body = "Hi " . ($data['name'] ?? '') . ",\n\n"
                  . "Your connection is back. Sorry for the disruption.\n\n— {$site_name}";
            break;
        case 'link.signal_drop':
            $subject = "{$site_name} link health alert";
            $body = "Hi " . ($data['name'] ?? '') . ",\n\nWe noticed your wireless "
                  . "signal dropped " . (int)($data['drop_db'] ?? 0) . " dB over the "
                  . "last 24 hours. We've opened a ticket and a technician will be "
                  . "in touch.\n\n— {$site_name}";
            break;
        case 'cable.snr_drop':
            $subject = "[NOC] Cable SNR regression on " . ($data['device_name'] ?? '');
            $body = "Cable SNR on " . ($data['device_name'] ?? '?') . " dropped "
                  . number_format((float)($data['drop_db'] ?? 0), 1)
                  . " dB over the last " . ($data['window_days'] ?? 7) . " days.\n"
                  . "Likely cause: water ingress, UV-cracked sheath, or a working-loose crimp.";
            break;
        case 'cred.fail':
            $subject = "[NOC] Credentials failing on " . ($data['device_name'] ?? '');
            $body = "Vendor adapter auth has failed " . ($data['fails'] ?? 3) . "x in a row "
                  . "for " . ($data['device_name'] ?? '?') . ". Telemetry has stopped.\n"
                  . "Rotate credentials in /admin/devices.php → Creds.";
            break;
        case 'maintenance.upcoming':
            $subject = "Scheduled maintenance — " . ($data['scope_label'] ?? '');
            $body = "Hi " . ($data['name'] ?? '') . ",\n\n"
                  . "We're doing planned maintenance on your link at "
                  . ($data['starts_at'] ?? '?') . " (expected duration: "
                  . ($data['duration_minutes'] ?? '?') . " minutes).\n\n"
                  . "Reason: " . ($data['reason'] ?? 'routine')
                  . "\n\n— {$site_name}";
            break;
        default:
            $subject = $data['subject'] ?? "Notification from {$site_name}";
            $body    = $data['body']    ?? '';
    }

    if ($channel === 'sms' || $channel === 'whatsapp') {
        // SMS body: shorten + drop the salutation.
        $sms_body = trim(preg_replace('/Hi [^,]+,\n+/', '', $body));
        $sms_body = mb_substr($sms_body, 0, 320);
        return ['subject' => $subject, 'body' => $sms_body];
    }
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Customer's per-channel opt-in. notify_prefs is JSON like
 *   {"sms_outage": true, "email_outage": true, "whatsapp_outage": false}
 * Missing keys default to true for outage events (we want to reach them)
 * and false for non-critical templates.
 */
function notify_user_wants(array $user, string $channel, string $template): bool {
    $prefs = $user['notify_prefs'] ?? null;
    if (is_string($prefs)) $prefs = json_decode($prefs, true);
    if (!is_array($prefs)) $prefs = [];
    $key = $channel . '_' . _notify_template_group($template);
    if (array_key_exists($key, $prefs)) return (bool)$prefs[$key];
    // Default policy: reach customers on all channels for outages,
    // email-only for everything else.
    return _notify_template_group($template) === 'outage'
        || $channel === 'email';
}

function _notify_template_group(string $template): string {
    if (str_starts_with($template, 'outage.'))      return 'outage';
    if (str_starts_with($template, 'maintenance.')) return 'maintenance';
    if (str_starts_with($template, 'link.'))        return 'link';
    if (str_starts_with($template, 'cable.'))       return 'noc';
    if (str_starts_with($template, 'cred.'))        return 'noc';
    return 'other';
}

/**
 * Send one notification to one user across whichever channels they
 * accept. Returns ['attempted'=>int, 'sent'=>int, 'failed'=>int, 'skipped'=>int].
 *
 * $channels=null → use defaults (email always, sms/whatsapp if configured
 * and the user opted in). Pass an explicit array to force a channel set
 * (e.g. NOC alerts go email only).
 */
function notify_send(array $user, string $template, array $data, ?array $channels = null): array {
    $channels = $channels ?? notify_default_channels($template);
    $config   = notify_load_config();
    $stats    = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($channels as $channel) {
        if (!in_array($channel, NOTIFY_CHANNELS, true)) continue;
        if (!notify_user_wants($user, $channel, $template)) {
            _notify_log($user, $channel, $template, '', '', 'skipped', 'opted out', null, null);
            $stats['skipped']++;
            continue;
        }
        if (!notify_channel_available($channel, $config)) {
            _notify_log($user, $channel, $template, '', '', 'skipped', 'channel not configured', null, null);
            $stats['skipped']++;
            continue;
        }
        $stats['attempted']++;
        $tpl = notify_template($template, array_merge($data, ['name' => $user['name'] ?? '']), $channel);
        $fn = 'channel_' . $channel . '_send';
        $r = function_exists($fn)
            ? $fn($user, $template, $tpl, $config[$channel] ?? [])
            : ['ok' => false, 'error' => "no channel handler: $fn"];
        $ok = !empty($r['ok']);
        _notify_log(
            $user, $channel, $template,
            (string)($r['recipient'] ?? ''), (string)($tpl['subject'] ?? ''),
            $ok ? 'sent' : 'failed',
            (string)($r['error'] ?? ''),
            $r['cost_zar'] ?? null,
            $r['meta'] ?? null
        );
        $ok ? $stats['sent']++ : $stats['failed']++;
    }
    return $stats;
}

function notify_default_channels(string $template): array {
    // NOC-internal alerts → email only.
    $group = _notify_template_group($template);
    if ($group === 'noc') return ['email'];
    // Customer-visible: try SMS first (most reliable in SA), then email.
    return ['sms', 'email'];
}

function notify_channel_available(string $channel, array $config): bool {
    if ($channel === 'email')    return true;
    if ($channel === 'sms')      return !empty($config['sms']['enabled']);
    if ($channel === 'whatsapp') return !empty($config['whatsapp']['enabled']);
    if ($channel === 'slack')    return !empty($config['slack']['enabled']);
    return false;
}

function notify_load_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg_file = is_file(DATA_DIR . '/db.local.php')
        ? DATA_DIR . '/db.local.php'
        : DATA_DIR . '/db.php';
    $raw = is_file($cfg_file) ? require $cfg_file : [];
    $cfg = [
        'email'    => $raw['notify_email']    ?? ['from' => 'no-reply@wifiber.co.za'],
        'sms'      => $raw['notify_sms']      ?? ['enabled' => false],
        'whatsapp' => $raw['notify_whatsapp'] ?? ['enabled' => false],
        'slack'    => $raw['notify_slack']    ?? ['enabled' => false],
    ];
    return $cfg;
}

function _notify_log(array $user, string $channel, string $template,
    string $recipient, string $subject, string $status, string $error,
    ?float $cost_zar, ?array $meta): void
{
    pdo()->prepare(
        "INSERT INTO notification_log
            (user_id, channel, template, recipient, subject, status, error, cost_zar, meta_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        !empty($user['id']) ? (int)$user['id'] : null,
        $channel, $template,
        mb_substr($recipient, 0, 160), mb_substr($subject, 0, 200),
        $status, mb_substr($error, 0, 500),
        $cost_zar,
        $meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

/**
 * Convenience: replay a recent notification (e.g. operator clicks
 * "Resend" in /admin/notifications.php). Looks up the original log row
 * and re-fires through notify_send with the same template/data.
 */
function notify_recent(?int $user_id = null, int $limit = 50): array {
    $limit = max(1, min(500, $limit));
    $sql = "SELECT * FROM notification_log";
    $args = [];
    if ($user_id !== null) {
        $sql .= " WHERE user_id = ?";
        $args[] = $user_id;
    }
    $sql .= " ORDER BY sent_at DESC, id DESC LIMIT $limit";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}
