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
        'site_id'   => !empty($data['site_id']) && is_numeric($data['site_id']) ? (int)$data['site_id'] : null,
        'name'      => trim((string)($data['name']    ?? '')),
        'vendor'    => $vendor,
        'model'     => trim((string)($data['model']   ?? '')),
        'role'      => $role,
        'serial'    => trim((string)($data['serial']  ?? '')),
        'mac'       => strtoupper(trim((string)($data['mac'] ?? ''))),
        'mgmt_ip'   => trim((string)($data['mgmt_ip'] ?? '')),
        'mgmt_port' => is_numeric($data['mgmt_port'] ?? null) ? max(1, min(65535, (int)$data['mgmt_port'])) : null,
        'firmware'  => trim((string)($data['firmware'] ?? '')),
        'status'    => $status,
        'notes'     => trim((string)($data['notes']    ?? '')) ?: null,
    ];

    if ($args['name'] === '') {
        throw new InvalidArgumentException('Device name is required.');
    }

    if ($id) {
        pdo()->prepare(
            "UPDATE devices
                SET site_id=?, name=?, vendor=?, model=?, role=?, serial=?, mac=?,
                    mgmt_ip=?, mgmt_port=?, firmware=?, status=?, notes=?
              WHERE id=?"
        )->execute([
            $args['site_id'], $args['name'], $args['vendor'], $args['model'], $args['role'],
            $args['serial'], $args['mac'], $args['mgmt_ip'], $args['mgmt_port'],
            $args['firmware'], $args['status'], $args['notes'], $id,
        ]);
        return $id;
    }

    pdo()->prepare(
        "INSERT INTO devices
            (site_id, name, vendor, model, role, serial, mac, mgmt_ip, mgmt_port,
             firmware, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $args['site_id'], $args['name'], $args['vendor'], $args['model'], $args['role'],
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
 * Most recent device_health rows for a device, newest first. Used by the
 * admin device-detail card once Phase 3 starts populating the table.
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
