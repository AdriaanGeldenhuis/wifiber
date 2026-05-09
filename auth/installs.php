<?php
/**
 * Install jobs — small workflow layer that lives alongside the user
 * record. Lets ops schedule installs, assign technicians, and record
 * sign-off without touching `users.status` directly. The helpers below
 * are deliberately thin — they read/write `install_jobs` and emit one
 * audit_log row per state change so the install timeline is provable.
 *
 * Used by:
 *   admin/installs.php      list + create
 *   admin/install-view.php  per-job workflow page
 *   admin/_layout.php       nav badge (install_jobs_count_open)
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const INSTALL_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

function install_job_normalise(array $r): array {
    foreach (['id', 'customer_id', 'assigned_to', 'completed_by', 'priority', 'signal_dbm', 'snr_db'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        }
    }
    return $r;
}

/* List install jobs joined with the customer record so the list page
   can render names + addresses without an N+1. Defaults to "open"
   (pending + in_progress) ordered by scheduled date — that's the
   installer's working queue. */
function install_jobs_all(?array $filters = null): array {
    $f = $filters ?? [];

    $sql = "SELECT j.*,
                   u.name           AS customer_name,
                   u.surname        AS customer_surname,
                   u.username       AS customer_username,
                   u.account_no     AS customer_account_no,
                   u.phone          AS customer_phone,
                   u.address        AS customer_address,
                   u.lat            AS customer_lat,
                   u.lng            AS customer_lng,
                   u.sector_id      AS customer_sector_id,
                   u.equipment_mac  AS customer_equipment_mac,
                   t.name           AS assigned_name,
                   t.username       AS assigned_username
              FROM install_jobs j
              JOIN users u  ON u.id = j.customer_id
              LEFT JOIN users t ON t.id = j.assigned_to";

    $where = [];
    $args  = [];

    if (array_key_exists('status', $f) && $f['status'] !== '') {
        if ($f['status'] === 'open') {
            $where[] = "j.status IN ('pending','in_progress')";
        } elseif (in_array($f['status'], INSTALL_STATUSES, true)) {
            $where[] = 'j.status = ?';
            $args[]  = $f['status'];
        }
    } else {
        // Default queue view — open jobs only.
        $where[] = "j.status IN ('pending','in_progress')";
    }
    if (!empty($f['assigned_to'])) {
        $where[] = 'j.assigned_to = ?';
        $args[]  = (int)$f['assigned_to'];
    }
    if (!empty($f['customer_id'])) {
        $where[] = 'j.customer_id = ?';
        $args[]  = (int)$f['customer_id'];
    }
    if (!empty($f['scheduled_from'])) {
        $where[] = 'j.scheduled_at >= ?';
        $args[]  = (string)$f['scheduled_from'];
    }
    if (!empty($f['scheduled_to'])) {
        $where[] = 'j.scheduled_at <= ?';
        $args[]  = (string)$f['scheduled_to'];
    }
    if (!empty($f['search'])) {
        $like = '%' . $f['search'] . '%';
        $where[] = '(u.name LIKE ? OR u.surname LIKE ? OR u.username LIKE ? OR u.account_no LIKE ? OR u.address LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like);
    }

    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY j.priority ASC,
                       (j.scheduled_at IS NULL),
                       j.scheduled_at ASC,
                       j.id DESC";

    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = install_job_normalise($r);
    return $rows;
}

function install_job_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare(
        "SELECT j.*,
                u.name AS customer_name, u.surname AS customer_surname,
                u.username AS customer_username, u.account_no AS customer_account_no,
                u.phone AS customer_phone, u.address AS customer_address,
                u.lat AS customer_lat, u.lng AS customer_lng,
                u.sector_id AS customer_sector_id,
                u.equipment_mac AS customer_equipment_mac,
                t.name AS assigned_name, t.username AS assigned_username
           FROM install_jobs j
           JOIN users u  ON u.id = j.customer_id
           LEFT JOIN users t ON t.id = j.assigned_to
          WHERE j.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? install_job_normalise($row) : null;
}

