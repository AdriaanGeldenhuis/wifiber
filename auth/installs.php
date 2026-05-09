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

/* Sign-off acceptance thresholds. Values worse than these surface a
   warning on the install workflow page so admins don't rubber-stamp a
   bad install — they can still override. Calibrated for typical PtMP
   5 GHz: -75 dBm / 15 dB SNR is the boundary between "good" and
   "marginal", -80 / 10 is "definitely going to drop in rain". */
const INSTALL_SIGNAL_DBM_OK   = -75;
const INSTALL_SIGNAL_DBM_WARN = -80;
const INSTALL_SNR_DB_OK       = 15;
const INSTALL_SNR_DB_WARN     = 10;

/* Install photo storage. Same pattern as ticket attachments. */
const INSTALL_PHOTO_DIR   = DATA_DIR . '/install-photos';
const INSTALL_PHOTO_MAX   = 8 * 1024 * 1024; // 8 MB — phones produce big JPEGs
const INSTALL_PHOTO_TYPES = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'heic' => 'image/heic',
];

function install_job_normalise(array $r): array {
    foreach (['id', 'customer_id', 'assigned_to', 'completed_by', 'priority', 'signal_dbm', 'snr_db'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        }
    }
    return $r;
}

/* Classify a candidate sign-off reading. Returns 'ok' / 'warn' / 'bad'
   so the UI can colour the form field and the server can audit the
   override decision. */
function install_signal_grade(?int $signal_dbm, ?int $snr_db): string {
    $sigBad  = $signal_dbm !== null && $signal_dbm < INSTALL_SIGNAL_DBM_WARN;
    $snrBad  = $snr_db     !== null && $snr_db     < INSTALL_SNR_DB_WARN;
    $sigWarn = $signal_dbm !== null && $signal_dbm < INSTALL_SIGNAL_DBM_OK;
    $snrWarn = $snr_db     !== null && $snr_db     < INSTALL_SNR_DB_OK;
    if ($sigBad || $snrBad)   return 'bad';
    if ($sigWarn || $snrWarn) return 'warn';
    return 'ok';
}

/* Save an uploaded install photo and return the on-disk path (relative
   to DATA_DIR), empty string on no upload, or throws on failure. */
function install_photo_save(?array $f): string {
    if (!$f || !is_array($f)) return '';
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return '';
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed (error code ' . $err . ').');
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) return '';
    if ($size > INSTALL_PHOTO_MAX) {
        throw new RuntimeException('Photo is too big (max ' . (INSTALL_PHOTO_MAX / 1024 / 1024) . ' MB).');
    }
    $orig = (string)($f['name'] ?? 'photo');
    $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset(INSTALL_PHOTO_TYPES[$ext])) {
        throw new RuntimeException(
            'Photo type ".' . $ext . '" not allowed. Allowed: ' . implode(', ', array_keys(INSTALL_PHOTO_TYPES))
        );
    }
    if (!is_dir(INSTALL_PHOTO_DIR)) @mkdir(INSTALL_PHOTO_DIR, 0755, true);
    if (!is_dir(INSTALL_PHOTO_DIR) || !is_writable(INSTALL_PHOTO_DIR)) {
        throw new RuntimeException('Photo directory is not writable: ' . INSTALL_PHOTO_DIR);
    }
    $rand = bin2hex(random_bytes(8));
    $base = date('Ymd-His') . '-' . $rand . '.' . $ext;
    $dest = INSTALL_PHOTO_DIR . '/' . $base;
    $tmp  = (string)($f['tmp_name'] ?? '');
    $ok   = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$ok) {
        throw new RuntimeException('Could not save photo to ' . $dest);
    }
    // Stored as a path relative to DATA_DIR so deployments that move
    // the data root don't break links.
    return 'install-photos/' . $base;
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

/* Coarse geohash-style clustering for a tech's daily route. Buckets
   jobs by ~5 km grid (0.05° ≈ 5.5 km at the equator) so neighbours
   land in the same bucket; within a bucket they're ordered by lat for
   a roughly-north-to-south drive. Returns
     [bucket_key => ['centroid'=>[lat,lng]|null, 'jobs'=>[…]], …]
   keyed first by 'unplaced' (if any), then by descending job count. */
function install_jobs_clustered(array $jobs, float $bucket_deg = 0.05): array {
    $clusters = ['unplaced' => ['centroid' => null, 'jobs' => []]];
    foreach ($jobs as $j) {
        $lat = $j['customer_lat'] ?? null;
        $lng = $j['customer_lng'] ?? null;
        if ($lat === null || $lng === null) {
            $clusters['unplaced']['jobs'][] = $j;
            continue;
        }
        $bl = round((float)$lat / $bucket_deg) * $bucket_deg;
        $bg = round((float)$lng / $bucket_deg) * $bucket_deg;
        $key = sprintf('%.3f_%.3f', $bl, $bg);
        if (!isset($clusters[$key])) {
            $clusters[$key] = ['centroid' => ['lat' => $bl, 'lng' => $bg], 'jobs' => []];
        }
        $clusters[$key]['jobs'][] = $j;
    }
    if (empty($clusters['unplaced']['jobs'])) unset($clusters['unplaced']);
    foreach ($clusters as &$c) {
        usort($c['jobs'], fn($a, $b) =>
            ((float)($b['customer_lat'] ?? 0)) <=> ((float)($a['customer_lat'] ?? 0))
        );
    }
    unset($c);
    uasort($clusters, fn($a, $b) => count($b['jobs']) - count($a['jobs']));
    return $clusters;
}

/* Build a Google Maps directions URL with up to 10 placed jobs as
   waypoints (Google's free limit). Returns null if there's nothing
   to route. */
