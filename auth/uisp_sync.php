<?php
/**
 * UISP sync — pulls live data from UISP's NMS + CRM APIs into local cache
 * tables (uisp_sites, uisp_devices, uisp_data_links, uisp_clients).
 *
 * Upserts by uisp_id; rows that disappear from UISP are marked is_stale = 1
 * (not hard-deleted) so any manual records linked via uisp_id keep working.
 *
 * Designed to be idempotent and safe to run on a schedule. A 60-second
 * concurrency lock (rate_limit table) prevents overlapping syncs.
 */

declare(strict_types=1);

require_once __DIR__ . '/uisp.php';

/* ------------------------------------------------------------------ all */

function uisp_sync_all(): array {
    $cfg = uisp_config();
    if (!uisp_is_configured()) {
        return ['ok' => false, 'errors' => ['UISP base_url and api_token must be set.'], 'counts' => [], 'version' => null];
    }
    if (!rate_limit_check('uisp_sync', 1, 60)) {
        return ['ok' => false, 'errors' => ['A sync just ran. Wait a minute and try again.'], 'counts' => [], 'version' => null];
    }

    $counts  = ['sites' => 0, 'devices' => 0, 'data_links' => 0, 'clients' => 0];
    $errors  = [];
    $version = null;

    // Best-effort version probe (non-fatal).
    try {
        $info = uisp_request('GET', '/nms/api/v2.1/server');
        $version = is_array($info) ? ($info['version'] ?? $info['nmsVersion'] ?? null) : null;
    } catch (Throwable $e) {
        // Some custom UISP builds disable /server — ignore.
    }

    $enabled = $cfg['enabled'] ?? [];

    if (!empty($enabled['sites'])) {
        try { $counts['sites']      = uisp_sync_sites(); }
        catch (Throwable $e) { $errors[] = 'sites: '      . $e->getMessage(); }
    }
    if (!empty($enabled['devices'])) {
        try { $counts['devices']    = uisp_sync_devices(); }
        catch (Throwable $e) { $errors[] = 'devices: '    . $e->getMessage(); }
    }
    if (!empty($enabled['links'])) {
        try { $counts['data_links'] = uisp_sync_data_links(); }
        catch (Throwable $e) { $errors[] = 'data_links: ' . $e->getMessage(); }
    }
    if (!empty($enabled['clients'])) {
        try { $counts['clients']    = uisp_sync_clients(); }
        catch (Throwable $e) { $errors[] = 'clients: '    . $e->getMessage(); }
    }

    $ok = empty($errors);
    uisp_save_config([
        'last_sync_at'      => date('c'),
        'last_sync_status'  => $ok ? 'ok' : 'partial',
        'last_sync_error'   => $errors ? implode(' | ', $errors) : null,
        'last_sync_counts'  => $counts,
        'last_sync_version' => $version,
    ]);
    audit_log('uisp.sync', ['meta' => ['ok' => $ok, 'counts' => $counts, 'errors' => $errors]]);

    return ['ok' => $ok, 'counts' => $counts, 'errors' => $errors, 'version' => $version];
}

/* ----------------------------------------------------------------- sites */

function uisp_sync_sites(): int {
    $rows = uisp_sites_fetch();
    $stmt = pdo()->prepare(
        "INSERT INTO uisp_sites
            (uisp_id, name, address, lat, lng, height_m, status, raw_json, last_seen_at, is_stale)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE
            name=VALUES(name), address=VALUES(address), lat=VALUES(lat), lng=VALUES(lng),
            height_m=VALUES(height_m), status=VALUES(status), raw_json=VALUES(raw_json),
            last_seen_at=VALUES(last_seen_at), is_stale=0"
    );
    $seen = [];
    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        if ($id === '') continue;
        $seen[] = $id;

        $ident  = $r['identification'] ?? [];
        $loc    = $r['location']       ?? [];
        $name   = $ident['name'] ?? ($r['name'] ?? '(unnamed)');
        $lat    = isset($loc['latitude'])  && is_numeric($loc['latitude'])  ? (float)$loc['latitude']  : null;
        $lng    = isset($loc['longitude']) && is_numeric($loc['longitude']) ? (float)$loc['longitude'] : null;
        $addr   = $loc['address']    ?? null;
        $height = isset($loc['elevation']) && is_numeric($loc['elevation']) ? (float)$loc['elevation'] : null;
        $status = $r['status'] ?? ($r['qos']['status'] ?? null);

        $stmt->execute([
            $id, $name, $addr, $lat, $lng, $height, $status,
            json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('Y-m-d H:i:s'),
        ]);
    }
    uisp_mark_stale('uisp_sites', $seen);
    return count($seen);
}

