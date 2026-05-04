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

/* ---------------------------------------------------- analytics helpers */

const SECTOR_OVERLAP_DISTANCE_KM = 10.0;  // Same-band sectors this close are likely to interfere.

/**
 * Find every other sector whose frequency range overlaps with this one
 * AND whose tower is within SECTOR_OVERLAP_DISTANCE_KM. Useful for the
 * "you're stepping on yourself" warning on /admin/sectors.php and
 * /admin/sector-edit.php.
 *
 * Two sectors overlap on the air if:
 *   • Same band
 *   • |freq_a - freq_b| < (width_a + width_b) / 2
 *   • haversine(tower_a, tower_b) < SECTOR_OVERLAP_DISTANCE_KM
 *
 * Returns rows with the offender sector + its tower + computed
 * distance + freq-window overlap in MHz, sorted by worst-offender
 * first (largest overlap × shortest distance).
 */
function sectors_overlap_check(int $sector_id): array {
    if ($sector_id <= 0) return [];
    require_once __DIR__ . '/sites.php'; // for haversine_km()

    $stmt = pdo()->prepare(
        "SELECT s.*, t.lat AS tower_lat, t.lng AS tower_lng, t.name AS tower_name
           FROM sectors s
           JOIN sites   t ON t.id = s.tower_id
          WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([$sector_id]);
    $self = $stmt->fetch();
    if (!$self || !$self['frequency_mhz'] || !$self['channel_width_mhz']) return [];

    $stmt = pdo()->prepare(
        "SELECT s.*, t.lat AS tower_lat, t.lng AS tower_lng, t.name AS tower_name
           FROM sectors s
           JOIN sites   t ON t.id = s.tower_id
          WHERE s.id <> ?
            AND s.band = ?
            AND s.frequency_mhz IS NOT NULL
            AND s.channel_width_mhz IS NOT NULL"
    );
    $stmt->execute([$sector_id, $self['band']]);

    $f1 = (int)$self['frequency_mhz'];
    $w1 = (int)$self['channel_width_mhz'];
    $hits = [];
    foreach ($stmt->fetchAll() as $r) {
        $f2 = (int)$r['frequency_mhz'];
        $w2 = (int)$r['channel_width_mhz'];
        $sep   = abs($f1 - $f2);
        $half  = ($w1 + $w2) / 2.0;
        $overlap_mhz = $half - $sep;
        if ($overlap_mhz <= 0) continue;

        $km = haversine_km(
            (float)$self['tower_lat'], (float)$self['tower_lng'],
            (float)$r['tower_lat'],    (float)$r['tower_lng']
        );
        if ($km > SECTOR_OVERLAP_DISTANCE_KM) continue;

        $hits[] = [
            'sector_id'    => (int)$r['id'],
            'sector_name'  => (string)$r['name'],
            'tower_id'     => (int)$r['tower_id'],
            'tower_name'   => (string)$r['tower_name'],
            'band'         => (string)$r['band'],
            'frequency_mhz'=> $f2,
            'channel_width_mhz' => $w2,
            'distance_km'  => round($km, 3),
            'overlap_mhz'  => round($overlap_mhz, 1),
            'azimuth_deg'  => $r['azimuth_deg'] !== null ? (int)$r['azimuth_deg'] : null,
        ];
    }
    // Worst offenders first: more MHz overlap and closer = higher priority.
    usort($hits, fn($a, $b) => ($b['overlap_mhz'] / max(0.1, $b['distance_km']))
                            <=> ($a['overlap_mhz'] / max(0.1, $a['distance_km'])));
    return $hits;
}

/**
 * Hourly throughput rollup for a sector over the last $hours.
 * Aggregates link_health_samples joined via wireless_links.sector_id.
 * Returns one row per hour bucket, oldest-first, suitable for an SVG
 * sparkline. Empty hours are present with avg/peak = 0 so the chart
 * doesn't have gaps.
 */
function sector_throughput_history(int $sector_id, int $hours = 24): array {
    if ($sector_id <= 0) return [];
    $hours = max(1, min(168, $hours));

    $stmt = pdo()->prepare(
        "SELECT DATE_FORMAT(lhs.polled_at, '%Y-%m-%d %H:00') AS bucket,
                AVG(COALESCE(lhs.throughput_local_mbps, 0)
                  + COALESCE(lhs.throughput_remote_mbps, 0))   AS avg_mbps,
                MAX(COALESCE(lhs.throughput_local_mbps, 0)
                  + COALESCE(lhs.throughput_remote_mbps, 0))   AS peak_mbps,
                COUNT(*)                                       AS samples
           FROM link_health_samples lhs
           JOIN wireless_links wl ON wl.id = lhs.link_id
          WHERE wl.sector_id = ?
            AND lhs.polled_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
          GROUP BY bucket
          ORDER BY bucket ASC"
    );
    $stmt->execute([$sector_id, $hours]);
    $by_bucket = [];
    foreach ($stmt->fetchAll() as $r) {
        $by_bucket[$r['bucket']] = [
            'bucket'    => $r['bucket'],
            'avg_mbps'  => round((float)$r['avg_mbps'], 2),
            'peak_mbps' => round((float)$r['peak_mbps'], 2),
            'samples'   => (int)$r['samples'],
        ];
    }
    // Fill in empty hours so the sparkline doesn't compress its X axis.
    $out = [];
    $now = strtotime(date('Y-m-d H:00:00'));
    for ($i = $hours - 1; $i >= 0; $i--) {
        $ts = $now - $i * 3600;
        $key = date('Y-m-d H:00', $ts);
        $out[] = $by_bucket[$key] ?? [
            'bucket' => $key, 'avg_mbps' => 0.0, 'peak_mbps' => 0.0, 'samples' => 0,
        ];
    }
    return $out;
}

/**
 * Outage history for a sector — recent rows from the outages table
 * plus aggregate stats (count, total downtime minutes, MTTR). Limits
 * to scope='sector' AND scope_id=$id; tower-level outages aren't
 * counted here even though they affect this sector — that's a
 * different chart.
 */
function sector_outage_history(int $sector_id, int $days = 90, int $list_limit = 20): array {
    if ($sector_id <= 0) return ['rows' => [], 'count' => 0, 'down_minutes' => 0, 'mttr_minutes' => null];
    $days = max(1, min(3650, $days));
    $list_limit = max(1, min(500, $list_limit));

    $stmt = pdo()->prepare(
        "SELECT *,
                TIMESTAMPDIFF(MINUTE, started_at, COALESCE(resolved_at, NOW())) AS minutes
           FROM outages
          WHERE scope = 'sector' AND scope_id = ?
            AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          ORDER BY started_at DESC LIMIT $list_limit"
    );
    $stmt->execute([$sector_id, $days]);
    $rows = $stmt->fetchAll();

    $stmt = pdo()->prepare(
        "SELECT COUNT(*) AS event_count,
                SUM(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(resolved_at, NOW()))) AS down_minutes,
                AVG(CASE WHEN resolved_at IS NOT NULL
                          THEN TIMESTAMPDIFF(MINUTE, started_at, resolved_at)
                          ELSE NULL END) AS mttr_minutes
           FROM outages
          WHERE scope = 'sector' AND scope_id = ?
            AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$sector_id, $days]);
    $agg = $stmt->fetch() ?: [];

    return [
        'rows'         => $rows,
        'count'        => (int)($agg['event_count']  ?? 0),
        'down_minutes' => (int)($agg['down_minutes'] ?? 0),
        'mttr_minutes' => $agg['mttr_minutes'] !== null ? (int)round((float)$agg['mttr_minutes']) : null,
    ];
}

