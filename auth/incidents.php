<?php
/**
 * Service incident helpers (Section 4 of the roadmap).
 *
 * Storage: incidents + incident_updates (see data/schema.sql).
 *
 * The public site banner and /status page query these helpers, so this file
 * MUST be safe to load from non-authenticated pages. It just leans on
 * auth/helpers.php's pdo() — no auth side effects.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const INCIDENT_STATUSES = ['investigating', 'identified', 'monitoring', 'resolved'];
const INCIDENT_STATUS_LABELS = [
    'investigating' => 'Investigating',
    'identified'    => 'Identified',
    'monitoring'    => 'Monitoring',
    'resolved'      => 'Resolved',
];
const INCIDENT_SEVERITIES = ['info', 'minor', 'major', 'critical'];
const INCIDENT_SEVERITY_LABELS = [
    'info'     => 'Info',
    'minor'    => 'Minor',
    'major'    => 'Major',
    'critical' => 'Critical',
];

/* --------------------------------------------------------------- queries */

function incidents_active_top(): ?array {
    try {
        $stmt = pdo()->query(
            "SELECT id, title, status, severity, started_at
             FROM incidents
             WHERE status <> 'resolved'
             ORDER BY (severity = 'critical') DESC,
                      (severity = 'major')    DESC,
                      started_at DESC
             LIMIT 1"
        );
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;   // never break the public site if the DB hiccups
    }
}

function incidents_active_all(): array {
    try {
        $stmt = pdo()->query(
            "SELECT * FROM incidents WHERE status <> 'resolved' ORDER BY started_at DESC"
        );
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function incidents_recent_resolved(int $limit = 20): array {
    $limit = max(1, min(100, $limit));
    try {
        $stmt = pdo()->prepare(
            "SELECT * FROM incidents WHERE status = 'resolved' ORDER BY resolved_at DESC, id DESC LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function incidents_all(?string $status = null): array {
    if ($status && !in_array($status, INCIDENT_STATUSES, true)) $status = null;
    $sql = "SELECT * FROM incidents";
    $args = [];
    if ($status) { $sql .= " WHERE status = ?"; $args[] = $status; }
    $sql .= " ORDER BY (status = 'resolved') ASC, started_at DESC, id DESC";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function incident_find(int $id): ?array {
    $stmt = pdo()->prepare("SELECT * FROM incidents WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function incident_updates_for(int $id): array {
    $stmt = pdo()->prepare(
        "SELECT u.*, usr.name AS author_name, usr.username AS author_username
         FROM incident_updates u
         LEFT JOIN users usr ON usr.id = u.created_by
         WHERE u.incident_id = ?
         ORDER BY u.created_at ASC, u.id ASC"
    );
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------- mutations */

function incident_create(array $data, ?int $created_by = null): int {
    $title    = trim((string)($data['title']    ?? ''));
    $body     = trim((string)($data['body']     ?? ''));
    $affected = trim((string)($data['affected'] ?? ''));
    $severity = (string)($data['severity'] ?? 'minor');
    $status   = (string)($data['status']   ?? 'investigating');
    $started  = (string)($data['started_at'] ?? date('Y-m-d H:i:s'));

    if ($title === '')                                     throw new InvalidArgumentException('Title is required.');
    if ($body  === '')                                     throw new InvalidArgumentException('Initial description is required.');
    if (!in_array($severity, INCIDENT_SEVERITIES, true))   throw new InvalidArgumentException('Unknown severity.');
    if (!in_array($status,   INCIDENT_STATUSES,   true))   throw new InvalidArgumentException('Unknown status.');

    $started_iso = incident_normalise_dt($started);

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (title, body, affected, severity, status, started_at, resolved_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $resolved_at = ($status === 'resolved') ? $started_iso : null;
        $stmt->execute([
            mb_substr($title, 0, 200), $body,
            mb_substr($affected, 0, 255),
            $severity, $status, $started_iso, $resolved_at, $created_by,
        ]);
        $id = (int)$pdo->lastInsertId();

        // Seed timeline with the initial post.
        $pdo->prepare(
            "INSERT INTO incident_updates (incident_id, status, body, created_by, created_at)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$id, $status, $body, $created_by, $started_iso]);

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function incident_update_meta(int $id, array $data): bool {
    $existing = incident_find($id);
    if (!$existing) return false;

    $title    = isset($data['title'])    ? trim((string)$data['title'])    : (string)$existing['title'];
    $body     = isset($data['body'])     ? trim((string)$data['body'])     : (string)$existing['body'];
    $affected = isset($data['affected']) ? trim((string)$data['affected']) : (string)$existing['affected'];
    $severity = isset($data['severity']) ? (string)$data['severity']       : (string)$existing['severity'];
    $started  = isset($data['started_at']) && $data['started_at'] !== ''
                  ? incident_normalise_dt((string)$data['started_at'])
                  : (string)$existing['started_at'];

    if ($title === '')                                   throw new InvalidArgumentException('Title is required.');
    if (!in_array($severity, INCIDENT_SEVERITIES, true)) throw new InvalidArgumentException('Unknown severity.');

    return pdo()->prepare(
        "UPDATE incidents SET title = ?, body = ?, affected = ?, severity = ?, started_at = ? WHERE id = ?"
    )->execute([
        mb_substr($title, 0, 200), $body,
        mb_substr($affected, 0, 255),
        $severity, $started, $id,
    ]);
}

function incident_add_update(int $id, string $status, string $body, ?int $created_by = null): int {
    $body = trim($body);
    if ($body === '')                                  throw new InvalidArgumentException('Update body is required.');
    if (!in_array($status, INCIDENT_STATUSES, true))   throw new InvalidArgumentException('Unknown status.');

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO incident_updates (incident_id, status, body, created_by) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$id, $status, $body, $created_by]);
        $update_id = (int)$pdo->lastInsertId();

        $sql = "UPDATE incidents SET status = ?";
        $args = [$status];
        if ($status === 'resolved') {
            $sql .= ", resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP)";
        } else {
            $sql .= ", resolved_at = NULL";
        }
        $sql .= " WHERE id = ?";
        $args[] = $id;
        $pdo->prepare($sql)->execute($args);

        $pdo->commit();
        return $update_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function incident_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM incidents WHERE id = ?")->execute([$id]);
}

function incident_normalise_dt(string $v): string {
    $ts = strtotime($v) ?: time();
    return date('Y-m-d H:i:s', $ts);
}

function incident_severity_class(string $sev): string {
    return 'sev-' . preg_replace('/[^a-z]/', '', strtolower($sev));
}
