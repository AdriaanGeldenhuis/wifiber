<?php
/**
 * Polling-freshness helper used by every dashboard to answer the
 * single question "is the data we're showing actually live?".
 *
 * The dashboards (links / sectors / devices / clients) read from
 * the four telemetry tables populated by bin/poll-wireless.php and
 * bin/poll-devices.php:
 *
 *   • device_health         (CPU, mem, RTT, status)
 *   • link_health_samples   (signal, noise, SNR, CCQ, rates, throughput)
 *   • rf_environment_samples(per-frequency RSSI scan)
 *   • ethernet_health       (cable diag, LAN speed, duplex)
 *
 * If the cron is broken or no device has working credentials, every
 * dashboard would show a sea of "—" without telling the operator
 * why. This module's job is to surface that distinction explicitly.
 *
 * The classifier returns one of four states based on sample age:
 *
 *   live   — sample within $stale_after_s seconds (default 3 min)
 *   stale  — older but within $dead_after_s seconds (default 15 min)
 *   dead   — older than $dead_after_s
 *   never  — no sample on record at all
 *
 * Companion JS: /assets/js/dashboard-live.js animates the "Xs ago"
 * suffix in real time and reloads the page on a configurable cadence.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/* Lock file used by bin/poll-wireless.php — used to tell whether the
 * cron is mid-run versus stuck, when no other signal is available. */
const POLL_WIRELESS_LOCKFILE = __DIR__ . '/../data/poll-wireless.lock';
const POLL_DEVICES_LOCKFILE  = __DIR__ . '/../data/poll-devices.lock';

/** Resolve a string timestamp like 'YYYY-MM-DD HH:MM:SS' to an age in seconds. */
function poll_age_seconds(?string $iso): ?int {
    if (!$iso) return null;
    $t = strtotime($iso);
    if ($t === false) return null;
    return max(0, time() - $t);
}

/** Pretty-print a duration as "Xs", "Xm", "Xh", "Xd". */
function poll_age_human(?int $seconds): string {
    if ($seconds === null)   return 'never';
    if ($seconds < 60)       return $seconds . 's ago';
    if ($seconds < 3600)     return intdiv($seconds, 60)    . 'm ago';
    if ($seconds < 86400)    return intdiv($seconds, 3600)  . 'h ago';
    return intdiv($seconds, 86400) . 'd ago';
}

/**
 * Classify a sample timestamp into live / stale / dead / never.
 * Returns an array with ISO timestamp, age, human age, state, colour.
 */
function poll_classify(?string $iso, int $stale_after_s = 180, int $dead_after_s = 900): array {
    $age = poll_age_seconds($iso);
    if ($age === null) {
        $state = 'never';
    } elseif ($age <= $stale_after_s) {
        $state = 'live';
    } elseif ($age <= $dead_after_s) {
        $state = 'stale';
    } else {
        $state = 'dead';
    }
    $colours = [
        'live'  => ['bg' => '#4ade80', 'fg' => '#001218', 'label' => 'Live'],
        'stale' => ['bg' => '#e8a814', 'fg' => '#001218', 'label' => 'Stale'],
        'dead'  => ['bg' => '#ff5470', 'fg' => '#001218', 'label' => 'Polling stopped'],
        'never' => ['bg' => '#6b7480', 'fg' => '#f4f6f8', 'label' => 'No data yet'],
    ];
    return [
        'iso'    => $iso,
        'age_s'  => $age,
        'human'  => poll_age_human($age),
        'state'  => $state,
        'bg'     => $colours[$state]['bg'],
        'fg'     => $colours[$state]['fg'],
        'label'  => $colours[$state]['label'],
        'stale_after_s' => $stale_after_s,
        'dead_after_s'  => $dead_after_s,
    ];
}

/**
 * Render the small pill badge that goes next to a dashboard heading.
 * The badge carries data-* attributes so /assets/js/dashboard-live.js
 * can tick the "Xs ago" suffix every second without a server round-trip.
 */
function poll_badge_html(array $status, string $title_prefix = 'Last sample'): string {
    $iso   = $status['iso'] ?? '';
    $state = $status['state'];
    $tip = $iso
        ? $title_prefix . ': ' . htmlspecialchars($iso, ENT_QUOTES)
        : $title_prefix . ': none on record';
    return sprintf(
        '<span class="poll-badge poll-badge-%s" '
        . 'data-poll-fresh-at="%s" data-poll-stale-after="%d" data-poll-dead-after="%d" '
        . 'title="%s" '
        . 'style="display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:10px;'
        . 'background:%s;color:%s;font-size:11px;font-weight:600;letter-spacing:.02em;'
        . 'vertical-align:middle;line-height:1.6;">'
        . '<span class="poll-dot" style="display:inline-block;width:6px;height:6px;border-radius:50%%;'
        . 'background:currentColor;opacity:.65;"></span>'
        . '<span class="poll-label">%s</span>'
        . '<span class="poll-age" style="opacity:.85;">· %s</span>'
        . '</span>',
        htmlspecialchars($state, ENT_QUOTES),
        htmlspecialchars((string)$iso, ENT_QUOTES),
        (int)$status['stale_after_s'],
        (int)$status['dead_after_s'],
        $tip,
        htmlspecialchars($status['bg'], ENT_QUOTES),
        htmlspecialchars($status['fg'], ENT_QUOTES),
        htmlspecialchars($status['label'], ENT_QUOTES),
        htmlspecialchars($status['human'], ENT_QUOTES)
    );
}