/**
 * Estimate when a sector will hit max_clients given the trailing
 * subscriber-add velocity. Linear projection — counts users whose
 * service_start landed inside the last $window_days, divides by the
 * window to get a per-day rate, then projects forward to max_clients.
 *
 * Returns null when the sector has no max_clients set, no current
 * subscribers, or recent growth ≤ 0 (in which case "fill" is
 * meaningless). Otherwise returns the projection so the UI can show
 * "expected to fill in N days at current rate".
 */
function sector_capacity_forecast(int $sector_id, int $window_days = 90): ?array {
    if ($sector_id <= 0) return null;
    $window_days = max(7, min(365, $window_days));

    $stmt = pdo()->prepare(
        "SELECT (SELECT max_clients FROM sectors WHERE id = ?) AS max_clients,
                COUNT(*)                                                          AS current_count,
                SUM(service_start IS NOT NULL
                    AND service_start >= DATE_SUB(CURDATE(), INTERVAL ? DAY))     AS recent_adds
           FROM users
          WHERE sector_id = ? AND role = 'client'"
    );
    $stmt->execute([$sector_id, $window_days, $sector_id]);
    $r = $stmt->fetch();
    if (!$r) return null;

    $max     = $r['max_clients'] !== null ? (int)$r['max_clients'] : null;
    $current = (int)$r['current_count'];
    $recent  = (int)$r['recent_adds'];
    if ($max === null || $max <= 0) return null;

    $pct = round(($current / $max) * 100, 1);

    $rate_per_day = $recent > 0 ? ($recent / $window_days) : 0.0;
    if ($rate_per_day <= 0) {
        return [
            'max_clients'   => $max,
            'current_count' => $current,
            'pct'           => $pct,
            'recent_adds'   => $recent,
            'window_days'   => $window_days,
            'rate_per_day'  => 0.0,
            'days_to_full'  => null,
        ];
    }
    $remaining     = max(0, $max - $current);
    $days_to_full  = (int)ceil($remaining / $rate_per_day);
    return [
        'max_clients'   => $max,
        'current_count' => $current,
        'pct'           => $pct,
        'recent_adds'   => $recent,
        'window_days'   => $window_days,
        'rate_per_day'  => round($rate_per_day, 3),
        'days_to_full'  => $days_to_full,
    ];
}
