<?php
/**
 * Append-only timestamped notes log for a client record.
 *
 * Replaces the single `users.notes` textarea on the client editor with
 * a stream of authored, timestamped entries (Splynx / Halo style). The
 * legacy column is kept for back-compat — anything written into it
 * before this migration is shown on the editor as a "Legacy notes"
 * read-only block.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function client_notes_for(int $user_id): array {
    $stmt = pdo()->prepare(
        "SELECT n.*, a.name AS author_name, a.username AS author_username
           FROM client_notes n
           LEFT JOIN users a ON a.id = n.author_id
          WHERE n.user_id = ?
       ORDER BY n.is_pinned DESC, n.created_at DESC, n.id DESC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function client_note_create(int $user_id, ?int $author_id, string $body, bool $pinned = false): int {
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Note body is empty.');
    }
    $stmt = pdo()->prepare(
        "INSERT INTO client_notes (user_id, author_id, body, is_pinned) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$user_id, $author_id ?: null, $body, $pinned ? 1 : 0]);
    return (int)pdo()->lastInsertId();
}

function client_note_delete(int $note_id, int $user_id): bool {
    $stmt = pdo()->prepare("DELETE FROM client_notes WHERE id = ? AND user_id = ?");
    return $stmt->execute([$note_id, $user_id]);
}

function client_note_toggle_pin(int $note_id, int $user_id): bool {
    $stmt = pdo()->prepare(
        "UPDATE client_notes SET is_pinned = 1 - is_pinned WHERE id = ? AND user_id = ?"
    );
    return $stmt->execute([$note_id, $user_id]);
}