/** Latest sample row across the device_health table. */
function poll_latest_device_health_at(): ?string {
    try {
        $row = pdo()->query("SELECT MAX(polled_at) AS t FROM device_health")->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Latest sample across all wireless link telemetry. */
function poll_latest_link_sample_at(): ?string {
    try {
        $row = pdo()->query("SELECT MAX(polled_at) AS t FROM link_health_samples")->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Latest RF-environment scan. */
function poll_latest_rf_sample_at(): ?string {
    try {
        $row = pdo()->query("SELECT MAX(polled_at) AS t FROM rf_environment_samples")->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Latest ethernet_health sample. */
function poll_latest_ethernet_sample_at(): ?string {
    try {
        $row = pdo()->query("SELECT MAX(polled_at) AS t FROM ethernet_health")->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Most recent link sample for a single wireless_links row. */
function poll_link_latest_at(int $link_id): ?string {
    try {
        $st = pdo()->prepare("SELECT MAX(polled_at) AS t FROM link_health_samples WHERE link_id = ?");
        $st->execute([$link_id]);
        $row = $st->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Most recent device_health sample for a single device. */
function poll_device_latest_at(int $device_id): ?string {
    try {
        $st = pdo()->prepare("SELECT MAX(polled_at) AS t FROM device_health WHERE device_id = ?");
        $st->execute([$device_id]);
        $row = $st->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Most recent sample tied to a sector — taken from the AP's link telemetry. */
function poll_sector_latest_at(int $sector_id): ?string {
    try {
        $st = pdo()->prepare(
            "SELECT MAX(lhs.polled_at) AS t
               FROM link_health_samples lhs
               JOIN wireless_links wl ON wl.id = lhs.link_id
              WHERE wl.sector_id = ?"
        );
        $st->execute([$sector_id]);
        $row = $st->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/** Most recent sample tied to a customer — across all of their links. */
function poll_customer_latest_at(int $customer_id): ?string {
    try {
        $st = pdo()->prepare(
            "SELECT MAX(lhs.polled_at) AS t
               FROM link_health_samples lhs
               JOIN wireless_links wl ON wl.id = lhs.link_id
              WHERE wl.customer_id = ?"
        );
        $st->execute([$customer_id]);
        $row = $st->fetch();
        return $row && $row['t'] ? (string)$row['t'] : null;
    } catch (Throwable $e) { return null; }
}

/**
 * Lock-file inspection — the poll cron creates a flock on these files
 * while it runs. If the file exists and was touched recently, polling
 * is in progress. If it's stale (>10 min old) something probably
 * crashed mid-run.
 */
function poll_lockfile_state(string $path): array {
    if (!is_file($path)) {
        return ['exists' => false, 'mtime' => null, 'age_s' => null];
    }
    $mtime = @filemtime($path) ?: null;
    return [
        'exists' => true,
        'mtime'  => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
        'age_s'  => $mtime ? max(0, time() - $mtime) : null,
    ];
}

/**
 * Per-vendor poll counts and last-polled timestamps in the last hour.
 * Used by /admin/diagnostics.php to show "ubiquiti: 12 polls in 60m".
 */
function poll_vendor_breakdown(int $minutes = 60): array {
    try {
        $st = pdo()->prepare(
            "SELECT d.vendor,
                    COUNT(dh.id) AS polls,
                    MAX(dh.polled_at) AS last_polled,
                    SUM(CASE WHEN dh.status = 'online'  THEN 1 ELSE 0 END) AS online_polls,
                    SUM(CASE WHEN dh.status = 'offline' THEN 1 ELSE 0 END) AS offline_polls
               FROM devices d
          LEFT JOIN device_health dh
                 ON dh.device_id = d.id
                AND dh.polled_at >= (NOW() - INTERVAL ? MINUTE)
              WHERE d.status != 'retired'
           GROUP BY d.vendor
           ORDER BY polls DESC, d.vendor ASC"
        );
        $st->execute([$minutes]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Devices whose credential rows have hit consecutive-fail threshold —
 * these are blocking poll-wireless.php from getting telemetry.
 */
function poll_failing_credentials(int $threshold = 3): array {
    try {
        $st = pdo()->prepare(
            "SELECT dc.id AS cred_id, dc.scheme, dc.consecutive_fails,
                    dc.last_auth_ok_at, dc.last_auth_error AS last_auth_err,
                    d.id AS device_id, d.name, d.vendor, d.model, d.mgmt_ip
               FROM device_credentials dc
               JOIN devices d ON d.id = dc.device_id
              WHERE dc.consecutive_fails >= ?
                AND d.status != 'retired'
           ORDER BY dc.consecutive_fails DESC, d.name ASC"
        );
        $st->execute([$threshold]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Devices that should be polled (have credentials + mgmt_ip + supported
 * vendor) but haven't produced a device_health row in the last hour.
 */
function poll_silent_devices(int $minutes = 60): array {
    try {
        $st = pdo()->prepare(
            "SELECT d.id, d.name, d.vendor, d.model, d.mgmt_ip,
                    MAX(dh.polled_at) AS last_polled
               FROM devices d
               JOIN device_credentials dc ON dc.device_id = d.id
          LEFT JOIN device_health dh ON dh.device_id = d.id
              WHERE d.status != 'retired'
                AND d.vendor IN ('ubiquiti','mikrotik','cambium','mimosa')
                AND d.mgmt_ip <> ''
           GROUP BY d.id
             HAVING last_polled IS NULL
                 OR last_polled < (NOW() - INTERVAL ? MINUTE)
           ORDER BY d.name ASC"
        );
        $st->execute([$minutes]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}
