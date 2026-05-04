<?php
/**
 * In-app admin inbox.
 *
 * Surface for events that a logged-in operator should see immediately
 * regardless of email/SMS preferences — outages, push-to-radio job
 * results, drift alerts, etc. The bell icon in portal-header.php reads
 * unread count from here; clicking it opens /admin/inbox.php.
 *
 * Two delivery modes:
 *   • broadcast (user_id = NULL) — every operator with a matching
 *     audience role sees the row. Read state per-user via admin_inbox_read.
 *   • directed  (user_id = N)    — only that user sees the row.
 *
 * See data/migrations/2026_05_04_phase31_acl_inbox.sql for schema.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const INBOX_AUDIENCES = ['any', 'noc', 'billing', 'support'];
const INBOX_SEVERITIES = ['info', 'warning', 'error', 'success'];

/**
 * Insert an inbox row. $opts:
 *   user_id     int|null  directed delivery (default NULL = broadcast)
 *   audience    string    one of INBOX_AUDIENCES (default 'any')
 *   severity    string    one of INBOX_SEVERITIES (default 'info')
 *   link        string    optional URL to open when the row is clicked
 *   dedupe_key  string    if set, skip insert when a matching row was
 *                          posted in the last 24h
 */
function inbox_post(string $title, string $body = '', array $opts = []): ?int {
    $title = trim($title);
    if ($title === '') return null;
    try {
        $audience = $opts['audience'] ?? 'any';
        if (!in_array($audience, INBOX_AUDIENCES, true)) $audience = 'any';
        $severity = $opts['severity'] ?? 'info';
        if (!in_array($severity, INBOX_SEVERITIES, true)) $severity = 'info';
        $user_id  = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
        if ($user_id === 0) $user_id = null;
        $link     = isset($opts['link']) ? mb_substr((string)$opts['link'], 0, 255) : null;
        $dedupe   = isset($opts['dedupe_key']) ? mb_substr((string)$opts['dedupe_key'], 0, 120) : null;

        if ($dedupe !== null && $dedupe !== '') {
            $existing = pdo()->prepare(
                "SELECT id FROM admin_inbox
                  WHERE dedupe_key = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  ORDER BY id DESC LIMIT 1"
            );
            $existing->execute([$dedupe]);
            if ($existing->fetchColumn()) return null;
        }

        $stmt = pdo()->prepare(
            "INSERT INTO admin_inbox
                (user_id, audience, severity, title, body, link, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user_id, $audience, $severity,
            mb_substr($title, 0, 160),
            $body !== '' ? $body : null,
            $link, $dedupe,
        ]);
        return (int)pdo()->lastInsertId();
    } catch (Throwable $e) {
        error_log('inbox_post failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Roles that should see broadcast rows of a given audience tag.
 * Matches the ACL grant table in auth/acl.php so an operator without
 * the relevant capability doesn't see noise they can't act on.
 */
function inbox_audiences_for_role(string $role): array {
    $map = [
        'super_admin'  => ['any', 'noc', 'billing', 'support'],
        'admin'        => ['any', 'noc', 'billing', 'support'],
        'noc_readonly' => ['any', 'noc'],
        'technician'   => ['any', 'noc'],
        'billing'      => ['any', 'billing'],
        'support'      => ['any', 'support'],
        'viewer'       => ['any'],
    ];
    return $map[$role] ?? ['any'];
}

/**
 * Build the WHERE fragment + arg list for "rows this user should see".
 * Returns [sql_fragment, args]. Always anchors on admin_inbox aliased
 * as `i`; callers wrap it with their own SELECT.
 */
function _inbox_visible_where(array $user): array {
    $uid  = (int)$user['id'];
    $role = (string)($user['role'] ?? '');
    $auds = inbox_audiences_for_role($role);
    // ?,?,? placeholders sized to $auds; positional binding only.
    $audPh = implode(',', array_fill(0, count($auds), '?'));
    $sql = "(i.user_id = ? OR (i.user_id IS NULL AND i.audience IN ($audPh)))";
    $args = array_merge([$uid], $auds);
    return [$sql, $args];
}

function inbox_unread_count(array $user): int {
    try {
        [$where, $args] = _inbox_visible_where($user);
        $sql = "SELECT COUNT(*) FROM admin_inbox i
                 LEFT JOIN admin_inbox_read r
                   ON r.inbox_id = i.id AND r.user_id = ?
                 WHERE $where AND r.inbox_id IS NULL";
        $stmt = pdo()->prepare($sql);
        $stmt->execute(array_merge([(int)$user['id']], $args));
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('inbox_unread_count failed: ' . $e->getMessage());
        return 0;
    }
}

function inbox_recent(array $user, int $limit = 50, bool $only_unread = false): array {
    $limit = max(1, min(500, $limit));
    try {
        [$where, $args] = _inbox_visible_where($user);
        $sql = "SELECT i.*,
                       CASE WHEN r.inbox_id IS NULL THEN 0 ELSE 1 END AS is_read,
                       r.read_at
                  FROM admin_inbox i
             LEFT JOIN admin_inbox_read r
                    ON r.inbox_id = i.id AND r.user_id = ?
                 WHERE $where";
        if ($only_unread) $sql .= " AND r.inbox_id IS NULL";
        $sql .= " ORDER BY i.id DESC LIMIT $limit";
        $stmt = pdo()->prepare($sql);
        $stmt->execute(array_merge([(int)$user['id']], $args));
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('inbox_recent failed: ' . $e->getMessage());
        return [];
    }
}

function inbox_mark_read(int $inbox_id, array $user): bool {
    if ($inbox_id <= 0) return false;
    try {
        [$where, $args] = _inbox_visible_where($user);
        // Confirm visibility before marking read so a malicious id can't
        // poison admin_inbox_read for unrelated rows.
        $check = pdo()->prepare("SELECT 1 FROM admin_inbox i WHERE i.id = ? AND $where LIMIT 1");
        $check->execute(array_merge([$inbox_id], $args));
        if (!$check->fetchColumn()) return false;

        pdo()->prepare(
            "INSERT IGNORE INTO admin_inbox_read (inbox_id, user_id) VALUES (?, ?)"
        )->execute([$inbox_id, (int)$user['id']]);
        return true;
    } catch (Throwable $e) {
        error_log('inbox_mark_read failed: ' . $e->getMessage());
        return false;
    }
}

function inbox_mark_all_read(array $user): int {
    try {
        [$where, $args] = _inbox_visible_where($user);
        $sql = "INSERT IGNORE INTO admin_inbox_read (inbox_id, user_id)
                SELECT i.id, ?
                  FROM admin_inbox i
             LEFT JOIN admin_inbox_read r
                    ON r.inbox_id = i.id AND r.user_id = ?
                 WHERE $where AND r.inbox_id IS NULL";
        $stmt = pdo()->prepare($sql);
        $uid = (int)$user['id'];
        $stmt->execute(array_merge([$uid, $uid], $args));
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('inbox_mark_all_read failed: ' . $e->getMessage());
        return 0;
    }
}
