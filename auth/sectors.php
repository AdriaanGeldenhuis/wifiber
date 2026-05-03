<?php
/**
 * Sector helpers — one row per AP-on-a-tower configuration.
 *
 * A sector knows where it's pointed (azimuth + beamwidth), what band /
 * frequency / channel width it's broadcasting on, and which AP device
 * drives it. Live noise / utilisation / client count come from the
 * Phase 3 polling worker and live in rf_samples (Phase 8) — never on
 * this row.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const SECTOR_BANDS = ['2.4GHz', '5GHz', '6GHz', '60GHz', 'other'];

function sector_normalise(array $r): array {
    $r['id']                = (int)$r['id'];
    $r['tower_id']          = (int)$r['tower_id'];
    $r['ap_device_id']      = $r['ap_device_id']      !== null ? (int)$r['ap_device_id']      : null;
    $r['azimuth_deg']       = $r['azimuth_deg']       !== null ? (int)$r['azimuth_deg']       : null;
    $r['beamwidth_deg']     = $r['beamwidth_deg']     !== null ? (int)$r['beamwidth_deg']     : null;
    $r['frequency_mhz']     = $r['frequency_mhz']     !== null ? (int)$r['frequency_mhz']     : null;
    $r['channel_width_mhz'] = $r['channel_width_mhz'] !== null ? (int)$r['channel_width_mhz'] : null;
    $r['tx_power_dbm']      = $r['tx_power_dbm']      !== null ? (int)$r['tx_power_dbm']      : null;
    $r['max_clients']       = $r['max_clients']       !== null ? (int)$r['max_clients']       : null;
    if (isset($r['customer_count'])) $r['customer_count'] = (int)$r['customer_count'];
    return $r;
}

/**
 * List sectors with their tower and AP-device names joined in.
 *
 * Filter keys (all optional):
 *   tower_id int
 *   band     string  — SECTOR_BANDS value
 *   search   string  — name LIKE match
 */
function sectors_all(?array $filters = null): array {
    $sql = "SELECT s.*,
                   t.name   AS tower_name,
                   d.name   AS ap_device_name,
                   d.vendor AS ap_device_vendor,
                   d.model  AS ap_device_model,
                   d.status AS ap_device_status,
                   d.last_seen_at AS ap_last_seen_at,
                   (SELECT COUNT(*) FROM users u
                     WHERE u.sector_id = s.id AND u.role = 'client') AS customer_count
              FROM sectors s
              LEFT JOIN sites   t ON t.id = s.tower_id
              LEFT JOIN devices d ON d.id = s.ap_device_id";
    $where = [];
    $args  = [];

    $f = $filters ?? [];
    if (!empty($f['tower_id'])) { $where[] = 's.tower_id = ?'; $args[] = (int)$f['tower_id']; }
    if (!empty($f['band']) && in_array($f['band'], SECTOR_BANDS, true)) {
        $where[] = 's.band = ?'; $args[] = $f['band'];
    }
    if (!empty($f['search'])) {
        $where[] = 's.name LIKE ?';
        $args[]  = '%' . $f['search'] . '%';
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY t.name ASC, s.name ASC';

    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = sector_normalise($r);
    return $rows;
}

function sector_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM sectors WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? sector_normalise($row) : null;
}

function sectors_for_tower(int $tower_id): array {
    if ($tower_id <= 0) return [];
    $stmt = pdo()->prepare("SELECT * FROM sectors WHERE tower_id = ? ORDER BY azimuth_deg ASC, name ASC");
    $stmt->execute([$tower_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = sector_normalise($r);
    return $rows;
}

function sector_save(array $data, ?int $id = null): int {
    $band = in_array($data['band'] ?? '', SECTOR_BANDS, true) ? $data['band'] : '5GHz';

    $args = [
        'tower_id'          => (int)($data['tower_id'] ?? 0),
        'ap_device_id'      => !empty($data['ap_device_id']) && is_numeric($data['ap_device_id']) ? (int)$data['ap_device_id'] : null,
        'name'              => trim((string)($data['name'] ?? '')),
        'azimuth_deg'       => is_numeric($data['azimuth_deg']       ?? null) ? max(0,    min(359,  (int)$data['azimuth_deg']))       : null,
        'beamwidth_deg'     => is_numeric($data['beamwidth_deg']     ?? null) ? max(1,    min(360,  (int)$data['beamwidth_deg']))     : null,
        'band'              => $band,
        'frequency_mhz'     => is_numeric($data['frequency_mhz']     ?? null) ? max(0,    min(65535,(int)$data['frequency_mhz']))     : null,
        'channel_width_mhz' => is_numeric($data['channel_width_mhz'] ?? null) ? max(0,    min(65535,(int)$data['channel_width_mhz'])) : null,
        'tx_power_dbm'      => is_numeric($data['tx_power_dbm']      ?? null) ? max(-128, min(127,  (int)$data['tx_power_dbm']))      : null,
        'max_clients'       => is_numeric($data['max_clients']       ?? null) ? max(0,    min(65535,(int)$data['max_clients']))       : null,
        'notes'             => trim((string)($data['notes'] ?? '')) ?: null,
    ];

    if ($args['name'] === '') {
        throw new InvalidArgumentException('Sector name is required.');
    }
    if ($args['tower_id'] <= 0) {
        throw new InvalidArgumentException('Pick a tower for this sector.');
    }
    // The FK will reject this too, but a friendly error beats a 500.
    $tower = pdo()->prepare("SELECT id, type FROM sites WHERE id = ? LIMIT 1");
    $tower->execute([$args['tower_id']]);
    $row = $tower->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Tower not found.');
    }

    if ($id) {
        pdo()->prepare(
            "UPDATE sectors
                SET tower_id=?, ap_device_id=?, name=?, azimuth_deg=?, beamwidth_deg=?,
                    band=?, frequency_mhz=?, channel_width_mhz=?, tx_power_dbm=?,
                    max_clients=?, notes=?
              WHERE id=?"
        )->execute([
            $args['tower_id'], $args['ap_device_id'], $args['name'], $args['azimuth_deg'], $args['beamwidth_deg'],
            $args['band'], $args['frequency_mhz'], $args['channel_width_mhz'], $args['tx_power_dbm'],
            $args['max_clients'], $args['notes'], $id,
        ]);
        return $id;
    }

    pdo()->prepare(
        "INSERT INTO sectors
            (tower_id, ap_device_id, name, azimuth_deg, beamwidth_deg,
             band, frequency_mhz, channel_width_mhz, tx_power_dbm,
             max_clients, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $args['tower_id'], $args['ap_device_id'], $args['name'], $args['azimuth_deg'], $args['beamwidth_deg'],
        $args['band'], $args['frequency_mhz'], $args['channel_width_mhz'], $args['tx_power_dbm'],
        $args['max_clients'], $args['notes'],
    ]);
    return (int)pdo()->lastInsertId();
}

function sector_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM sectors WHERE id = ?")->execute([$id]);
}
