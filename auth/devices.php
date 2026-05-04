<?php
/**
 * Device helpers — APs, CPEs, routers, switches, backhaul radios and PoP
 * gear. Manual entry for now; Phase 3 will add a polling worker that
 * writes to the device_health table on a schedule.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const DEVICE_VENDORS  = ['mikrotik', 'ubiquiti', 'cambium', 'mimosa', 'other'];
const DEVICE_ROLES    = ['ap', 'cpe', 'router', 'switch', 'backhaul', 'ups', 'other'];
const DEVICE_STATUSES = ['online', 'offline', 'unknown', 'retired'];

function device_normalise(array $r): array {
    $r['id']           = (int)$r['id'];
    $r['site_id']      = $r['site_id']      !== null ? (int)$r['site_id']      : null;
    $r['customer_id']  = isset($r['customer_id']) && $r['customer_id'] !== null ? (int)$r['customer_id'] : null;
    $r['mgmt_port']    = $r['mgmt_port']    !== null ? (int)$r['mgmt_port']    : null;
    return $r;
}

/**
 * List devices with the parent site name joined in for the admin grid.
 *
 * Filters keys (all optional):
 *   site_id  int    — exact match
 *   role     string — DEVICE_ROLES value
 *   status   string — DEVICE_STATUSES value
 *   vendor   string — DEVICE_VENDORS value
 *   search   string — name / mac / serial / mgmt_ip LIKE match
 */
function devices_all(?array $filters = null): array {
    $sql = "SELECT d.*, s.name AS site_name
              FROM devices d
              LEFT JOIN sites s ON s.id = d.site_id";
    $where = [];
    $args  = [];

    $f = $filters ?? [];
    if (!empty($f['site_id'])) { $where[] = 'd.site_id = ?'; $args[] = (int)$f['site_id']; }
    if (!empty($f['role']) && in_array($f['role'], DEVICE_ROLES, true))   { $where[] = 'd.role = ?';   $args[] = $f['role']; }
    if (!empty($f['status']) && in_array($f['status'], DEVICE_STATUSES, true)) { $where[] = 'd.status = ?'; $args[] = $f['status']; }
    if (!empty($f['vendor']) && in_array($f['vendor'], DEVICE_VENDORS, true))  { $where[] = 'd.vendor = ?'; $args[] = $f['vendor']; }
    if (!empty($f['search'])) {
        $like = '%' . $f['search'] . '%';
        $where[] = '(d.name LIKE ? OR d.mac LIKE ? OR d.serial LIKE ? OR d.mgmt_ip LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY d.name ASC';

    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = device_normalise($r);
    return $rows;
}

function device_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM devices WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? device_normalise($row) : null;
}

