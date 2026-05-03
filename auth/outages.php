<?php
/**
 * Outage helpers and auto-detector.
 *
 * The outages table holds one row per detected fault, scoped to a
 * sector (AP-device offline) for now. Tower-level rollup and customer
 * notifications come later.
 *
 * Two callers:
 *   bin/detect-outages.php  — cron, calls outage_detect() every minute
 *   admin/outages.php       — UI for browsing + manual close
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const OUTAGE_SCOPES   = ['device', 'sector', 'tower', 'core'];
const OUTAGE_STATUSES = ['active', 'resolved'];

function outage_normalise(array $r): array {
    $r['id']             = (int)$r['id'];
    $r['scope_id']       = $r['scope_id'] !== null ? (int)$r['scope_id'] : null;
    $r['affected_count'] = (int)$r['affected_count'];
    return $r;
}

/**
 * Most recent active outage matching a scope tuple, or null.
 */
function outage_active(string $scope, ?int $scope_id): ?array {
    if (!in_array($scope, OUTAGE_SCOPES, true)) return null;
    $sql  = "SELECT * FROM outages
              WHERE scope = ? AND status = 'active'
                AND " . ($scope_id === null ? "scope_id IS NULL" : "scope_id = ?")
          . " ORDER BY started_at DESC LIMIT 1";
    $args = $scope_id === null ? [$scope] : [$scope, $scope_id];
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch();
    return $row ? outage_normalise($row) : null;
}

function outage_create(string $scope, ?int $scope_id, string $label, int $affected, ?string $cause = null): int {
    if (!in_array($scope, OUTAGE_SCOPES, true)) {
        throw new InvalidArgumentException("Unknown outage scope: $scope");
    }
    // Suppress notifications when a maintenance window covers this scope
    // (planned pole-swap, scheduled freq move, etc.).
    $suppress = false;
    if ($scope_id) {
        $mw = pdo()->prepare(
            "SELECT id, notify_customers FROM maintenance_windows
              WHERE scope = ? AND scope_id = ?
                AND starts_at <= NOW() AND ends_at >= NOW()
              LIMIT 1"
        );
        $mw->execute([$scope, $scope_id]);
        $row = $mw->fetch();
        if ($row && empty($row['notify_customers'])) {
            $suppress = true;
        }
    }
    pdo()->prepare(
        "INSERT INTO outages (scope, scope_id, scope_label, status, affected_count, cause, started_at, suppressed)
         VALUES (?, ?, ?, 'active', ?, ?, NOW(), ?)"
    )->execute([$scope, $scope_id, $label, $affected, $cause, $suppress ? 1 : 0]);
    $id = (int)pdo()->lastInsertId();

    if ($scope === 'sector' && $scope_id && !$suppress) {
        $sent = outage_notify_affected($id, $scope_id, $label, $cause);
        audit_log('outage.notify', [
            'target_type' => 'outage', 'target_id' => $id,
            'meta' => $sent,
        ]);
    }
    // Webhooks fire regardless of suppression — third-party
    // integrations (Slack, PagerDuty) want to know.
    if (function_exists('webhook_fire')) {
        webhook_fire('outage.opened', [
            'outage_id' => $id, 'scope' => $scope, 'scope_id' => $scope_id,
            'label' => $label, 'affected_count' => $affected, 'cause' => $cause,
            'suppressed' => $suppress,
        ]);
    } elseif (is_file(__DIR__ . '/webhooks.php')) {
        require_once __DIR__ . '/webhooks.php';
        webhook_fire('outage.opened', [
            'outage_id' => $id, 'scope' => $scope, 'scope_id' => $scope_id,
            'label' => $label, 'affected_count' => $affected, 'cause' => $cause,
            'suppressed' => $suppress,
        ]);
    }
    if ($suppress) {
        audit_log('outage.suppressed', [
            'target_type' => 'outage', 'target_id' => $id,
            'meta' => ['reason' => 'maintenance_window_active'],
        ]);
    }

    return $id;
}

