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

function site_link_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM site_links WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['id']            = (int)$row['id'];
    $row['from_site_id']  = (int)$row['from_site_id'];
    $row['to_site_id']    = (int)$row['to_site_id'];
    $row['capacity_mbps'] = $row['capacity_mbps'] !== null ? (float)$row['capacity_mbps'] : null;
    return $row;
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

/* ---------------------------------------------------- attachments + contacts */

const SITE_ATTACH_DIR    = DATA_DIR . '/site-attachments';
const SITE_ATTACH_MAX    = 10 * 1024 * 1024; // 10 MB — site photos can be big
const SITE_ATTACH_TYPES  = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'heic' => 'image/heic',
    'svg'  => 'image/svg+xml',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt'  => 'text/plain',
];
const SITE_ATTACH_KINDS = ['photo', 'contract', 'deed', 'diagram', 'permit', 'other'];
const SITE_CONTACT_ROLES = ['landlord', 'key_holder', 'security', 'technical', 'municipal', 'other'];

function site_attachments_for(int $site_id): array {
    if ($site_id <= 0) return [];
    $stmt = pdo()->prepare(
        "SELECT * FROM site_attachments WHERE site_id = ? ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']          = (int)$r['id'];
        $r['site_id']     = (int)$r['site_id'];
        $r['file_size']   = (int)$r['file_size'];
        $r['uploaded_by'] = $r['uploaded_by'] !== null ? (int)$r['uploaded_by'] : null;
    }
    return $rows;
}

function site_attachment_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM site_attachments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function site_attachment_save(int $site_id, ?array $f, string $caption, string $kind, ?int $uploaded_by): int {
    if ($site_id <= 0) throw new InvalidArgumentException('Site id is required.');
    if (!$f || !is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('No file uploaded.');
    }
    $err = (int)$f['error'];
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed: ' . site_upload_error_msg($err));
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('Empty file.');
    if ($size > SITE_ATTACH_MAX) throw new RuntimeException('File is too big (max 10 MB).');

    $orig = (string)($f['name'] ?? 'file');
    $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset(SITE_ATTACH_TYPES[$ext])) {
        throw new RuntimeException('File type ".' . $ext . '" is not allowed. Allowed: ' . implode(', ', array_keys(SITE_ATTACH_TYPES)));
    }
    if (!is_dir(SITE_ATTACH_DIR)) @mkdir(SITE_ATTACH_DIR, 0755, true);
    if (!is_dir(SITE_ATTACH_DIR) || !is_writable(SITE_ATTACH_DIR)) {
        throw new RuntimeException('Site attachment directory is not writable: ' . SITE_ATTACH_DIR);
    }

    $rand = bin2hex(random_bytes(8));
    $base = date('Ymd-His') . '-' . $rand . '.' . $ext;
    $dest = SITE_ATTACH_DIR . '/' . $base;
    $tmp  = (string)($f['tmp_name'] ?? '');

    $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$ok) throw new RuntimeException('Could not save the uploaded file.');
    @chmod($dest, 0644);

    $kind = in_array($kind, SITE_ATTACH_KINDS, true) ? $kind : 'other';

    $stmt = pdo()->prepare(
        "INSERT INTO site_attachments
            (site_id, kind, file_path, file_name, file_size, mime, caption, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $site_id, $kind, $base, mb_substr($orig, 0, 255), $size,
        SITE_ATTACH_TYPES[$ext], mb_substr($caption, 0, 255), $uploaded_by,
    ]);
    return (int)pdo()->lastInsertId();
}

function site_attachment_delete(int $id): bool {
    $a = site_attachment_find($id);
    if (!$a) return false;
    $full = site_attachment_full_path((string)$a['file_path']);
    if ($full) @unlink($full);
    return pdo()->prepare("DELETE FROM site_attachments WHERE id = ?")->execute([$id]);
}

function site_attachment_full_path(string $relative): ?string {
    if ($relative === '' || strpos($relative, '/') !== false || strpos($relative, '\\') !== false || strpos($relative, '..') !== false) {
        return null;
    }
    $full = SITE_ATTACH_DIR . '/' . $relative;
    return is_file($full) ? $full : null;
}

function site_attachment_stream(array $attachment): void {
    $rel  = (string)($attachment['file_path'] ?? '');
    $full = site_attachment_full_path($rel);
    if (!$full) {
        http_response_code(404);
        die('Attachment not found.');
    }
    $name = (string)($attachment['file_name'] ?? basename($rel));
    $mime = (string)($attachment['mime'] ?? 'application/octet-stream');
    $disp = str_starts_with($mime, 'image/') || $mime === 'application/pdf' ? 'inline' : 'attachment';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($full);
    exit;
}

function site_upload_error_msg(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'file is bigger than the server allows';
        case UPLOAD_ERR_PARTIAL:    return 'upload was interrupted';
        case UPLOAD_ERR_NO_TMP_DIR: return 'server has no temp directory';
        case UPLOAD_ERR_CANT_WRITE: return 'server could not write the file';
        case UPLOAD_ERR_EXTENSION:  return 'a PHP extension blocked the upload';
        default: return 'unknown error (' . $code . ')';
    }
}

/* ---------------------------------------------------------------- contacts */