function device_save(array $data, ?int $id = null): int {
    $vendor = in_array($data['vendor'] ?? '', DEVICE_VENDORS, true)   ? $data['vendor'] : 'other';
    $role   = in_array($data['role']   ?? '', DEVICE_ROLES,   true)   ? $data['role']   : 'other';
    $status = in_array($data['status'] ?? '', DEVICE_STATUSES, true)  ? $data['status'] : 'unknown';

    $args = [
        'site_id'     => !empty($data['site_id'])     && is_numeric($data['site_id'])     ? (int)$data['site_id']     : null,
        'customer_id' => !empty($data['customer_id']) && is_numeric($data['customer_id']) ? (int)$data['customer_id'] : null,
        'name'        => trim((string)($data['name']    ?? '')),
        'vendor'      => $vendor,
        'model'       => trim((string)($data['model']   ?? '')),
        'role'        => $role,
        'serial'      => trim((string)($data['serial']  ?? '')),
        'mac'         => mac_canonical((string)($data['mac'] ?? '')),
        'mgmt_ip'     => trim((string)($data['mgmt_ip'] ?? '')),
        'mgmt_port'   => is_numeric($data['mgmt_port'] ?? null) ? max(1, min(65535, (int)$data['mgmt_port'])) : null,
        'firmware'    => trim((string)($data['firmware'] ?? '')),
        'status'      => $status,
        'notes'       => trim((string)($data['notes']    ?? '')) ?: null,
    ];

    if ($args['name'] === '') {
        throw new InvalidArgumentException('Device name is required.');
    }

    // Block duplicate MACs across the inventory. We canonicalise to
    // AA:BB:CC:DD:EE:FF before compare so paste-from-airOS variants
    // (aa-bb-cc, aabbccddeeff, lowercase) all collide correctly.
    if ($args['mac'] !== '') {
        $clash = device_mac_conflict($args['mac'], $id);
        if ($clash) {
            throw new InvalidArgumentException(
                'MAC ' . $args['mac'] . ' is already used by device "' . $clash['name'] . '" (#' . (int)$clash['id'] . ').'
            );
        }
    }

    if ($id) {
        pdo()->prepare(
            "UPDATE devices
                SET site_id=?, customer_id=?, name=?, vendor=?, model=?, role=?, serial=?, mac=?,
                    mgmt_ip=?, mgmt_port=?, firmware=?, status=?, notes=?
              WHERE id=?"
        )->execute([
            $args['site_id'], $args['customer_id'], $args['name'], $args['vendor'], $args['model'], $args['role'],
            $args['serial'], $args['mac'], $args['mgmt_ip'], $args['mgmt_port'],
            $args['firmware'], $args['status'], $args['notes'], $id,
        ]);
        return $id;
    }

    pdo()->prepare(
        "INSERT INTO devices
            (site_id, customer_id, name, vendor, model, role, serial, mac, mgmt_ip, mgmt_port,
             firmware, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $args['site_id'], $args['customer_id'], $args['name'], $args['vendor'], $args['model'], $args['role'],
        $args['serial'], $args['mac'], $args['mgmt_ip'], $args['mgmt_port'],
        $args['firmware'], $args['status'], $args['notes'],
    ]);
    return (int)pdo()->lastInsertId();
}

function device_delete(int $id): bool {
    // ON DELETE CASCADE on device_health takes care of the history rows.
    return pdo()->prepare("DELETE FROM devices WHERE id = ?")->execute([$id]);
}

/**
 * Devices linked to a particular customer (CPE/router/switch installed
 * at their premises). Used by the client editor's "Linked devices"
 * panel.
 */
function devices_for_customer(int $user_id): array {
    if ($user_id <= 0) return [];
    $stmt = pdo()->prepare(
        "SELECT d.*, s.name AS site_name
           FROM devices d
           LEFT JOIN sites s ON s.id = d.site_id
          WHERE d.customer_id = ?
       ORDER BY d.role ASC, d.name ASC"
    );
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = device_normalise($r);
    return $rows;
}

/**
 * Toggle the customer link on a device — used by attach / detach
 * actions on the client editor without rewriting every field.
 */
function device_set_customer(int $device_id, ?int $user_id): bool {
    return pdo()->prepare(
        "UPDATE devices SET customer_id = ? WHERE id = ?"
    )->execute([$user_id ?: null, $device_id]);
}

/**
 * Most recent device_health rows for a device, newest first.
 */