/**
 * Email every active customer attached to a sector that just went down.
 * Returns ['sent' => N, 'failed' => M, 'skipped' => K].
 *
 * Customers without an email are silently skipped. We don't dedupe
 * across nearby outages — if a tower has three sectors and they all
 * blink, three emails go out. That's acceptable for now; a smarter
 * fan-out (debounce per customer per 30 min) is item 14 follow-up.
 */
function outage_notify_affected(int $outage_id, int $sector_id, string $label, ?string $cause): array {
    require_once __DIR__ . '/notifications.php';
    $stmt = pdo()->prepare(
        "SELECT id, email, name, username, phone, phone_e164, notify_prefs
           FROM users
          WHERE role = 'client'
            AND status = 'active'
            AND sector_id = ?"
    );
    $stmt->execute([$sector_id]);
    $rows = $stmt->fetchAll();

    $sent = 0; $failed = 0; $skipped = 0;
    foreach ($rows as $u) {
        $r = notify_send($u, 'outage.opened', [
            'scope_label' => $label,
            'cause'       => $cause,
            'outage_id'   => $outage_id,
        ]);
        $sent    += $r['sent'];
        $failed  += $r['failed'];
        $skipped += $r['skipped'];
    }
    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'total' => count($rows)];
}

function outage_resolve(int $outage_id, ?string $note = null): bool {
    if ($outage_id <= 0) return false;
    // Snapshot before the update so we can email the affected customers.
    $before = pdo()->prepare("SELECT * FROM outages WHERE id = ? LIMIT 1");
    $before->execute([$outage_id]);
    $row = $before->fetch();

    if ($note !== null && $note !== '') {
        pdo()->prepare(
            "UPDATE outages
                SET status = 'resolved', resolved_at = NOW(),
                    notes = TRIM(BOTH '\n' FROM CONCAT_WS('\n', notes, ?))
              WHERE id = ? AND status = 'active'"
        )->execute([$note, $outage_id]);
    } else {
        pdo()->prepare(
            "UPDATE outages SET status = 'resolved', resolved_at = NOW()
              WHERE id = ? AND status = 'active'"
        )->execute([$outage_id]);
    }

    if ($row && $row['status'] === 'active' && $row['scope'] === 'sector' && $row['scope_id']) {
        $sent = outage_notify_resolved((int)$outage_id, (int)$row['scope_id'], (string)$row['scope_label']);
        audit_log('outage.notify_resolved', [
            'target_type' => 'outage', 'target_id' => $outage_id,
            'meta' => $sent,
        ]);
    }
    if (is_file(__DIR__ . '/webhooks.php')) {
        require_once __DIR__ . '/webhooks.php';
        webhook_fire('outage.resolved', [
            'outage_id' => $outage_id,
            'scope' => $row['scope'] ?? null,
            'scope_id' => $row['scope_id'] ?? null,
            'label' => $row['scope_label'] ?? '',
            'note' => $note,
        ]);
    }
    return true;
}

/**
 * Mirror of outage_notify_affected for the all-clear message.
 */
function outage_notify_resolved(int $outage_id, int $sector_id, string $label): array {
    require_once __DIR__ . '/notifications.php';
    $stmt = pdo()->prepare(
        "SELECT id, email, name, username, phone, phone_e164, notify_prefs
           FROM users
          WHERE role = 'client'
            AND status = 'active'
            AND sector_id = ?"
    );
    $stmt->execute([$sector_id]);
    $rows = $stmt->fetchAll();

    $sent = 0; $failed = 0; $skipped = 0;
    foreach ($rows as $u) {
        $r = notify_send($u, 'outage.resolved', [
            'scope_label' => $label,
            'outage_id'   => $outage_id,
        ]);
        $sent    += $r['sent'];
        $failed  += $r['failed'];
        $skipped += $r['skipped'];
    }
    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'total' => count($rows)];
}

