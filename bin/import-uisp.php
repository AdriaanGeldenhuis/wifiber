<?php
/**
 * UISP (Ubiquiti Network Management System) importer.
 *
 * Reads UISP's REST API and upserts:
 *
 *   sites       (id, name, lat, lng, height, type)        → sites
 *   devices     (id, identification.{mac,model,...},      → devices
 *               attributes.tower, attributes.role)
 *   data-links  (from / to → device.id)                   → wireless_links
 *
 * Idempotency: every row is keyed on (external_src='uisp', external_ref=
 * UISP's id).  Re-running the importer updates existing rows; manual
 * rows without an external_ref are left alone.
 *
 * Usage:
 *   php bin/import-uisp.php --base-url=https://uisp.example.com \
 *                            --token=YOUR_X_AUTH_TOKEN \
 *                            [--dry-run] [--limit=50] \
 *                            [--only=sites,devices,links]
 *
 * Auth: UISP uses the X-Auth-Token header. Generate one in the UISP UI
 * (Settings → User → API tokens).
 *
 * The HTTP layer talks to /nms/api/v2.1/ — older UISP installs serving
 * v2.0 will need --api=2.0 (auto-fallback if the v2.1 endpoint 404s).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/importers.php';
require __DIR__ . '/../auth/sites.php';
require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';

const UISP_RESOURCES = ['sites', 'devices', 'links'];

$opts = [
    'dry-run'  => false,
    'limit'    => 0,
    'base-url' => '',
    'token'    => '',
    'only'     => UISP_RESOURCES,
    'api'      => '2.1',
];
$rest = importer_parse_common_args($argv, $opts);
foreach ($rest as $a) {
    if (preg_match('/^--api=(2\.0|2\.1)$/', $a, $m)) { $opts['api'] = $m[1]; continue; }
    fwrite(STDERR, "unknown arg: $a\n"); exit(2);
}

if ($opts['base-url'] === '' || $opts['token'] === '') {
    fwrite(STDERR, "usage: import-uisp.php --base-url=https://… --token=… [--dry-run] [--limit=N] [--only=sites,devices,links]\n");
    exit(2);
}
$opts['only'] = array_values(array_intersect($opts['only'], UISP_RESOURCES));
if (!$opts['only']) {
    fwrite(STDERR, "nothing to do (--only filtered everything)\n");
    exit(0);
}

$base   = rtrim($opts['base-url'], '/');
$auth   = ['header' => ['X-Auth-Token: ' . $opts['token']]];
$prefix = $base . '/nms/api/v' . $opts['api'];

echo "[uisp] base=$base api=" . $opts['api'] . " resources=" . implode(',', $opts['only']) . ($opts['dry-run'] ? ' (DRY RUN)' : '') . "\n";

/* ------------------------------------------------------------ resources */

if (in_array('sites', $opts['only'], true)) {
    uisp_import_sites($prefix, $auth, $opts);
}
if (in_array('devices', $opts['only'], true)) {
    uisp_import_devices($prefix, $auth, $opts);
}
if (in_array('links', $opts['only'], true)) {
    uisp_import_data_links($prefix, $auth, $opts);
}

echo "[uisp] done.\n";

/* ============================================================ functions */