function site_contacts_for(int $site_id): array {
    if ($site_id <= 0) return [];
    $stmt = pdo()->prepare(
        "SELECT * FROM site_contacts
          WHERE site_id = ?
          ORDER BY is_primary DESC, role ASC, name ASC"
    );
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['site_id']    = (int)$r['site_id'];
        $r['is_primary'] = !empty($r['is_primary']);
    }
    return $rows;
}

function site_contact_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM site_contacts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function site_contact_save(array $data, ?int $id = null): int {
    $site_id = (int)($data['site_id'] ?? 0);
    if ($site_id <= 0) throw new InvalidArgumentException('Site is required.');
    if (!site_find($site_id))    throw new InvalidArgumentException('Site not found.');

    $role  = in_array($data['role'] ?? '', SITE_CONTACT_ROLES, true) ? $data['role'] : 'other';
    $name  = trim((string)($data['name']  ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $notes = trim((string)($data['notes'] ?? '')) ?: null;
    $is_primary = !empty($data['is_primary']) ? 1 : 0;

    if ($name === '') throw new InvalidArgumentException('Contact name is required.');

    // Only one primary per site — flip everyone else off when this one
    // claims the slot.
    if ($is_primary) {
        $clr = pdo()->prepare("UPDATE site_contacts SET is_primary = 0 WHERE site_id = ?");
        $clr->execute([$site_id]);
    }

    if ($id) {
        pdo()->prepare(
            "UPDATE site_contacts
                SET site_id=?, role=?, name=?, phone=?, email=?, notes=?, is_primary=?
              WHERE id=?"
        )->execute([$site_id, $role, $name, $phone, $email, $notes, $is_primary, $id]);
        return $id;
    }
    pdo()->prepare(
        "INSERT INTO site_contacts (site_id, role, name, phone, email, notes, is_primary)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$site_id, $role, $name, $phone, $email, $notes, $is_primary]);
    return (int)pdo()->lastInsertId();
}

function site_contact_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM site_contacts WHERE id = ?")->execute([$id]);
}

/**
 * site_links joined to both endpoint sites, with great-circle distance
 * between them in km. Used by /admin/links.php's backbone section so the
 * admin sees something more useful than raw IDs.
 */
function site_links_with_sites(): array {
    $rows = pdo()->query(
        "SELECT sl.*,
                fs.name AS from_name, fs.lat AS from_lat, fs.lng AS from_lng,
                ts.name AS to_name,   ts.lat AS to_lat,   ts.lng AS to_lng
           FROM site_links sl
           JOIN sites fs ON fs.id = sl.from_site_id
           JOIN sites ts ON ts.id = sl.to_site_id
          ORDER BY sl.id ASC"
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['id']            = (int)$r['id'];
        $r['from_site_id']  = (int)$r['from_site_id'];
        $r['to_site_id']    = (int)$r['to_site_id'];
        $r['capacity_mbps'] = $r['capacity_mbps'] !== null ? (float)$r['capacity_mbps'] : null;
        $r['distance_km']   = haversine_km(
            (float)$r['from_lat'], (float)$r['from_lng'],
            (float)$r['to_lat'],   (float)$r['to_lng']
        );
    }
    return $rows;
}

/**
 * Great-circle distance between two lat/lng pairs, in kilometres.
 * Earth radius 6371 km. Good enough for short PTP / backhaul spans.
 *
 * Guarded with function_exists because auth/wireless.php declares an
 * identical helper, and a page that loads both files (e.g. links.php)
 * would otherwise fatal on redeclaration.
 */
if (!function_exists('haversine_km')) {
    function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $R = 6371.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dphi = deg2rad($lat2 - $lat1);
        $dlam = deg2rad($lng2 - $lng1);
        $a = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
        return 2 * $R * asin(min(1.0, sqrt($a)));
    }
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

/**
 * Nominatim free-text search returning up to $limit candidates. Used by
 * the address autocomplete on the client editor — same rate-limit dance
 * as geocode_address.
 */
function nominatim_search(string $address, int $limit = 5): array {
    $address = trim($address);
    if ($address === '' || !function_exists('curl_init')) return [];

    if (!rate_limit_check('nominatim', 1, 1)) {
        usleep(1100000);
    }

    $url = 'https://nominatim.openstreetmap.org/search?'
         . http_build_query([
             'q'              => $address,
             'format'         => 'json',
             'limit'          => max(1, min(10, $limit)),
             'addressdetails' => 0,
             'countrycodes'   => 'za',
         ]);

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

    if ($code !== 200 || !$resp) return [];
    $data = json_decode($resp, true);
    if (!is_array($data)) return [];

    $out = [];
    foreach ($data as $row) {
        if (empty($row['lat']) || empty($row['lon'])) continue;
        $out[] = [
            'lat'          => (float)$row['lat'],
            'lng'          => (float)$row['lon'],
            'display_name' => (string)($row['display_name'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Reverse-geocode a lat/lng to a display name. Used when the admin drops
 * a pin on the editor map and we want to fill in the address field.
 */
function nominatim_reverse(float $lat, float $lng): ?string {
    if (!function_exists('curl_init')) return null;

    if (!rate_limit_check('nominatim', 1, 1)) {
        usleep(1100000);
    }

    $url = 'https://nominatim.openstreetmap.org/reverse?'
         . http_build_query([
             'lat'    => $lat,
             'lon'    => $lng,
             'format' => 'json',
             'zoom'   => 18,
         ]);

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
    if (!is_array($data) || empty($data['display_name'])) return null;

    return (string)$data['display_name'];
}