/* --------------------------------------------------------------- devices */

function uisp_sync_devices(): int {
    $rows = uisp_devices_fetch();
    $stmt = pdo()->prepare(
        "INSERT INTO uisp_devices
            (uisp_id, uisp_site_id, name, type, model, mac, ip, role,
             status, signal_dbm, lat, lng, raw_json, last_seen_at, is_stale)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE
            uisp_site_id=VALUES(uisp_site_id), name=VALUES(name), type=VALUES(type),
            model=VALUES(model), mac=VALUES(mac), ip=VALUES(ip), role=VALUES(role),
            status=VALUES(status), signal_dbm=VALUES(signal_dbm),
            lat=VALUES(lat), lng=VALUES(lng), raw_json=VALUES(raw_json),
            last_seen_at=VALUES(last_seen_at), is_stale=0"
    );
    $seen = [];
    foreach ($rows as $r) {
        $ident = $r['identification'] ?? [];
        $id = (string)($ident['id'] ?? $r['id'] ?? '');
        if ($id === '') continue;
        $seen[] = $id;

        $over    = $r['overview'] ?? [];
        $name    = $ident['name']     ?? $ident['hostname'] ?? '(unnamed)';
        $type    = $ident['type']     ?? null;
        $model   = $ident['model']    ?? null;
        $mac     = $ident['mac']      ?? null;
        $role    = $ident['role']     ?? null;
        $ipa     = $r['ipAddress']    ?? null;
        $site_id = $ident['site']['id'] ?? ($r['site']['id'] ?? null);

        $online_raw = $over['status'] ?? null;
        if (in_array($online_raw, ['active', 'connected'], true)) {
            $status = 'online';
        } elseif (in_array($online_raw, ['inactive', 'disconnected', 'offline'], true)) {
            $status = 'offline';
        } else {
            $status = 'unknown';
        }

        $signal = $over['signal'] ?? null;
        $signal = is_numeric($signal) ? (int)$signal : null;

        $loc = $r['location'] ?? null;
        $lat = (is_array($loc) && isset($loc['latitude'])  && is_numeric($loc['latitude']))  ? (float)$loc['latitude']  : null;
        $lng = (is_array($loc) && isset($loc['longitude']) && is_numeric($loc['longitude'])) ? (float)$loc['longitude'] : null;

        $last_seen    = $over['lastSeen'] ?? null;
        $last_seen_dt = $last_seen ? (date('Y-m-d H:i:s', strtotime((string)$last_seen) ?: time())) : null;

        $stmt->execute([
            $id, $site_id, $name, $type, $model, $mac, $ipa, $role, $status, $signal, $lat, $lng,
            json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $last_seen_dt,
        ]);
    }
    uisp_mark_stale('uisp_devices', $seen);
    return count($seen);
}

/* ----------------------------------------------------------- data links */

