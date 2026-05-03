<?php
/**
 * Network map helpers — sites (towers / APs / PTP endpoints / PoPs),
 * links between sites, and a small Nominatim geocoding proxy.
 */

require_once __DIR__ . '/helpers.php';

const SITE_TYPES = ['tower', 'ap', 'ptp_endpoint', 'pop', 'other'];
const LINK_TYPES = ['ptp', 'ptmp', 'fiber', 'backhaul'];

function site_normalise(array $r): array {
    $r['id']                = (int)$r['id'];
    $r['parent_id']         = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
    $r['lat']               = (float)$r['lat'];
    $r['lng']               = (float)$r['lng'];
    $r['height_m']          = $r['height_m']          !== null ? (float)$r['height_m']          : null;
    $r['coverage_radius_m'] = $r['coverage_radius_m'] !== null ? (int)  $r['coverage_radius_m'] : null;
    $r['is_active']         = !empty($r['is_active']);
    return $r;
}

function sites_all(bool $active_only = false): array {
    $sql = "SELECT * FROM sites";
    if ($active_only) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY name ASC";
    $rows = pdo()->query($sql)->fetchAll();
    foreach ($rows as &$r) $r = site_normalise($r);
    return $rows;
}

function site_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM sites WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? site_normalise($row) : null;
}

function site_save(array $data, ?int $id = null): int {
    $type = in_array($data['type'] ?? '', SITE_TYPES, true) ? $data['type'] : 'tower';
    $args = [
        'parent_id'         => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
        'type'              => $type,
        'name'              => trim((string)($data['name'] ?? '')),
        'lat'               => is_numeric($data['lat'] ?? null) ? (float)$data['lat'] : null,
        'lng'               => is_numeric($data['lng'] ?? null) ? (float)$data['lng'] : null,
        'height_m'          => is_numeric($data['height_m'] ?? null) ? (float)$data['height_m'] : null,
        'coverage_radius_m' => is_numeric($data['coverage_radius_m'] ?? null) ? max(0, (int)$data['coverage_radius_m']) : null,
        'color'             => trim((string)($data['color'] ?? '')) ?: null,
        'notes'             => trim((string)($data['notes'] ?? '')) ?: null,
        'is_active'         => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($args['name'] === '') throw new InvalidArgumentException('Site name is required.');
    if ($args['lat'] === null || $args['lng'] === null) {
        throw new InvalidArgumentException('Latitude and longitude are required.');
    }

    if ($id) {
        $stmt = pdo()->prepare(
            "UPDATE sites
                SET parent_id=?, type=?, name=?, lat=?, lng=?, height_m=?, coverage_radius_m=?,
                    color=?, notes=?, is_active=?
              WHERE id=?"
        );
        $stmt->execute([
            $args['parent_id'], $args['type'], $args['name'], $args['lat'], $args['lng'],
            $args['height_m'], $args['coverage_radius_m'], $args['color'], $args['notes'],
            $args['is_active'], $id,
        ]);
        return $id;
    }

    $stmt = pdo()->prepare(
        "INSERT INTO sites
            (parent_id, type, name, lat, lng, height_m, coverage_radius_m, color, notes, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $args['parent_id'], $args['type'], $args['name'], $args['lat'], $args['lng'],
        $args['height_m'], $args['coverage_radius_m'], $args['color'], $args['notes'],
        $args['is_active'],
    ]);
    return (int)pdo()->lastInsertId();
}

function site_move(int $id, float $lat, float $lng): bool {
    $stmt = pdo()->prepare("UPDATE sites SET lat=?, lng=? WHERE id=?");
    return $stmt->execute([$lat, $lng, $id]);
}

function site_delete(int $id): bool {
    pdo()->prepare("DELETE FROM site_links WHERE from_site_id = ? OR to_site_id = ?")->execute([$id, $id]);
    pdo()->prepare("UPDATE users SET site_id = NULL WHERE site_id = ?")->execute([$id]);
    return pdo()->prepare("DELETE FROM sites WHERE id = ?")->execute([$id]);
}

/* ---------------------------------------------------------------- links */

function site_links_all(): array {
    $rows = pdo()->query("SELECT * FROM site_links ORDER BY id ASC")->fetchAll();
    foreach ($rows as &$r) {
        $r['id']            = (int)$r['id'];
        $r['from_site_id']  = (int)$r['from_site_id'];
        $r['to_site_id']    = (int)$r['to_site_id'];
        $r['capacity_mbps'] = $r['capacity_mbps'] !== null ? (float)$r['capacity_mbps'] : null;
    }
    return $rows;
}

function site_link_save(array $data, ?int $id = null): int {
    $type = in_array($data['type'] ?? '', LINK_TYPES, true) ? $data['type'] : 'ptp';
    $from = (int)($data['from_site_id'] ?? 0);
    $to   = (int)($data['to_site_id']   ?? 0);
    if ($from <= 0 || $to <= 0)  throw new InvalidArgumentException('Pick two sites for the link.');
    if ($from === $to)           throw new InvalidArgumentException('A link must connect two different sites.');
    if (!site_find($from) || !site_find($to)) throw new InvalidArgumentException('One of the sites no longer exists.');

    $args = [
        'from_site_id'  => $from,
        'to_site_id'    => $to,
        'type'          => $type,
        'label'         => trim((string)($data['label'] ?? '')),
        'capacity_mbps' => is_numeric($data['capacity_mbps'] ?? null) ? (float)$data['capacity_mbps'] : null,
        'frequency'     => trim((string)($data['frequency'] ?? '')) ?: null,
        'color'         => trim((string)($data['color']     ?? '')) ?: null,
        'notes'         => trim((string)($data['notes']     ?? '')) ?: null,
    ];
    if ($id) {
        pdo()->prepare(
            "UPDATE site_links
                SET from_site_id=?, to_site_id=?, type=?, label=?, capacity_mbps=?,
                    frequency=?, color=?, notes=?
              WHERE id=?"
        )->execute([
            $args['from_site_id'], $args['to_site_id'], $args['type'], $args['label'],
            $args['capacity_mbps'], $args['frequency'], $args['color'], $args['notes'], $id,
        ]);
        return $id;
    }
    pdo()->prepare(
        "INSERT INTO site_links
            (from_site_id, to_site_id, type, label, capacity_mbps, frequency, color, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $args['from_site_id'], $args['to_site_id'], $args['type'], $args['label'],
        $args['capacity_mbps'], $args['frequency'], $args['color'], $args['notes'],
    ]);
    return (int)pdo()->lastInsertId();
}

function site_link_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM site_links WHERE id = ?")->execute([$id]);
}