function outage_update_affected_count(int $outage_id, int $count): void {
    if ($outage_id <= 0) return;
    pdo()->prepare("UPDATE outages SET affected_count = ? WHERE id = ?")
         ->execute([$count, $outage_id]);
}

/**
 * List outages with optional filters.
 * Filter keys: status, scope, since (DATETIME string).
 */
function outages_all(?array $filters = null, int $limit = 200): array {
    $sql   = "SELECT * FROM outages";
    $where = [];
    $args  = [];

    $f = $filters ?? [];
    if (!empty($f['status']) && in_array($f['status'], OUTAGE_STATUSES, true)) {
        $where[] = 'status = ?';   $args[] = $f['status'];
    }
    if (!empty($f['scope']) && in_array($f['scope'], OUTAGE_SCOPES, true)) {
        $where[] = 'scope = ?';    $args[] = $f['scope'];
    }
    if (!empty($f['since'])) {
        $where[] = 'started_at >= ?';
        $args[]  = $f['since'];
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY started_at DESC';
    $limit = max(1, min(2000, $limit));
    $sql  .= ' LIMIT ' . $limit;

    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = outage_normalise($r);
    return $rows;
}

function outage_active_count(): int {
    return (int)pdo()->query("SELECT COUNT(*) FROM outages WHERE status = 'active'")->fetchColumn();
}

/* ------------------------------------------------------------ detector */

/**
 * Sweep sectors and create / resolve outages based on AP device status.
 *
 * Rule: a sector with an assigned ap_device_id whose device.status is
 * 'offline' is an active outage. When the device flips back to
 * 'online' (or is set to 'retired'), the outage resolves. We don't
 * react to 'unknown' — that's a "haven't polled yet" state and would
 * cause flap.
 *
 * The Phase 3 status-flip debounce already smooths out single-packet
 * blips, so the detector itself doesn't need its own hysteresis.
 *
 * Returns ['opened' => N, 'closed' => M, 'updated' => K].
 */
function outage_detect(): array {
    $opened = $closed = $updated = 0;

    // All sectors that have an AP device linked. LEFT JOIN tower so we
    // can build a useful label even if the tower row was deleted.
    $rows = pdo()->query(
        "SELECT s.id          AS sector_id,
                s.name        AS sector_name,
                t.name        AS tower_name,
                d.id          AS ap_device_id,
                d.status      AS ap_status
           FROM sectors s
           INNER JOIN devices d ON d.id = s.ap_device_id
           LEFT JOIN sites    t ON t.id = s.tower_id"
    )->fetchAll();

    foreach ($rows as $r) {
        $sector_id   = (int)$r['sector_id'];
        $is_offline  = $r['ap_status'] === 'offline';
        $existing    = outage_active('sector', $sector_id);

        if ($is_offline && !$existing) {
            $count = outage_count_affected_for_sector($sector_id);
            $label = $r['sector_name'];
            if (!empty($r['tower_name'])) $label .= ' · ' . $r['tower_name'];
            outage_create('sector', $sector_id, $label, $count, 'AP offline');
            $opened++;
        } elseif (!$is_offline && $existing && $r['ap_status'] === 'online') {
            outage_resolve((int)$existing['id'], 'AP back online');
            $closed++;
        } elseif ($is_offline && $existing) {
            // Refresh affected_count in case customers were re-assigned
            // since the outage opened.
            $count = outage_count_affected_for_sector($sector_id);
            if ($count !== (int)$existing['affected_count']) {
                outage_update_affected_count((int)$existing['id'], $count);
                $updated++;
            }
        }
    }

    return ['opened' => $opened, 'closed' => $closed, 'updated' => $updated];
}

function outage_count_affected_for_sector(int $sector_id): int {
    if ($sector_id <= 0) return 0;
    $stmt = pdo()->prepare(
        "SELECT COUNT(*) FROM users
          WHERE sector_id = ? AND role = 'client' AND status = 'active'"
    );
    $stmt->execute([$sector_id]);
    return (int)$stmt->fetchColumn();
}
