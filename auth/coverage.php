<?php
/**
 * Coverage check + waitlist (Section 5 of the roadmap).
 *
 * Coverage areas live in data/coverage.json (admin-editable). The waitlist
 * lives in the coverage_waitlist DB table so admins can triage leads.
 *
 * The matching is intentionally simple: lowercase + alphanumeric-only the
 * needle and each area name/alias/suburb, then substring-match. Good enough
 * for a small ISP serving named towns; can be swapped for GeoJSON polygons
 * later without changing the public API.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const COVERAGE_FILE       = DATA_DIR . '/coverage.json';
const COVERAGE_AREA_LIMIT = 50;

const WAITLIST_STATUSES = ['new', 'contacted', 'converted', 'dropped'];
const WAITLIST_STATUS_LABELS = [
    'new'       => 'New',
    'contacted' => 'Contacted',
    'converted' => 'Signed up',
    'dropped'   => 'Dropped',
];

/* --------------------------------------------------------------- areas */

function coverage_load(): array {
    $d = json_load(COVERAGE_FILE, ['intro' => '', 'areas' => []]);
    $d['areas'] = is_array($d['areas'] ?? null) ? $d['areas'] : [];
    $d['intro'] = (string)($d['intro'] ?? '');
    return $d;
}

function coverage_save(array $data): bool {
    $clean = [
        'intro' => trim((string)($data['intro'] ?? '')),
        'areas' => [],
    ];
    foreach (($data['areas'] ?? []) as $a) {
        $name = trim((string)($a['name'] ?? ''));
        if ($name === '') continue;
        $aliases  = coverage_split_list($a['aliases']  ?? []);
        $suburbs  = coverage_split_list($a['suburbs']  ?? []);
        $clean['areas'][] = [
            'name'     => mb_substr($name, 0, 80),
            'aliases'  => $aliases,
            'suburbs'  => $suburbs,
        ];
        if (count($clean['areas']) >= COVERAGE_AREA_LIMIT) break;
    }
    return json_save(COVERAGE_FILE, $clean);
}

function coverage_split_list($v): array {
    if (is_array($v)) {
        $items = $v;
    } else {
        $items = preg_split('/[\r\n,;]+/', (string)$v) ?: [];
    }
    $out = [];
    foreach ($items as $i) {
        $i = trim((string)$i);
        if ($i !== '') $out[] = mb_substr($i, 0, 80);
    }
    return array_values(array_unique($out));
}

function coverage_normalise(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
    return $s;
}

/**
 * Returns ['matched' => bool, 'area' => ?array, 'matched_term' => ?string,
 *          'tower' => ?array, 'distance_km' => ?float].
 *
 * Coverage is the union of two sources:
 *   1. Curated suburb / town names in data/coverage.json (admin-editable;
 *      lets ops list "we serve here" before a tower is up).
 *   2. Live tower coverage radii from the sites table (auto-derived as
 *      towers are added / moved / have their coverage_radius_m updated —
 *      no JSON edit needed). Activated when the address geocodes to a
 *      lat/lng inside any tower's circle.
 */
function coverage_check(string $address): array {
    $needle = coverage_normalise($address);
    $miss = ['matched' => false, 'area' => null, 'matched_term' => null,
             'tower'   => null,  'distance_km' => null];
    if ($needle === '') return $miss;

    /* (1) Curated text match — fastest, run first. */
    foreach (coverage_load()['areas'] as $area) {
        $candidates = array_merge(
            [(string)$area['name']],
            (array)($area['aliases'] ?? []),
            (array)($area['suburbs'] ?? [])
        );
        foreach ($candidates as $term) {
            $norm = coverage_normalise((string)$term);
            // Skip very short terms (e.g. "SE", "CW") — they cause false positives
            // because the normalised needle has all spaces stripped, so "se" matches
            // anywhere it appears as a substring (e.g. "rivers east" -> "...rseast").
            if (strlen($norm) < 3) continue;
            if (strpos($needle, $norm) !== false) {
                return ['matched' => true, 'area' => $area, 'matched_term' => (string)$term,
                        'tower'   => null,  'distance_km' => null];
            }
        }
    }

    /* (2) Live tower-radius match — geocode the address, then haversine
       it against every tower's coverage circle. */
    $hit = coverage_check_live_towers($address);
    if ($hit) return $hit;

    return $miss;
}

/* GPS-based coverage check. Returns the same shape as coverage_check
   on a hit, or null if no tower covers the geocoded address (or
   geocoding fails). Pulled out so it can be called directly when the
   caller already has lat/lng. */