function uisp_sync_data_links(): int {
    $rows = uisp_data_links_fetch();
    $stmt = pdo()->prepare(
        "INSERT INTO uisp_data_links
            (uisp_id, from_device_uisp_id, to_device_uisp_id, frequency, capacity_mbps,
             status, raw_json, is_stale)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE
            from_device_uisp_id=VALUES(from_device_uisp_id),
            to_device_uisp_id=VALUES(to_device_uisp_id),
            frequency=VALUES(frequency), capacity_mbps=VALUES(capacity_mbps),
            status=VALUES(status), raw_json=VALUES(raw_json), is_stale=0"
    );
    $seen = [];
    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        if ($id === '') continue;
        $seen[] = $id;

        $from_id = $r['from']['device']['id'] ?? ($r['fromDeviceId'] ?? null);
        $to_id   = $r['to']['device']['id']   ?? ($r['toDeviceId']   ?? null);
        $freq    = $r['frequency'] ?? null;
        $cap_raw = $r['capacity']  ?? ($r['speed'] ?? null);
        $cap     = is_numeric($cap_raw) ? (float)$cap_raw : null;
        $status  = $r['state'] ?? ($r['status'] ?? null);

        $stmt->execute([
            $id, $from_id, $to_id, $freq, $cap, $status,
            json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
    uisp_mark_stale('uisp_data_links', $seen);
    return count($seen);
}

/* --------------------------------------------------------------- clients */

function uisp_sync_clients(): int {
    $rows = uisp_clients_fetch();

    // Optional services roll-up — best-effort.
    $services = [];
    try {
        foreach (uisp_services_fetch() as $svc) {
            $cid = (string)($svc['clientId'] ?? '');
            if ($cid === '') continue;
            $services[$cid][] = [
                'id'     => $svc['id']         ?? null,
                'name'   => $svc['name']       ?? null,
                'status' => $svc['statusString'] ?? ($svc['status'] ?? null),
                'tariff' => $svc['tariffName'] ?? null,
            ];
        }
    } catch (Throwable $e) {
        // /clients/services may be disabled — leave $services empty.
    }

    $stmt = pdo()->prepare(
        "INSERT INTO uisp_clients
            (uisp_id, account_no, name, email, address_full, lat, lng,
             status, services_summary, raw_json, is_stale)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE
            account_no=VALUES(account_no), name=VALUES(name), email=VALUES(email),
            address_full=VALUES(address_full), lat=VALUES(lat), lng=VALUES(lng),
            status=VALUES(status), services_summary=VALUES(services_summary),
            raw_json=VALUES(raw_json), is_stale=0"
    );
    $seen = [];
    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        if ($id === '') continue;
        $seen[] = $id;

        $first = trim((string)($r['firstName'] ?? ''));
        $last  = trim((string)($r['lastName']  ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name === '') $name = (string)($r['companyName'] ?? '(unnamed)');

        $email = $r['email'] ?? null;
        $acct  = $r['userIdent'] ?? ($r['contractId'] ?? null);

        $addr_parts = array_filter([
            $r['street1']     ?? null,
            $r['street2']     ?? null,
            $r['city']        ?? null,
            $r['countryName'] ?? null,
            $r['zipCode']     ?? null,
        ]);
        $addr = $addr_parts ? implode(', ', $addr_parts) : null;

        $lat = isset($r['addressGpsLat']) && is_numeric($r['addressGpsLat']) ? (float)$r['addressGpsLat'] : null;
        $lng = isset($r['addressGpsLon']) && is_numeric($r['addressGpsLon']) ? (float)$r['addressGpsLon'] : null;

        $status = $r['statusString'] ?? null;
        $svc    = $services[$id] ?? [];

        $stmt->execute([
            $id, $acct, $name, $email, $addr, $lat, $lng, $status,
            $svc ? json_encode($svc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
    uisp_mark_stale('uisp_clients', $seen);
    return count($seen);
}

/* --------------------------------------------------------------- helpers */

/**
 * Mark rows that no longer appear in UISP as stale. We never hard-delete:
 * a manual record might be linked via uisp_id, and we'd rather show a
 * faded "stale" marker than make it vanish.
 *
 * If the API returned an empty list we leave existing rows alone — that's
 * almost certainly an outage / permission issue rather than a real wipe.
 */
function uisp_mark_stale(string $table, array $kept_ids): void {
    if (empty($kept_ids)) return;
    $place = implode(',', array_fill(0, count($kept_ids), '?'));
    $sql = "UPDATE `$table` SET is_stale = 1 WHERE uisp_id NOT IN ($place)";
    pdo()->prepare($sql)->execute($kept_ids);
}
