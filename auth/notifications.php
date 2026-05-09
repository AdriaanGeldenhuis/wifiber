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
require_once __DIR__ . '/channels/push.php';

const NOTIFY_CHANNELS = ['email', 'sms', 'whatsapp', 'slack', 'push'];

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
        case 'install.scheduled':
            $subject = "{$site_name} install scheduled";
            $body = "Hi " . ($data['name'] ?? '') . ",\n\n"
                  . "Your {$site_name} install is booked for "
                  . ($data['scheduled_at'] ?? 'a date to be confirmed') . ".\n\n"
                  . "Our technician will call before they arrive. "
                  . "Please make sure someone over 18 is on-site with access to where the equipment will be mounted.\n\n"
                  . "Reply to this message if the date doesn't work — we'll reschedule.\n\n"
                  . "— {$site_name}";
            break;
        case 'install.completed':
            $subject = "Welcome to {$site_name} — you're online";
            $body = "Hi " . ($data['name'] ?? '') . ",\n\n"
                  . "Your {$site_name} install is done and the link is up. "
                  . "If something doesn't look right in the next 24 hours, "
                  . "reply to this message and we'll send a tech back out.\n\n"
                  . "Welcome aboard.\n\n— {$site_name}";
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
    // Default policy: reach customers on all channels for outage and
    // install events (those are direct, time-sensitive customer
    // touchpoints), email-only for everything else.
    $group = _notify_template_group($template);
    return in_array($group, ['outage', 'install'], true)
        || $channel === 'email';
}

function _notify_template_group(string $template): string {
    if (str_starts_with($template, 'outage.'))      return 'outage';
    if (str_starts_with($template, 'maintenance.')) return 'maintenance';
    if (str_starts_with($template, 'install.'))     return 'install';
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
    // Customer-visible: try push (instant on the native app) and SMS
    // (most reliable in SA) first, with email as the durable fallback.
    return ['push', 'sms', 'email'];
}

function notify_channel_available(string $channel, array $config): bool {
    if ($channel === 'email')    return true;
    if ($channel === 'sms')      return !empty($config['sms']['enabled']);
    if ($channel === 'whatsapp') return !empty($config['whatsapp']['enabled']);
    if ($channel === 'slack')    return !empty($config['slack']['enabled']);
    if ($channel === 'push')     return !empty($config['push']['enabled']);
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
        'push'     => $raw['notify_push']     ?? ['enabled' => false],
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

/**
 * Notification log search for the admin notifications page. Returns
 * rows joined with the user (so the operator sees who got what)
 * filtered by channel, status, template, user_id, free-text and date.
 */
function notify_search(array $filters = [], int $limit = 200): array {
    $limit = max(1, min(2000, $limit));
    $sql = "SELECT n.*, u.username, u.name AS client_name, u.role AS user_role
              FROM notification_log n
              LEFT JOIN users u ON u.id = n.user_id";
    $where = [];
    $args  = [];
    if (!empty($filters['channel'])  && in_array($filters['channel'], NOTIFY_CHANNELS, true)) {
        $where[] = 'n.channel = ?'; $args[] = $filters['channel'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'n.status = ?'; $args[] = $filters['status'];
    }
    if (!empty($filters['template'])) {
        $where[] = 'n.template = ?'; $args[] = $filters['template'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'n.user_id = ?'; $args[] = (int)$filters['user_id'];
    }
    if (!empty($filters['from'])) { $where[] = 'n.sent_at >= ?'; $args[] = $filters['from'] . ' 00:00:00'; }
    if (!empty($filters['to']))   { $where[] = 'n.sent_at <= ?'; $args[] = $filters['to']   . ' 23:59:59'; }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $where[] = '(n.subject LIKE ? OR n.recipient LIKE ? OR u.username LIKE ? OR u.name LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY n.sent_at DESC, n.id DESC LIMIT $limit";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/**
 * Per-channel + per-status counts over the last `$days` days. Powers
 * the summary tiles on /admin/notifications.php.
 */
function notify_stats(int $days = 30): array {
    $days = max(1, min(365, $days));
    $stmt = pdo()->prepare(
        "SELECT channel, status, COUNT(*) AS c
           FROM notification_log
          WHERE sent_at >= (NOW() - INTERVAL $days DAY)
          GROUP BY channel, status"
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['channel']][$r['status']] = (int)$r['c'];
        $out[$r['channel']]['total'] = ($out[$r['channel']]['total'] ?? 0) + (int)$r['c'];
    }
    return $out;
}

/* -------------------------------------------------- device tokens (FCM)
 * The native app calls a small API endpoint with its FCM registration
 * token; we store one row per (user, device) here. The push channel
 * (auth/channels/push.php) reads from this table when delivering and
 * marks tokens inactive on FCM 404 / UNREGISTERED responses.
 */

/**
 * Register or refresh a device token. Tokens are unique — re-registering
 * a known token on the same user just bumps last_seen_at; on a different
 * user it transfers the token (sign-out → sign-in on the same device).
 */
function device_token_register(int $user_id, string $platform, string $token, array $extra = []): int {
    $platforms = ['android', 'ios', 'web'];
    if (!in_array($platform, $platforms, true)) $platform = 'android';
    $token = trim($token);
    if ($user_id <= 0 || $token === '') {
        throw new InvalidArgumentException('user_id and token required');
    }
    $app_version  = mb_substr((string)($extra['app_version']  ?? ''), 0, 32);
    $device_label = mb_substr((string)($extra['device_label'] ?? ''), 0, 120);

    pdo()->prepare(
        "INSERT INTO device_tokens
            (user_id, platform, token, app_version, device_label, is_active, registered_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            platform = VALUES(platform),
            app_version = VALUES(app_version),
            device_label = VALUES(device_label),
            is_active = 1,
            last_seen_at = NOW()"
    )->execute([$user_id, $platform, $token, $app_version, $device_label]);

    $stmt = pdo()->prepare("SELECT id FROM device_tokens WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/** Idempotent — bumps last_seen_at on an existing row. No-op on stale id. */
function device_token_touch(int $id): void {
    if ($id <= 0) return;
    pdo()->prepare("UPDATE device_tokens SET last_seen_at = NOW() WHERE id = ?")->execute([$id]);
}

/**
 * Soft-revoke (is_active = 0). Safer than DELETE because the row is
 * still useful for audit ("when did we stop reaching this device?")
 * and the unique key on token blocks accidental duplicate inserts
 * from a buggy app re-registration loop.
 */
function device_token_revoke(int $id): void {
    if ($id <= 0) return;
    pdo()->prepare("UPDATE device_tokens SET is_active = 0 WHERE id = ?")->execute([$id]);
}

function device_token_revoke_by_token(string $token): void {
    $token = trim($token);
    if ($token === '') return;
    pdo()->prepare("UPDATE device_tokens SET is_active = 0 WHERE token = ?")->execute([$token]);
}

function device_tokens_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    $stmt = pdo()->prepare(
        "SELECT * FROM device_tokens
          WHERE user_id = ? AND is_active = 1
          ORDER BY last_seen_at DESC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function device_tokens_count(int $user_id, bool $active_only = true): int {
    if ($user_id <= 0) return 0;
    $sql = "SELECT COUNT(*) FROM device_tokens WHERE user_id = ?";
    if ($active_only) $sql .= " AND is_active = 1";
    $stmt = pdo()->prepare($sql);
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn();
}