function uisp_import_sites(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('uisp', 'sites', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- sites ---\n";

    $r = importer_http_get_json($prefix . '/sites', $auth);
    if (!$r['ok']) {
        echo "  ! fetch failed: {$r['error']}\n";
        importer_run_end($run, $c->as_array(), $r['error']);
        return;
    }
    $rows = is_array($r['data']) ? $r['data'] : [];
    if ($opts['limit'] > 0) $rows = array_slice($rows, 0, $opts['limit']);

    foreach ($rows as $s) {
        $c->total++;
        $ref = (string)($s['id'] ?? '');
        if ($ref === '') { $c->failed++; continue; }
        $type = uisp_site_type((string)($s['type'] ?? ''));
        $values = [
            'name'              => mb_substr((string)($s['name'] ?? 'UISP Site ' . $ref), 0, 120),
            'type'              => $type,
            'lat'               => isset($s['location']['latitude'])  ? (float)$s['location']['latitude']  : 0.0,
            'lng'               => isset($s['location']['longitude']) ? (float)$s['location']['longitude'] : 0.0,
            'height_m'          => isset($s['elevation']) ? (float)$s['elevation'] : null,
            'is_active'         => 1,
        ];
        if (empty($values['lat']) && empty($values['lng'])) {
            $c->skipped++;
            echo "  - skip {$values['name']} (no GPS in UISP)\n";
            continue;
        }
        try {
            $res = importer_upsert_external_ref('sites', 'uisp', $ref, $values, $opts['dry-run']);
            $c->note($res['change']);
            if ($res['change'] !== 'noop') echo "  + {$res['change']}: {$values['name']} (uisp #$ref)\n";
        } catch (Throwable $e) {
            echo "  ! failed for $ref: {$e->getMessage()}\n";
            $c->failed++;
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function uisp_site_type(string $uisp_type): string {
    return match (strtolower($uisp_type)) {
        'site', 'tower' => 'tower',
        'pop', 'datacenter' => 'pop',
        'endpoint'      => 'ptp_endpoint',
        default         => 'tower',
    };
}

function uisp_import_devices(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('uisp', 'devices', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- devices ---\n";

    $r = importer_http_get_json($prefix . '/devices', $auth);
    if (!$r['ok']) {
        echo "  ! fetch failed: {$r['error']}\n";
        importer_run_end($run, $c->as_array(), $r['error']);
        return;
    }
    $rows = is_array($r['data']) ? $r['data'] : [];
    if ($opts['limit'] > 0) $rows = array_slice($rows, 0, $opts['limit']);

    foreach ($rows as $d) {
        $c->total++;
        $ref = (string)($d['identification']['id'] ?? '');
        if ($ref === '') { $c->failed++; continue; }
        $mac = mac_canonical((string)($d['identification']['mac'] ?? ''));

        // Parent site lookup — UISP's site object is denormalised onto each device.
        $site_uisp_id = (string)($d['identification']['site']['id'] ?? '');
        $site_id      = null;
        if ($site_uisp_id !== '') {
            $row = importer_find_by_external_ref('sites', 'uisp', $site_uisp_id);
            if ($row) $site_id = (int)$row['id'];
        }

        $values = [
            'site_id'  => $site_id,
            'name'     => mb_substr((string)($d['identification']['name'] ?? 'UISP ' . $ref), 0, 120),
            'vendor'   => 'ubiquiti',
            'model'    => mb_substr((string)($d['identification']['model'] ?? ''), 0, 60),
            'role'     => uisp_device_role((string)($d['identification']['type'] ?? '')),
            'serial'   => mb_substr((string)($d['identification']['serialNumber'] ?? ''), 0, 60),
            'mac'      => $mac,
            'mgmt_ip'  => (string)($d['ipAddress'] ?? ''),
            'firmware' => mb_substr((string)($d['firmware']['current'] ?? $d['firmwareVersion'] ?? ''), 0, 60),
            'status'   => uisp_device_status((string)($d['overview']['status'] ?? '')),
        ];

        try {
            $res = importer_upsert_external_ref(
                'devices',
                'uisp',
                $ref,
                $values,
                $opts['dry-run'],
                $mac !== '' ? ['mac' => $mac] : null
            );
            $c->note($res['change']);
            if ($res['change'] !== 'noop') {
                echo "  + {$res['change']}: {$values['name']} ({$values['model']}, {$values['mac']})\n";
            }
        } catch (Throwable $e) {
            echo "  ! failed for $ref: {$e->getMessage()}\n";
            $c->failed++;
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function uisp_device_role(string $uisp_type): string {
    $u = strtolower($uisp_type);
    return match (true) {
        str_contains($u, 'router') => 'router',
        str_contains($u, 'switch') => 'switch',
        str_contains($u, 'ap')     => 'ap',
        str_contains($u, 'sta'),
        str_contains($u, 'cpe')    => 'cpe',
        str_contains($u, 'olt'),
        str_contains($u, 'onu')    => 'other',
        str_contains($u, 'ptp')    => 'backhaul',
        default                    => 'other',
    };
}

function uisp_device_status(string $uisp_status): string {
    return match (strtolower($uisp_status)) {
        'active', 'connected', 'online' => 'online',
        'disconnected', 'offline'        => 'offline',
        'retired'                        => 'retired',
        default                          => 'unknown',
    };
}

function uisp_import_data_links(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('uisp', 'links', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- data-links ---\n";

    $r = importer_http_get_json($prefix . '/data-links', $auth);
    if (!$r['ok']) {
        echo "  ! fetch failed: {$r['error']}\n";
        importer_run_end($run, $c->as_array(), $r['error']);
        return;
    }
    $rows = is_array($r['data']) ? $r['data'] : [];
    if ($opts['limit'] > 0) $rows = array_slice($rows, 0, $opts['limit']);

    foreach ($rows as $dl) {
        $c->total++;
        $ref = (string)($dl['id'] ?? '');
        if ($ref === '') { $c->failed++; continue; }

        // Resolve UISP device IDs → local device IDs via external_ref.
        $from_id = uisp_resolve_device((string)($dl['from']['device']['id'] ?? ''));
        $to_id   = uisp_resolve_device((string)($dl['to']['device']['id']   ?? ''));
        if (!$from_id || !$to_id) {
            $c->skipped++;
            continue;
        }

        // Only one side of a UISP "data-link" is the AP — the other is a
        // station (CPE) or another AP (PTP). Use ap_device_id naming.
        $ssid = (string)($dl['ssid'] ?? '');
        $values = [
            'ap_device_id'  => $from_id,
            'cpe_device_id' => $to_id,
            'ssid'          => mb_substr($ssid, 0, 80),
            'frequency_mhz' => isset($dl['frequency']) ? (int)$dl['frequency'] : null,
            'distance_km'   => isset($dl['distance']) ? round((float)$dl['distance'] / 1000, 3) : null,
        ];

        try {
            $res = importer_upsert_external_ref('wireless_links', 'uisp', $ref, $values, $opts['dry-run']);
            $c->note($res['change']);
            if ($res['change'] !== 'noop') echo "  + {$res['change']}: link uisp #$ref ($from_id ↔ $to_id)\n";
        } catch (Throwable $e) {
            echo "  ! failed for $ref: {$e->getMessage()}\n";
            $c->failed++;
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function uisp_resolve_device(string $uisp_id): ?int {
    if ($uisp_id === '') return null;
    $row = importer_find_by_external_ref('devices', 'uisp', $uisp_id);
    return $row ? (int)$row['id'] : null;
}
