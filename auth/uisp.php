<?php
/**
 * UISP integration helpers — config, HTTP client, and cache readers.
 *
 * Storage:
 *   data/uisp.json   — base_url, api_token, verify_ssl, sync prefs, last-sync metadata
 *   uisp_sites etc.  — local cache tables populated by auth/uisp_sync.php
 *
 * The token is the "App key" generated under UISP → Settings → Integrations.
 * Both the NMS API (/nms/api/v2.1) and CRM API (/crm/api/v1.0) authenticate
 * with the same x-auth-token header.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const UISP_CONFIG_FILE      = DATA_DIR . '/uisp.json';
const UISP_REQUEST_TIMEOUT  = 15;
const UISP_DEFAULT_INTERVAL = 15;

function uisp_config_default(): array {
    return [
        'base_url'              => '',
        'api_token'             => '',
        'verify_ssl'            => true,
        'enabled'               => [
            'sites'   => true,
            'devices' => true,
            'links'   => true,
            'clients' => true,
        ],
        'sync_interval_minutes' => UISP_DEFAULT_INTERVAL,
        'last_sync_at'          => null,
        'last_sync_status'      => null,
        'last_sync_error'       => null,
        'last_sync_counts'      => null,
        'last_sync_version'     => null,
    ];
}

function uisp_config(): array {
    return array_replace_recursive(uisp_config_default(), json_load(UISP_CONFIG_FILE, []));
}

function uisp_save_config(array $patch): bool {
    $merged = array_replace_recursive(uisp_config(), $patch);
    $ok = json_save(UISP_CONFIG_FILE, $merged);
    if ($ok) @chmod(UISP_CONFIG_FILE, 0600);
    return $ok;
}

function uisp_is_configured(): bool {
    $c = uisp_config();
    return !empty($c['base_url']) && !empty($c['api_token']);
}

/* ----------------------------------------------------------- HTTP client */

/**
 * Make an authenticated request to the UISP API.
 *
 * Returns the decoded JSON body (array | null). Throws RuntimeException with
 * the HTTP code + first 200 chars of the response on any non-2xx or transport
 * failure so callers can show a useful error message.
 *
 * @param  string      $method  GET | POST | PATCH | DELETE
 * @param  string      $path    e.g. /nms/api/v2.1/sites
 * @param  array|null  $body    request body — JSON-encoded automatically
 * @return array|null
 */
function uisp_request(string $method, string $path, ?array $body = null) {
    $cfg = uisp_config();
    if (empty($cfg['base_url']) || empty($cfg['api_token'])) {
        throw new RuntimeException('UISP is not configured. Set base_url and api_token in /admin/uisp.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is not available.');
    }

    $url = rtrim((string)$cfg['base_url'], '/') . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => UISP_REQUEST_TIMEOUT,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'x-auth-token: ' . $cfg['api_token'],
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => !empty($cfg['verify_ssl']),
        CURLOPT_SSL_VERIFYHOST => !empty($cfg['verify_ssl']) ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch) ?: 'unknown cURL error';
        curl_close($ch);
        throw new RuntimeException("UISP request failed: $err");
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $snippet = mb_substr((string)$resp, 0, 200);
        throw new RuntimeException("UISP $method $path returned HTTP $code: $snippet");
    }
    if ($resp === '' || $resp === null) return null;
    $decoded = json_decode((string)$resp, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('UISP returned non-JSON: ' . mb_substr((string)$resp, 0, 200));
    }
    return $decoded;
}

/**
 * Light probe used by the settings page "Test connection" button. Tries the
 * NMS server-info endpoint first (cheap, returns version); falls back to a
 * sites list call if /server is missing on this build.
 */
function uisp_test_connection(): array {
    try {
        $info = uisp_request('GET', '/nms/api/v2.1/server');
        $version = is_array($info) ? ($info['version'] ?? $info['nmsVersion'] ?? null) : null;
        return ['ok' => true, 'message' => 'Connected to UISP.', 'version' => $version];
    } catch (Throwable $e1) {
        try {
            uisp_request('GET', '/nms/api/v2.1/sites');
            return ['ok' => true, 'message' => 'Connected (could not detect version).', 'version' => null];
        } catch (Throwable $e2) {
            return ['ok' => false, 'message' => $e1->getMessage(), 'version' => null];
        }
    }
}

/* --------------------------------------------------------- live fetchers */

function uisp_sites_fetch(): array {
    $r = uisp_request('GET', '/nms/api/v2.1/sites');
    return is_array($r) ? $r : [];
}
function uisp_devices_fetch(): array {
    $r = uisp_request('GET', '/nms/api/v2.1/devices');
    return is_array($r) ? $r : [];
}
function uisp_data_links_fetch(): array {
    $r = uisp_request('GET', '/nms/api/v2.1/data-links');
    return is_array($r) ? $r : [];
}
function uisp_clients_fetch(): array {
    $r = uisp_request('GET', '/crm/api/v1.0/clients');
    return is_array($r) ? $r : [];
}
function uisp_services_fetch(): array {
    $r = uisp_request('GET', '/crm/api/v1.0/clients/services');
    return is_array($r) ? $r : [];
}

/* --------------------------------------------------------- cache readers
 *
 * Defensive: if the uisp_* migration hasn't been applied yet, return [].
 * Callers should still work — the map will simply render no UISP overlay.
 */

function uisp_cache_query(string $sql): array {
    try {
        return pdo()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('UISP cache query failed: ' . $e->getMessage());
        return [];
    }
}

function uisp_sites_cached(bool $include_stale = false): array {
    $sql = "SELECT * FROM uisp_sites";
    if (!$include_stale) $sql .= " WHERE is_stale = 0";
    $sql .= " ORDER BY name ASC";
    return uisp_cache_query($sql);
}
function uisp_devices_cached(bool $include_stale = false): array {
    $sql = "SELECT * FROM uisp_devices";
    if (!$include_stale) $sql .= " WHERE is_stale = 0";
    $sql .= " ORDER BY name ASC";
    return uisp_cache_query($sql);
}
function uisp_data_links_cached(bool $include_stale = false): array {
    $sql = "SELECT * FROM uisp_data_links";
    if (!$include_stale) $sql .= " WHERE is_stale = 0";
    return uisp_cache_query($sql);
}
function uisp_clients_cached(bool $include_stale = false): array {
    $sql = "SELECT * FROM uisp_clients";
    if (!$include_stale) $sql .= " WHERE is_stale = 0";
    $sql .= " ORDER BY name ASC";
    return uisp_cache_query($sql);
}

/* --------------------------------------------------------- sync timing */

function uisp_last_sync_age_seconds(): ?int {
    $cfg = uisp_config();
    if (empty($cfg['last_sync_at'])) return null;
    $ts = strtotime((string)$cfg['last_sync_at']);
    return $ts ? max(0, time() - $ts) : null;
}

function uisp_should_auto_sync(): bool {
    if (!uisp_is_configured()) return false;
    $cfg = uisp_config();
    $interval = max(1, (int)($cfg['sync_interval_minutes'] ?? UISP_DEFAULT_INTERVAL));
    $age = uisp_last_sync_age_seconds();
    if ($age === null) return true;
    return $age >= $interval * 60;
}
