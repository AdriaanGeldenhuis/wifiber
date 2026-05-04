<?php
/**
 * Support-ticket helpers (Section 2 of the roadmap).
 *
 * Storage: tickets + ticket_messages tables (see data/schema.sql).
 * Attachments: data/ticket-attachments/<random>.<ext>, served only via
 * authenticated PHP endpoints (account/attachment.php, admin/attachment.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const TICKET_ATTACH_DIR    = DATA_DIR . '/ticket-attachments';
const TICKET_ATTACH_MAX    = 5 * 1024 * 1024; // 5 MB
const TICKET_ATTACH_TYPES  = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'txt'  => 'text/plain',
    'log'  => 'text/plain',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

const TICKET_STATUSES = ['open', 'in_progress', 'closed'];
const TICKET_STATUS_LABELS = [
    'open'        => 'Open',
    'in_progress' => 'In progress',
    'closed'      => 'Closed',
];

/* --------------------------------------------------------------- queries */

function tickets_for_user(int $user_id): array {
    $stmt = pdo()->prepare(
        "SELECT t.*, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS message_count
         FROM tickets t
         WHERE t.user_id = ?
         ORDER BY t.updated_at DESC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function tickets_all(?string $status_filter = null): array {
    $sql = "SELECT t.*, u.username, u.name AS client_name, u.email AS client_email,
                   (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS message_count
            FROM tickets t
            LEFT JOIN users u ON u.id = t.user_id";
    $args = [];
    if ($status_filter && in_array($status_filter, TICKET_STATUSES, true)) {
        $sql .= " WHERE t.status = ?";
        $args[] = $status_filter;
    }
    $sql .= " ORDER BY (t.status = 'closed') ASC, t.updated_at DESC";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function ticket_find(int $id): ?array {
    $stmt = pdo()->prepare(
        "SELECT t.*, u.username, u.name AS client_name, u.email AS client_email
         FROM tickets t
         LEFT JOIN users u ON u.id = t.user_id
         WHERE t.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function ticket_messages(int $ticket_id): array {
    $stmt = pdo()->prepare(
        "SELECT m.*, u.name AS author_name, u.username AS author_username
         FROM ticket_messages m
         LEFT JOIN users u ON u.id = m.author_id
         WHERE m.ticket_id = ?
         ORDER BY m.created_at ASC, m.id ASC"
    );
    $stmt->execute([$ticket_id]);
    return $stmt->fetchAll();
}

function ticket_message_find(int $message_id): ?array {
    $stmt = pdo()->prepare(
        "SELECT m.*, t.user_id AS ticket_user_id, t.subject AS ticket_subject
         FROM ticket_messages m
         INNER JOIN tickets t ON t.id = m.ticket_id
         WHERE m.id = ?"
    );
    $stmt->execute([$message_id]);
    return $stmt->fetch() ?: null;
}

/* --------------------------------------------------------- mutations */

function ticket_create(int $user_id, string $subject, string $body, ?array $uploaded_file = null): array {
    $subject = trim($subject);
    $body    = trim($body);
    if ($subject === '' || $body === '') {
        throw new InvalidArgumentException('Subject and message are both required.');
    }
    if (mb_strlen($subject) > 200) {
        throw new InvalidArgumentException('Subject is too long (max 200 characters).');
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, status) VALUES (?, ?, 'open')");
        $stmt->execute([$user_id, $subject]);
        $ticket_id = (int)$pdo->lastInsertId();

        $message_id = ticket_message_insert($ticket_id, $user_id, 'client', $body, $uploaded_file);

        $pdo->commit();
        return ['ticket_id' => $ticket_id, 'message_id' => $message_id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ticket_reply(int $ticket_id, int $author_id, string $author_role, string $body, ?array $uploaded_file = null): int {
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Message cannot be empty.');
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $message_id = ticket_message_insert($ticket_id, $author_id, $author_role, $body, $uploaded_file);

        // Auto-status: admin reply on an 'open' ticket → 'in_progress'.
        // Client reply on a 'closed' ticket → reopen to 'open'.
        $cur = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
        $cur->execute([$ticket_id]);
        $status = (string)$cur->fetchColumn();

        $new_status = null;
        if ($author_role === 'admin' && $status === 'open')   $new_status = 'in_progress';
        if ($author_role === 'client' && $status === 'closed') $new_status = 'open';
        if ($new_status) {
            $upd = $pdo->prepare("UPDATE tickets SET status = ?, closed_at = NULL WHERE id = ?");
            $upd->execute([$new_status, $ticket_id]);
        } else {
            // Bump updated_at by touching the row.
            $pdo->prepare("UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$ticket_id]);
        }

        $pdo->commit();
        return $message_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ticket_set_status(int $ticket_id, string $status): bool {
    if (!in_array($status, TICKET_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown status.');
    }
    $sql = $status === 'closed'
        ? "UPDATE tickets SET status = ?, closed_at = CURRENT_TIMESTAMP WHERE id = ?"
        : "UPDATE tickets SET status = ?, closed_at = NULL WHERE id = ?";
    return pdo()->prepare($sql)->execute([$status, $ticket_id]);
}

/**
 * System-driven ticket — used by background workers (link health, cable
 * SNR, etc.) that need to surface a problem without a customer asking.
 * Inserts the ticket against the named customer, with the first message
 * authored by author_id=NULL, role='admin', label='system'.
 */
function ticket_create_system(int $customer_id, string $subject, string $body): int {
    if ($customer_id <= 0) throw new InvalidArgumentException('customer_id required');
    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, status) VALUES (?, ?, 'open')");
        $stmt->execute([$customer_id, mb_substr($subject, 0, 200)]);
        $ticket_id = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO ticket_messages
                (ticket_id, author_id, author_role, author_label, body)
             VALUES (?, NULL, 'admin', 'system', ?)"
        )->execute([$ticket_id, $body]);
        $pdo->commit();
        return $ticket_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ticket_message_insert(int $ticket_id, ?int $author_id, string $author_role, string $body, ?array $uploaded_file): int {
    if (!in_array($author_role, ['admin', 'client'], true)) {
        throw new InvalidArgumentException('author_role must be admin or client.');
    }

    $author_label = '';
    if ($author_id) {
        $u = find_user_by_id($author_id);
        if ($u) $author_label = (string)($u['name'] ?: $u['username']);
    }

    $att = ticket_attachment_save($uploaded_file);

    $stmt = pdo()->prepare(
        "INSERT INTO ticket_messages
            (ticket_id, author_id, author_role, author_label, body,
             attachment_path, attachment_name, attachment_size)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $ticket_id, $author_id, $author_role, $author_label, $body,
        $att['path'] ?? null, $att['name'] ?? null, $att['size'] ?? null,
    ]);
    return (int)pdo()->lastInsertId();
}

/* ----------------------------------------------------------- attachments */

function ticket_attachment_save(?array $f): array {
    if (!$f || !is_array($f)) return [];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [];
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed: ' . ticket_upload_error_msg($err));
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) return [];
    if ($size > TICKET_ATTACH_MAX) {
        throw new RuntimeException('Attachment is too big (max 5 MB).');
    }
    $orig = (string)($f['name'] ?? 'file');
    $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset(TICKET_ATTACH_TYPES[$ext])) {
        throw new RuntimeException('Attachment type ".' . $ext . '" is not allowed. Allowed: ' . implode(', ', array_keys(TICKET_ATTACH_TYPES)));
    }
    if (!is_dir(TICKET_ATTACH_DIR)) @mkdir(TICKET_ATTACH_DIR, 0755, true);
    if (!is_dir(TICKET_ATTACH_DIR) || !is_writable(TICKET_ATTACH_DIR)) {
        throw new RuntimeException('Attachment directory is not writable: ' . TICKET_ATTACH_DIR);
    }
    $rand = bin2hex(random_bytes(8));
    $base = date('Ymd-His') . '-' . $rand . '.' . $ext;
    $dest = TICKET_ATTACH_DIR . '/' . $base;

    $tmp = (string)($f['tmp_name'] ?? '');
    $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$ok) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    @chmod($dest, 0644);

    return [
        'path' => $base,                         // relative to TICKET_ATTACH_DIR
        'name' => mb_substr($orig, 0, 255),
        'size' => $size,
    ];
}

function ticket_attachment_full_path(string $relative): ?string {
    if ($relative === '' || strpos($relative, '/') !== false || strpos($relative, '\\') !== false || strpos($relative, '..') !== false) {
        return null;
    }
    $full = TICKET_ATTACH_DIR . '/' . $relative;
    return is_file($full) ? $full : null;
}

function ticket_attachment_mime(string $relative): string {
    $ext = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
    return TICKET_ATTACH_TYPES[$ext] ?? 'application/octet-stream';
}

function ticket_attachment_stream(array $message): void {
    $rel = (string)($message['attachment_path'] ?? '');
    $full = ticket_attachment_full_path($rel);
    if (!$full) {
        http_response_code(404);
        die('Attachment not found.');
    }
    $name = (string)($message['attachment_name'] ?? basename($rel));
    header('Content-Type: ' . ticket_attachment_mime($rel));
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

function ticket_upload_error_msg(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'file is bigger than the server allows';
        case UPLOAD_ERR_PARTIAL:   return 'upload was interrupted';
        case UPLOAD_ERR_NO_TMP_DIR: return 'server has no temp directory';
        case UPLOAD_ERR_CANT_WRITE: return 'server could not write the file';
        case UPLOAD_ERR_EXTENSION:  return 'a PHP extension blocked the upload';
        default: return 'unknown error (' . $code . ')';
    }
}

/* ---------------------------------------------------------------- email */

function ticket_notify_admin(int $ticket_id, int $message_id): array {
    $t = ticket_find($ticket_id);
    $m = ticket_message_find($message_id);
    if (!$t || !$m) return ['ok' => false, 'reason' => 'ticket or message missing'];

    $site      = load_site_settings();
    $site_name = $site['name']         ?? 'WiFIBER';
    $support   = $site['email_support'] ?? '';
    if ($support === '' || !filter_var($support, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'no support email configured'];
    }

    $base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
    $url  = rtrim($base, '/') . '/admin/tickets.php?id=' . (int)$t['id'];

    $client_label = $t['client_name'] ?: $t['username'] ?: ('user #' . $t['user_id']);
    $body  = "New message on ticket #{$t['id']} — {$t['subject']}\n";
    $body .= "From: {$client_label} (" . ($t['client_email'] ?: 'no email') . ")\n";
    $body .= "Status: " . (TICKET_STATUS_LABELS[$t['status']] ?? $t['status']) . "\n\n";
    $body .= rtrim($m['body']) . "\n\n";
    if (!empty($m['attachment_name'])) {
        $body .= "Attachment: {$m['attachment_name']} (" . number_format(((int)$m['attachment_size']) / 1024, 1) . " KB)\n\n";
    }
    $body .= "Open in admin portal: {$url}\n";

    $headers = "From: {$site_name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . "Reply-To: " . ($t['client_email'] ?: $support) . "\r\n"
             . "X-Mailer: WiFIBER-Tickets\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($support, "[Ticket #{$t['id']}] " . $t['subject'], $body, $headers);
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}

function ticket_notify_client(int $ticket_id, int $message_id): array {
    $t = ticket_find($ticket_id);
    $m = ticket_message_find($message_id);
    if (!$t || !$m) return ['ok' => false, 'reason' => 'ticket or message missing'];
    $email = (string)($t['client_email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'client has no email'];
    }

    $site      = load_site_settings();
    $site_name = $site['name']         ?? 'WiFIBER';
    $support   = $site['email_support'] ?? 'support@wifiber.co.za';
    $base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
    $url  = rtrim($base, '/') . '/account/tickets.php?id=' . (int)$t['id'];

    $name = $t['client_name'] ?: $t['username'] ?: 'there';
    $body  = "Hi {$name},\n\n";
    $body .= "There's a new reply on your support ticket #{$t['id']} — {$t['subject']}.\n\n";
    $body .= rtrim($m['body']) . "\n\n";
    if (!empty($m['attachment_name'])) {
        $body .= "Attachment: {$m['attachment_name']}\n\n";
    }
    $body .= "View the full thread: {$url}\n\n";
    $body .= "— The {$site_name} team\n";

    $headers = "From: {$site_name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . "Reply-To: {$support}\r\n"
             . "X-Mailer: WiFIBER-Tickets\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($email, "[Ticket #{$t['id']}] " . $t['subject'], $body, $headers);
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}