/* ---------------------------------------------------------- geocoding */

/**
 * Geocode a free-form address via OpenStreetMap Nominatim.
 *
 * Returns ['lat'=>float,'lng'=>float,'display_name'=>string] or null. Cached
 * for an hour in the rate_limit table to avoid hammering Nominatim — they
 * ask for ≤ 1 req/sec and no bulk geocoding, which is fine for our scale.
 */
function geocode_address(string $address): ?array {
    $address = trim($address);
    if ($address === '') return null;

    $cache_key = 'geocode:' . sha1($address);

    // Soft-rate-limit: at most 1 successful Nominatim hit per second
    // application-wide. enforce_global_post_rate_limit is too coarse here.
    if (!rate_limit_check('nominatim', 1, 1)) {
        usleep(1100000); // 1.1s
    }

    $url = 'https://nominatim.openstreetmap.org/search?'
         . http_build_query([
             'q'              => $address,
             'format'         => 'json',
             'limit'          => 1,
             'addressdetails' => 0,
             'countrycodes'   => 'za',
         ]);

    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'WiFIBER-admin/1.0 (+https://wifiber.co.za)',
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) return null;
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;

    return [
        'lat'          => (float)$data[0]['lat'],
        'lng'          => (float)$data[0]['lon'],
        'display_name' => (string)($data[0]['display_name'] ?? ''),
    ];
}