/* History for a customer's install timeline (newest first). */
function install_jobs_for_customer(int $customer_id): array {
    if ($customer_id <= 0) return [];
    $stmt = pdo()->prepare(
        "SELECT * FROM install_jobs WHERE customer_id = ? ORDER BY id DESC"
    );
    $stmt->execute([$customer_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = install_job_normalise($r);
    return $rows;
}

function install_jobs_count_open(): int {
    try {
        return (int)pdo()->query(
            "SELECT COUNT(*) FROM install_jobs WHERE status IN ('pending','in_progress')"
        )->fetchColumn();
    } catch (Throwable $e) {
        // Table might not exist yet on a freshly cloned dev box.
        return 0;
    }
}

/* Insert / update. Pass $id null for insert, an integer for update. */
function install_job_save(array $data, ?int $id = null): int {
    $customer_id = (int)($data['customer_id'] ?? 0);
    if ($customer_id <= 0) throw new InvalidArgumentException('customer_id required');

    $args = [
        'customer_id'  => $customer_id,
        'assigned_to'  => !empty($data['assigned_to'])  ? (int)$data['assigned_to']  : null,
        'scheduled_at' => !empty($data['scheduled_at']) ? (string)$data['scheduled_at'] : null,
        'priority'     => max(1, min(5, (int)($data['priority'] ?? 3))),
        'notes'        => (string)($data['notes'] ?? ''),
        'cpe_mac'      => strtoupper(trim((string)($data['cpe_mac']    ?? ''))),
        'cpe_serial'   => trim((string)($data['cpe_serial'] ?? '')),
        'cpe_model'    => trim((string)($data['cpe_model']  ?? '')),
    ];

    if ($id) {
        $stmt = pdo()->prepare(
            "UPDATE install_jobs
                SET assigned_to=?, scheduled_at=?, priority=?, notes=?,
                    cpe_mac=?, cpe_serial=?, cpe_model=?
              WHERE id=?"
        );
        $stmt->execute([
            $args['assigned_to'], $args['scheduled_at'], $args['priority'], $args['notes'],
            $args['cpe_mac'], $args['cpe_serial'], $args['cpe_model'],
            $id,
        ]);
        audit_log('install_job.update', [
            'target_type' => 'install_job', 'target_id' => $id,
            'meta' => ['customer_id' => $customer_id],
        ]);
        return $id;
    }

    $stmt = pdo()->prepare(
        "INSERT INTO install_jobs
            (customer_id, assigned_to, scheduled_at, priority, notes,
             cpe_mac, cpe_serial, cpe_model, status)
         VALUES (?,?,?,?,?,?,?,?, 'pending')"
    );
    $stmt->execute([
        $args['customer_id'], $args['assigned_to'], $args['scheduled_at'],
        $args['priority'], $args['notes'],
        $args['cpe_mac'], $args['cpe_serial'], $args['cpe_model'],
    ]);
    $new_id = (int)pdo()->lastInsertId();
    audit_log('install_job.create', [
        'target_type' => 'install_job', 'target_id' => $new_id,
        'meta' => ['customer_id' => $customer_id],
    ]);
    return $new_id;
}

function install_job_start(int $id): void {
    $stmt = pdo()->prepare(
        "UPDATE install_jobs
            SET status='in_progress',
                started_at = COALESCE(started_at, NOW())
          WHERE id = ? AND status IN ('pending','in_progress')"
    );
    $stmt->execute([$id]);
    audit_log('install_job.start', [
        'target_type' => 'install_job', 'target_id' => $id,
    ]);
}

/* Sign-off. Records as-installed signal levels alongside the audit
   entry — the operator's "the install actually worked" receipt. We
   deliberately don't touch users.status here; reactivation /
   provisioning is a separate concern. */
function install_job_complete(int $id, array $extra = []): void {
    $sig = isset($extra['signal_dbm']) && $extra['signal_dbm'] !== '' ? (int)$extra['signal_dbm'] : null;
    $snr = isset($extra['snr_db'])     && $extra['snr_db']     !== '' ? (int)$extra['snr_db']     : null;
    $mac = isset($extra['cpe_mac'])    ? strtoupper(trim((string)$extra['cpe_mac']))    : null;
    $ser = isset($extra['cpe_serial']) ? trim((string)$extra['cpe_serial'])             : null;
    $by  = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $stmt = pdo()->prepare(
        "UPDATE install_jobs
            SET status='completed',
                completed_at = COALESCE(completed_at, NOW()),
                completed_by = ?,
                signal_dbm   = COALESCE(?, signal_dbm),
                snr_db       = COALESCE(?, snr_db),
                cpe_mac      = COALESCE(NULLIF(?, ''), cpe_mac),
                cpe_serial   = COALESCE(NULLIF(?, ''), cpe_serial)
          WHERE id = ?"
    );
    $stmt->execute([$by, $sig, $snr, $mac, $ser, $id]);
    audit_log('install_job.complete', [
        'target_type' => 'install_job', 'target_id' => $id,
        'meta' => array_filter([
            'signal_dbm' => $sig,
            'snr_db'     => $snr,
            'cpe_mac'    => $mac,
            'cpe_serial' => $ser,
        ], fn($v) => $v !== null && $v !== ''),
    ]);
}

function install_job_cancel(int $id, string $reason): void {
    $stmt = pdo()->prepare(
        "UPDATE install_jobs
            SET status='cancelled',
                cancelled_at = COALESCE(cancelled_at, NOW()),
                cancelled_reason = ?
          WHERE id = ?"
    );
    $stmt->execute([$reason, $id]);
    audit_log('install_job.cancel', [
        'target_type' => 'install_job', 'target_id' => $id,
        'meta' => ['reason' => $reason],
    ]);
}