function install_jobs_route_url(array $jobs, ?string $origin = null): ?string {
    $points = [];
    foreach ($jobs as $j) {
        if ($j['customer_lat'] !== null && $j['customer_lng'] !== null) {
            $points[] = (float)$j['customer_lat'] . ',' . (float)$j['customer_lng'];
        }
        if (count($points) >= 10) break;
    }
    if (!$points) return null;
    $params = ['api' => '1', 'travelmode' => 'driving'];
    $params['destination'] = array_pop($points);
    if ($origin)            $params['origin']     = $origin;
    elseif ($points)        $params['origin']     = array_shift($points);
    if ($points)            $params['waypoints']  = implode('|', $points);
    return 'https://www.google.com/maps/dir/?' . http_build_query($params);
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

    /* Notify the customer if a date has been picked. We deliberately
       skip notification on the auto-created shell jobs (no scheduled_at
       yet) so customers don't get an SMS the moment they sign up. */
    if (!empty($args['scheduled_at']) && is_file(__DIR__ . '/notifications.php')) {
        require_once __DIR__ . '/notifications.php';
        try {
            $u = find_user_by_id($customer_id);
            if ($u) {
                notify_send($u, 'install.scheduled', [
                    'scheduled_at' => $args['scheduled_at'],
                ]);
            }
        } catch (Throwable $e) {
            error_log('install.scheduled notify failed: ' . $e->getMessage());
        }
    }

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
    $sig    = isset($extra['signal_dbm']) && $extra['signal_dbm'] !== '' ? (int)$extra['signal_dbm'] : null;
    $snr    = isset($extra['snr_db'])     && $extra['snr_db']     !== '' ? (int)$extra['snr_db']     : null;
    $mac    = isset($extra['cpe_mac'])    ? strtoupper(trim((string)$extra['cpe_mac']))    : null;
    $ser    = isset($extra['cpe_serial']) ? trim((string)$extra['cpe_serial'])             : null;
    $photo  = isset($extra['photo_path']) ? (string)$extra['photo_path']                   : null;
    $by     = (int)($_SESSION['user_id'] ?? 0) ?: null;

    /* Pull the most recent live readings if the form left them blank.
       The alignment endpoint stamps signal_dbm/snr_db on every poll, so
       this catches the common case of "tech signed off without retyping". */
    if ($sig === null || $snr === null) {
        $row = pdo()->prepare("SELECT signal_dbm, snr_db FROM install_jobs WHERE id = ?");
        $row->execute([$id]);
        $live = $row->fetch();
        if ($live) {
            if ($sig === null && $live['signal_dbm'] !== null) $sig = (int)$live['signal_dbm'];
            if ($snr === null && $live['snr_db']     !== null) $snr = (int)$live['snr_db'];
        }
    }
    $grade = install_signal_grade($sig, $snr);

    $stmt = pdo()->prepare(
        "UPDATE install_jobs
            SET status='completed',
                completed_at = COALESCE(completed_at, NOW()),
                completed_by = ?,
                signal_dbm   = COALESCE(?, signal_dbm),
                snr_db       = COALESCE(?, snr_db),
                cpe_mac      = COALESCE(NULLIF(?, ''), cpe_mac),
                cpe_serial   = COALESCE(NULLIF(?, ''), cpe_serial),
                photo_path   = COALESCE(NULLIF(?, ''), photo_path)
          WHERE id = ?"
    );
    $stmt->execute([$by, $sig, $snr, $mac, $ser, $photo, $id]);
    audit_log('install_job.complete', [
        'target_type' => 'install_job', 'target_id' => $id,
        'meta' => array_filter([
            'signal_dbm' => $sig,
            'snr_db'     => $snr,
            'cpe_mac'    => $mac,
            'cpe_serial' => $ser,
            'photo'      => $photo,
            'grade'      => $grade,
        ], fn($v) => $v !== null && $v !== ''),
    ]);

    /* Welcome-aboard SMS / email. Reuses the notifications fan-out, so
       the customer gets it on whichever channels they accept. */
    if (is_file(__DIR__ . '/notifications.php')) {
        require_once __DIR__ . '/notifications.php';
        try {
            $cstmt = pdo()->prepare("SELECT customer_id FROM install_jobs WHERE id = ?");
            $cstmt->execute([$id]);
            $cid = (int)$cstmt->fetchColumn();
            if ($cid > 0) {
                $u = find_user_by_id($cid);
                if ($u) notify_send($u, 'install.completed', []);
            }
        } catch (Throwable $e) {
            error_log('install.completed notify failed: ' . $e->getMessage());
        }
    }
}

/* Live-alignment sample writer. Called from /admin/align.php on every
   poll so an admin watching /admin/install-view.php sees the dish
   improving in real time. We only stamp the open job (pending or
   in_progress) — completed / cancelled jobs are immutable. Failures
   here must never break the alignment endpoint, so the caller wraps
   this in try/catch. */
function install_job_record_alignment_sample(int $customer_id, ?int $signal_dbm, ?int $snr_db): ?int {
    if ($customer_id <= 0) return null;
    $stmt = pdo()->prepare(
        "SELECT id FROM install_jobs
          WHERE customer_id = ? AND status IN ('pending','in_progress')
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$customer_id]);
    $job_id = (int)$stmt->fetchColumn();
    if ($job_id <= 0) return null;

    pdo()->prepare(
        "UPDATE install_jobs
            SET last_alignment_at = NOW(),
                signal_dbm        = COALESCE(?, signal_dbm),
                snr_db            = COALESCE(?, snr_db)
          WHERE id = ?"
    )->execute([$signal_dbm, $snr_db, $job_id]);

    return $job_id;
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