function coverage_check_live_towers(string $address): ?array {
    if (!is_file(__DIR__ . '/sites.php')) return null;
    require_once __DIR__ . '/sites.php';

    $hits = nominatim_search($address, 1);
    if (!$hits || !isset($hits[0]['lat'], $hits[0]['lng'])) return null;
    return coverage_check_by_gps((float)$hits[0]['lat'], (float)$hits[0]['lng']);
}

function coverage_check_by_gps(float $lat, float $lng): ?array {
    require_once __DIR__ . '/sites.php';
    /* Only towers carry meaningful coverage radii; APs / PoPs / other
       sites are infrastructure pins. */
    $towers = array_values(array_filter(
        sites_all(true),
        fn ($s) => ($s['type'] ?? '') === 'tower'
                && ($s['coverage_radius_m'] ?? 0) > 0
                && $s['lat'] !== null && $s['lng'] !== null
    ));
    if (!$towers) return null;

    $best = null;
    foreach ($towers as $t) {
        $km = haversine_km((float)$t['lat'], (float)$t['lng'], $lat, $lng);
        $reach_km = ((float)$t['coverage_radius_m']) / 1000.0;
        if ($km <= $reach_km) {
            if ($best === null || $km < $best['distance_km']) {
                $best = ['tower' => $t, 'distance_km' => $km];
            }
        }
    }
    if (!$best) return null;
    return [
        'matched'      => true,
        'area'         => null,
        'matched_term' => null,
        'tower'        => $best['tower'],
        'distance_km'  => round($best['distance_km'], 2),
    ];
}

/* ------------------------------------------------------------ waitlist */

function waitlist_create(array $data): int {
    $address = trim((string)($data['address'] ?? ''));
    $name    = trim((string)($data['name']    ?? ''));
    $email   = trim((string)($data['email']   ?? ''));
    $phone   = trim((string)($data['phone']   ?? ''));
    $notes   = trim((string)($data['notes']   ?? ''));

    if ($address === '')                                          throw new InvalidArgumentException('Address is required.');
    if ($email === '' && $phone === '')                           throw new InvalidArgumentException('Give us at least an email or a phone number so we can let you know.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email is not valid.');

    $stmt = pdo()->prepare(
        "INSERT INTO coverage_waitlist (address, name, email, phone, notes, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        mb_substr($address, 0, 255),
        mb_substr($name,    0, 120),
        mb_substr($email,   0, 120),
        mb_substr($phone,   0,  40),
        $notes !== '' ? mb_substr($notes, 0, 2000) : null,
        client_ip(),
    ]);
    return (int)pdo()->lastInsertId();
}

function waitlist_all(?string $status = null): array {
    if ($status && !in_array($status, WAITLIST_STATUSES, true)) $status = null;
    $sql = "SELECT * FROM coverage_waitlist";
    $args = [];
    if ($status) { $sql .= " WHERE status = ?"; $args[] = $status; }
    $sql .= " ORDER BY (status = 'dropped') ASC, created_at DESC, id DESC";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function waitlist_set_status(int $id, string $status): bool {
    if (!in_array($status, WAITLIST_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown status.');
    }
    return pdo()->prepare("UPDATE coverage_waitlist SET status = ? WHERE id = ?")
        ->execute([$status, $id]);
}

function waitlist_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM coverage_waitlist WHERE id = ?")->execute([$id]);
}

/* ---------------------------------------------------------------- email */

function notify_admin_of_waitlist_lead(int $id): array {
    $stmt = pdo()->prepare("SELECT * FROM coverage_waitlist WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'reason' => 'lead missing'];

    $site      = load_site_settings();
    $site_name = (string)($site['name'] ?? 'WiFIBER');
    $to        = (string)($site['email_admin'] ?? $site['email_support'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'no admin email configured'];
    }

    $body  = "New coverage waitlist signup.\n\n";
    $body .= "Address:  {$row['address']}\n";
    if ($row['name'])  $body .= "Name:     {$row['name']}\n";
    if ($row['email']) $body .= "Email:    {$row['email']}\n";
    if ($row['phone']) $body .= "Phone:    {$row['phone']}\n";
    if ($row['notes']) $body .= "Notes:    {$row['notes']}\n";
    $body .= "Captured: {$row['created_at']}\n";
    $body .= "From IP:  " . ($row['ip_address'] ?: 'unknown') . "\n\n";
    $body .= "Manage waitlist: " . ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za')) . "/admin/coverage.php\n";

    $headers = "From: {$site_name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . ($row['email'] ? "Reply-To: {$row['email']}\r\n" : '')
             . "X-Mailer: WiFIBER-Coverage\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($to, "[Coverage waitlist] {$row['address']}", $body, $headers);
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}