function device_recent_health(int $device_id, int $limit = 100): array {
    if ($device_id <= 0) return [];
    $limit = max(1, min(2000, $limit));
    $stmt = pdo()->prepare(
        "SELECT * FROM device_health
          WHERE device_id = ?
          ORDER BY polled_at DESC, id DESC
          LIMIT $limit"
    );
    $stmt->execute([$device_id]);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------- polling */
/*
 * Phase 3 polling — ICMP only. Vendor-specific protocols (RouterOS API,
 * AirOS SSH, SNMP) come later; right now we just establish reachability,
 * RTT, and the online/offline flip.
 */

/**
 * Shell out to /bin/ping. Returns ['ok'=>bool, 'rtt_ms'=>?float, 'output'=>string].
 *
 * Used by both the bin/poll-devices.php cron worker (which orchestrates
 * its own parallel ping batches via proc_open) and the per-device
 * "Ping now" button in /admin/devices.php.
 */
function icmp_ping(string $ip, int $timeout_s = 2): array {
    $ip = trim($ip);
    if ($ip === '') {
        return ['ok' => false, 'rtt_ms' => null, 'output' => 'no IP set'];
    }
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'rtt_ms' => null, 'output' => 'proc_open is disabled on this host'];
    }
    $timeout_s = max(1, min(10, $timeout_s));
    // proc_open shells through /bin/sh -c on Unix, so PATH lookup
    // handles /bin/ping vs /usr/bin/ping vs $PREFIX/bin/ping for us.
    $cmd  = sprintf('ping -c 1 -W %d %s 2>&1', $timeout_s, escapeshellarg($ip));
    $pipes = [];
    $h = @proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($h)) {
        return ['ok' => false, 'rtt_ms' => null, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($h);
    $output = $stdout . $stderr;

    $rtt = null;
    if ($code === 0 && preg_match('/time=([0-9.]+)\s*ms/', $output, $m)) {
        $rtt = (float)$m[1];
    }
    return ['ok' => $code === 0, 'rtt_ms' => $rtt, 'output' => $output];
}

/**
 * Insert a device_health row, then re-evaluate devices.status based on
 * the most recent N samples. Reachable polls also bump devices.last_seen_at.
 *
 * Status flip is debounced: we only switch online↔offline when the last
 * STATUS_FLIP_WINDOW samples agree, so a single dropped packet doesn't
 * page the NOC.
 */
const STATUS_FLIP_WINDOW = 2;

function device_record_poll_result(int $device_id, bool $reachable, ?float $rtt_ms): void {
    if ($device_id <= 0) return;
    $status = $reachable ? 'online' : 'offline';

    pdo()->prepare(
        "INSERT INTO device_health (device_id, polled_at, status, rtt_ms)
         VALUES (?, NOW(), ?, ?)"
    )->execute([$device_id, $status, $rtt_ms]);

    if ($reachable) {
        pdo()->prepare("UPDATE devices SET last_seen_at = NOW() WHERE id = ?")
            ->execute([$device_id]);
    }

    $derived = device_status_from_history($device_id, STATUS_FLIP_WINDOW);
    if ($derived !== null) {
        // Don't ever clobber 'retired' — that's an admin-set state.
        pdo()->prepare("UPDATE devices SET status = ? WHERE id = ? AND status <> 'retired'")
            ->execute([$derived, $device_id]);
    }
}

/**
 * Look at the last $window samples for a device. If they all agree on
 * online or offline, return that. Otherwise return null (don't flip).
 */
function device_status_from_history(int $device_id, int $window = 2): ?string {
    if ($device_id <= 0) return null;
    $window = max(1, min(20, $window));
    $stmt = pdo()->prepare(
        "SELECT status FROM device_health
          WHERE device_id = ?
          ORDER BY id DESC
          LIMIT $window"
    );
    $stmt->execute([$device_id]);
    $rows = $stmt->fetchAll();
    if (count($rows) < $window) return null;
    $first = $rows[0]['status'];
    if ($first !== 'online' && $first !== 'offline') return null;
    foreach ($rows as $r) if ($r['status'] !== $first) return null;
    return $first;
}

/**
 * Drop device_health rows older than $retention_days. Returns rows deleted.
 * Called at the end of each cron poll cycle so the table doesn't grow
 * unbounded. Phase 8 will replace this with hourly aggregation.
 */
function device_health_cleanup(int $retention_days = 30): int {
    $retention_days = max(1, min(3650, $retention_days));
    $stmt = pdo()->prepare(
        "DELETE FROM device_health WHERE polled_at < (NOW() - INTERVAL ? DAY)"
    );
    $stmt->execute([$retention_days]);
    return $stmt->rowCount();
}

/* ------------------------------------------------------- MAC + firmware */

/**
 * Canonical MAC form is AA:BB:CC:DD:EE:FF — uppercase, colon-separated.
 * Accepts the usual handful of paste variants (no separator, hyphenated,
 * Cisco dotted-quad). Returns '' if the input doesn't look like a MAC at
 * all so the caller can decide whether that's a soft skip or hard fail.
 */
function mac_canonical(string $raw): string {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '';
    if (strlen($hex) !== 12) return '';
    $hex = strtoupper($hex);
    return implode(':', str_split($hex, 2));
}

/**
 * Find an existing device that already owns a MAC. Used by device_save()
 * to throw before insert, and by /admin/devices.php for a nightly
 * "duplicate MAC" report. Excludes $exclude_id so editing your own row
 * doesn't trip on itself, and ignores blank MACs (lots of pre-staged
 * devices have no MAC yet).
 */
function device_mac_conflict(string $mac, ?int $exclude_id = null): ?array {
    $mac = mac_canonical($mac);
    if ($mac === '') return null;
    $sql  = "SELECT id, name, vendor, model, mgmt_ip, site_id
               FROM devices
              WHERE mac = ?";
    $args = [$mac];
    if ($exclude_id) { $sql .= " AND id <> ?"; $args[] = $exclude_id; }
    $sql .= " LIMIT 1";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch();
    return $row ? device_normalise($row) : null;
}

/**
 * Sweep the entire inventory for duplicated MACs (canonicalising as we
 * go so cosmetic differences don't hide collisions). Returns groups of
 * 2+ devices keyed by canonical MAC. Surfaced on /admin/devices.php so
 * the operator can fix legacy data that pre-dates the save-time check.
 */
function device_mac_conflicts_all(): array {
    $rows = pdo()->query("SELECT id, name, mac, vendor, model, mgmt_ip FROM devices WHERE mac <> ''")->fetchAll();
    $by_mac = [];
    foreach ($rows as $r) {
        $c = mac_canonical((string)$r['mac']);
        if ($c === '') continue;
        $by_mac[$c][] = device_normalise($r);
    }
    return array_filter($by_mac, fn($g) => count($g) > 1);
}

/**
 * Match a device's vendor / model / firmware against the curated
 * firmware_eol table. Returns the worst applicable row (or null if
 * nothing matches), with eol_date / eos_date / severity / notes for
 * the badge. Severity ordering: critical > warn > info.
 */
function device_firmware_eol_status(string $vendor, string $model, string $firmware): ?array {
    if ($firmware === '') return null;
    if (!in_array($vendor, DEVICE_VENDORS, true)) return null;
    $stmt = pdo()->prepare(
        "SELECT * FROM firmware_eol
          WHERE vendor = ?
            AND ? LIKE model_match
            AND ? LIKE version_match
          ORDER BY FIELD(severity,'critical','warn','info'), eol_date ASC
          LIMIT 1"
    );
    $stmt->execute([$vendor, $model ?: '%', $firmware]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['id']       = (int)$row['id'];
    $row['eol_date'] = $row['eol_date'] ?: null;
    $row['eos_date'] = $row['eos_date'] ?: null;
    return $row;
}

/* ----------------------------------------------------- config snapshots */

/**
 * Insert a running-config snapshot for a device. Skips the write when
 * the new body's sha256 matches the most recent snapshot — keeps the
 * table small and means "did this change?" is just a row-count query.
 * Returns the new device_configs.id, or 0 if it was a no-op.
 */
function device_config_save(int $device_id, string $vendor, string $body, string $captured_via = 'cron', ?int $taken_by = null, string $notes = ''): int {
    if ($device_id <= 0) throw new InvalidArgumentException('device_id required');
    if (!in_array($vendor, DEVICE_VENDORS, true)) $vendor = 'other';
    $sha = hash('sha256', $body);

    $stmt = pdo()->prepare(
        "SELECT config_sha256 FROM device_configs
          WHERE device_id = ? ORDER BY captured_at DESC LIMIT 1"
    );
    $stmt->execute([$device_id]);
    $prev = $stmt->fetchColumn();
    if ($prev === $sha) return 0;

    pdo()->prepare(
        "INSERT INTO device_configs
            (device_id, captured_via, vendor, config_sha256, config_body, size_bytes, taken_by, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $device_id, in_array($captured_via, ['cron','manual','pre-change','post-change'], true) ? $captured_via : 'cron',
        $vendor, $sha, $body, strlen($body), $taken_by, mb_substr($notes, 0, 255),
    ]);
    return (int)pdo()->lastInsertId();
}

function device_configs_for(int $device_id, int $limit = 20): array {
    if ($device_id <= 0) return [];
    $limit = max(1, min(500, $limit));
    $stmt = pdo()->prepare(
        "SELECT id, device_id, captured_at, captured_via, vendor, config_sha256,
                size_bytes, taken_by, notes
           FROM device_configs WHERE device_id = ?
          ORDER BY captured_at DESC, id DESC LIMIT $limit"
    );
    $stmt->execute([$device_id]);
    return $stmt->fetchAll();
}

function device_config_find(int $config_id): ?array {
    if ($config_id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM device_configs WHERE id = ? LIMIT 1");
    $stmt->execute([$config_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
