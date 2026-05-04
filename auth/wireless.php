<?php
/**
 * Wireless link helpers — wireless_links, sectors radio config,
 * device_credentials, link_health_samples, rf_environment_samples,
 * ethernet_health, wireless_change_jobs, wireless_change_log.
 *
 * Read by: bin/poll-wireless.php, bin/apply-wireless-changes.php,
 *          admin/links.php, admin/link-view.php, admin/sector-edit.php,
 *          admin/freq-planner.php, admin/devices.php (credentials modal).
 *
 * Vendor-specific I/O is in auth/vendors/*.php — this file is pure
 * database + scoring + math.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const WL_MODES        = ['802.11n', '802.11ac', '802.11ax', 'airmax_ac', 'airmax_n', 'other'];
const WL_SECURITIES   = ['open', 'wep', 'wpa', 'wpa2', 'wpa3', 'other'];
const CRED_SCHEMES    = ['http', 'https', 'ssh', 'snmpv2', 'snmpv3', 'api'];
const CHANGE_STATUSES = ['queued', 'applying', 'applied', 'failed', 'rolled_back', 'cancelled'];
const CHANGE_SCOPES   = ['sector', 'link', 'device'];

/* --------------------------------------------------------- wireless_links */

function wireless_link_normalise(array $r): array {
    $r['id']            = (int)$r['id'];
    $r['ap_device_id']  = (int)$r['ap_device_id'];
    $r['cpe_device_id'] = $r['cpe_device_id'] !== null ? (int)$r['cpe_device_id'] : null;
    $r['sector_id']     = $r['sector_id']     !== null ? (int)$r['sector_id']     : null;
    $r['customer_id']   = $r['customer_id']   !== null ? (int)$r['customer_id']   : null;
    $r['health_score']  = $r['health_score']  !== null ? (int)$r['health_score']  : null;
    return $r;
}

function wireless_links_all(?array $filters = null): array {
    $sql = "SELECT wl.*,
                   ap.name  AS ap_name,  ap.vendor AS ap_vendor,  ap.model AS ap_model,
                   cpe.name AS cpe_name, cpe.vendor AS cpe_vendor, cpe.model AS cpe_model,
                   s.name AS sector_name,
                   u.name AS customer_name, u.surname AS customer_surname,
                   (SELECT COUNT(*) FROM link_alerts la
                     WHERE la.link_id = wl.id AND la.resolved_at IS NULL) AS active_alerts
              FROM wireless_links wl
              JOIN devices ap        ON ap.id = wl.ap_device_id
              LEFT JOIN devices cpe  ON cpe.id = wl.cpe_device_id
              LEFT JOIN sectors s    ON s.id = wl.sector_id
              LEFT JOIN users u      ON u.id = wl.customer_id";
    $where = [];
    $args  = [];
    $f = $filters ?? [];

    if (!empty($f['sector_id']))   { $where[] = 'wl.sector_id = ?';   $args[] = (int)$f['sector_id']; }
    if (!empty($f['ap_device_id'])){ $where[] = 'wl.ap_device_id = ?'; $args[] = (int)$f['ap_device_id']; }
    if (!empty($f['customer_id'])) { $where[] = 'wl.customer_id = ?'; $args[] = (int)$f['customer_id']; }
    if (isset($f['health_max']) && $f['health_max'] !== '') {
        $where[] = '(wl.health_score IS NULL OR wl.health_score <= ?)';
        $args[]  = (int)$f['health_max'];
    }
    if (!empty($f['search'])) {
        $like = '%' . $f['search'] . '%';
        $where[] = '(ap.name LIKE ? OR cpe.name LIKE ? OR wl.ssid LIKE ? OR u.name LIKE ? OR u.surname LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY (wl.health_score IS NULL), wl.health_score ASC, wl.id DESC';

    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = wireless_link_normalise($r);
    return $rows;
}

function wireless_link_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare(
        "SELECT wl.*,
                ap.name  AS ap_name,  ap.vendor AS ap_vendor,  ap.model AS ap_model,
                ap.firmware AS ap_firmware, ap.site_id AS ap_site_id,
                cpe.name AS cpe_name, cpe.vendor AS cpe_vendor, cpe.model AS cpe_model,
                cpe.firmware AS cpe_firmware, cpe.site_id AS cpe_site_id,
                s.name AS sector_name, s.frequency_mhz AS sector_freq, s.channel_width_mhz AS sector_width,
                u.name AS customer_name, u.surname AS customer_surname
           FROM wireless_links wl
           JOIN devices ap       ON ap.id = wl.ap_device_id
           LEFT JOIN devices cpe ON cpe.id = wl.cpe_device_id
           LEFT JOIN sectors s   ON s.id = wl.sector_id
           LEFT JOIN users u     ON u.id = wl.customer_id
          WHERE wl.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? wireless_link_normalise($row) : null;
}

function wireless_link_save(array $data, ?int $id = null): int {
    $args = [
        'ap_device_id'  => (int)($data['ap_device_id']  ?? 0),
        'cpe_device_id' => !empty($data['cpe_device_id']) ? (int)$data['cpe_device_id'] : null,
        'sector_id'     => !empty($data['sector_id'])    ? (int)$data['sector_id']      : null,
        'customer_id'   => !empty($data['customer_id'])  ? (int)$data['customer_id']    : null,
        'ssid'          => trim((string)($data['ssid'] ?? '')),
        'ap_mac'        => strtoupper(trim((string)($data['ap_mac']      ?? ''))),
        'station_mac'   => strtoupper(trim((string)($data['station_mac'] ?? ''))),
    ];
    if ($args['ap_device_id'] <= 0) {
        throw new InvalidArgumentException('AP device is required.');
    }

    if ($id) {
        pdo()->prepare(
            "UPDATE wireless_links
                SET ap_device_id=?, cpe_device_id=?, sector_id=?, customer_id=?,
                    ssid=?, ap_mac=?, station_mac=?
              WHERE id=?"
        )->execute([
            $args['ap_device_id'], $args['cpe_device_id'], $args['sector_id'], $args['customer_id'],
            $args['ssid'], $args['ap_mac'], $args['station_mac'], $id,
        ]);
        return $id;
    }

    pdo()->prepare(
        "INSERT INTO wireless_links
            (ap_device_id, cpe_device_id, sector_id, customer_id, ssid, ap_mac, station_mac)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $args['ap_device_id'], $args['cpe_device_id'], $args['sector_id'], $args['customer_id'],
        $args['ssid'], $args['ap_mac'], $args['station_mac'],
    ]);
    return (int)pdo()->lastInsertId();
}

function wireless_link_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM wireless_links WHERE id = ?")->execute([$id]);
}

/**
 * Upsert by (ap_device_id, cpe_device_id) — used by the polling worker
 * when a vendor adapter discovers a station that doesn't have a
 * wireless_links row yet. Returns the link id.
 */
function wireless_link_upsert(int $ap_id, int $cpe_id, array $extra = []): int {
    if ($ap_id <= 0 || $cpe_id <= 0) {
        throw new InvalidArgumentException('Both AP and CPE device ids are required.');
    }
    $stmt = pdo()->prepare(
        "SELECT id FROM wireless_links WHERE ap_device_id = ? AND cpe_device_id = ? LIMIT 1"
    );
    $stmt->execute([$ap_id, $cpe_id]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    return wireless_link_save(array_merge($extra, [
        'ap_device_id'  => $ap_id,
        'cpe_device_id' => $cpe_id,
    ]));
}

/**
 * Replace the "current state" columns on wireless_links with the latest
 * sample. Pair this with a fresh row in link_health_samples for history.
 */
function wireless_link_record_sample(int $link_id, array $s): void {
    if ($link_id <= 0) return;

    pdo()->prepare(
        "UPDATE wireless_links
            SET signal_dbm        = COALESCE(?, signal_dbm),
                signal_dbm_remote = COALESCE(?, signal_dbm_remote),
                noise_dbm         = COALESCE(?, noise_dbm),
                noise_dbm_remote  = COALESCE(?, noise_dbm_remote),
                snr_db            = COALESCE(?, snr_db),
                snr_db_remote     = COALESCE(?, snr_db_remote),
                ccq_pct           = COALESCE(?, ccq_pct),
                tx_rate_mbps      = COALESCE(?, tx_rate_mbps),
                rx_rate_mbps      = COALESCE(?, rx_rate_mbps),
                airtime_local_pct  = COALESCE(?, airtime_local_pct),
                airtime_remote_pct = COALESCE(?, airtime_remote_pct),
                throughput_local_mbps  = COALESCE(?, throughput_local_mbps),
                throughput_remote_mbps = COALESCE(?, throughput_remote_mbps),
                capacity_local_mbps    = COALESCE(?, capacity_local_mbps),
                capacity_remote_mbps   = COALESCE(?, capacity_remote_mbps),
                tx_power_dbm_local  = COALESCE(?, tx_power_dbm_local),
                tx_power_dbm_remote = COALESCE(?, tx_power_dbm_remote),
                frequency_mhz       = COALESCE(?, frequency_mhz),
                channel_width_mhz   = COALESCE(?, channel_width_mhz),
                expected_rate_mbps  = COALESCE(?, expected_rate_mbps),
                modulation          = COALESCE(NULLIF(?, ''), modulation),
                wireless_mode       = COALESCE(NULLIF(?, ''), wireless_mode),
                ap_mac              = COALESCE(NULLIF(?, ''), ap_mac),
                station_mac         = COALESCE(NULLIF(?, ''), station_mac),
                uptime_seconds      = COALESCE(?, uptime_seconds),
                tx_bytes            = COALESCE(?, tx_bytes),
                rx_bytes            = COALESCE(?, rx_bytes),
                distance_km         = COALESCE(?, distance_km),
                health_score        = ?,
                last_evaluated_at   = NOW()
          WHERE id = ?"
    )->execute([
        $s['signal_local_dbm']    ?? null,
        $s['signal_remote_dbm']   ?? null,
        $s['noise_local_dbm']     ?? null,
        $s['noise_remote_dbm']    ?? null,
        $s['snr_local_db']        ?? null,
        $s['snr_remote_db']       ?? null,
        $s['ccq_pct']             ?? null,
        $s['tx_rate_mbps']        ?? null,
        $s['rx_rate_mbps']        ?? null,
        $s['airtime_local_pct']   ?? null,
        $s['airtime_remote_pct']  ?? null,
        $s['throughput_local_mbps']  ?? null,
        $s['throughput_remote_mbps'] ?? null,
        $s['capacity_local_mbps']    ?? null,
        $s['capacity_remote_mbps']   ?? null,
        $s['tx_power_dbm_local']  ?? null,
        $s['tx_power_dbm_remote'] ?? null,
        $s['frequency_mhz']       ?? null,
        $s['channel_width_mhz']   ?? null,
        $s['expected_rate_mbps']  ?? null,
        (string)($s['modulation']    ?? ''),
        (string)($s['wireless_mode'] ?? ''),
        (string)($s['ap_mac']        ?? ''),
        (string)($s['station_mac']   ?? ''),
        $s['uptime_seconds']      ?? null,
        $s['tx_bytes']            ?? null,
        $s['rx_bytes']            ?? null,
        $s['distance_km']         ?? null,
        wireless_link_score_health($s),
        $link_id,
    ]);

    pdo()->prepare(
        "INSERT INTO link_health_samples
            (link_id, signal_local_dbm, signal_remote_dbm,
             noise_local_dbm, noise_remote_dbm, snr_local_db, snr_remote_db,
             ccq_pct, tx_rate_mbps, rx_rate_mbps,
             airtime_local_pct, airtime_remote_pct,
             throughput_local_mbps, throughput_remote_mbps,
             capacity_local_mbps, capacity_remote_mbps)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $link_id,
        $s['signal_local_dbm']     ?? null,
        $s['signal_remote_dbm']    ?? null,
        $s['noise_local_dbm']      ?? null,
        $s['noise_remote_dbm']     ?? null,
        $s['snr_local_db']         ?? null,
        $s['snr_remote_db']        ?? null,
        $s['ccq_pct']              ?? null,
        $s['tx_rate_mbps']         ?? null,
        $s['rx_rate_mbps']         ?? null,
        $s['airtime_local_pct']    ?? null,
        $s['airtime_remote_pct']   ?? null,
        $s['throughput_local_mbps']  ?? null,
        $s['throughput_remote_mbps'] ?? null,
        $s['capacity_local_mbps']    ?? null,
        $s['capacity_remote_mbps']   ?? null,
    ]);
}

/**
 * Score a link 0-100 from the freshest sample. Heuristic, mirrors the
 * green/yellow/red pills in admin/links.php and the customer dashboard:
 *
 *   SNR              ≥30 dB → 40 / ≥20 → 25 / ≥15 → 15 / else → 0
 *   CCQ              ≥90 % → 25 / ≥70 → 15 / ≥50 → 8 / else → 0
 *   Airtime headroom <50 % → 20 / <70 → 12 / <85 → 6 / else → 0
 *   Capacity reached <60 % → 15 / <80 → 10 / <90 → 5 / else → 0
 */
function wireless_link_score_health(array $s): int {
    $score = 0;

    $snr = $s['snr_local_db'] ?? $s['snr_remote_db'] ?? null;
    if ($snr !== null) {
        if      ($snr >= 30) $score += 40;
        elseif  ($snr >= 20) $score += 25;
        elseif  ($snr >= 15) $score += 15;
    }

    $ccq = $s['ccq_pct'] ?? null;
    if ($ccq !== null) {
        if      ($ccq >= 90) $score += 25;
        elseif  ($ccq >= 70) $score += 15;
        elseif  ($ccq >= 50) $score += 8;
    } else {
        $score += 12; // neutral if vendor doesn't report CCQ
    }

    $air = max((float)($s['airtime_local_pct'] ?? 0), (float)($s['airtime_remote_pct'] ?? 0));
    if      ($air < 50) $score += 20;
    elseif  ($air < 70) $score += 12;
    elseif  ($air < 85) $score += 6;

    $tput = max((float)($s['throughput_local_mbps'] ?? 0), (float)($s['throughput_remote_mbps'] ?? 0));
    $cap  = max((float)($s['capacity_local_mbps']    ?? 0), (float)($s['capacity_remote_mbps']    ?? 0));
    if ($cap > 0) {
        $util = $tput / $cap;
        if      ($util < 0.6) $score += 15;
        elseif  ($util < 0.8) $score += 10;
        elseif  ($util < 0.9) $score += 5;
    } else {
        $score += 8;
    }

    return max(0, min(100, $score));
}

function wireless_link_recent_samples(int $link_id, int $limit = 288): array {
    if ($link_id <= 0) return [];
    $limit = max(1, min(2000, $limit));
    $stmt = pdo()->prepare(
        "SELECT * FROM link_health_samples
          WHERE link_id = ?
          ORDER BY polled_at DESC, id DESC
          LIMIT $limit"
    );
    $stmt->execute([$link_id]);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------ rf samples */

function rf_environment_record(int $device_id, array $rows): int {
    if ($device_id <= 0 || !$rows) return 0;
    $stmt = pdo()->prepare(
        "INSERT INTO rf_environment_samples (device_id, freq_mhz, rssi_dbm) VALUES (?, ?, ?)"
    );
    $n = 0;
    foreach ($rows as $r) {
        $f = (int)($r['freq_mhz'] ?? 0);
        if ($f <= 0) continue;
        $rssi = (int)($r['rssi_dbm'] ?? -100);
        $stmt->execute([$device_id, $f, $rssi]);
        $n++;
    }
    return $n;
}

function rf_environment_recent(int $device_id, int $minutes = 60): array {
    if ($device_id <= 0) return [];
    $minutes = max(1, min(1440, $minutes));
    $stmt = pdo()->prepare(
        "SELECT freq_mhz, MAX(rssi_dbm) AS rssi_dbm
           FROM rf_environment_samples
          WHERE device_id = ? AND polled_at >= (NOW() - INTERVAL ? MINUTE)
          GROUP BY freq_mhz
          ORDER BY freq_mhz ASC"
    );
    $stmt->execute([$device_id, $minutes]);
    return $stmt->fetchAll();
}

function rf_environment_cleanup(int $retention_hours = 48): int {
    $retention_hours = max(1, min(8760, $retention_hours));
    $stmt = pdo()->prepare(
        "DELETE FROM rf_environment_samples WHERE polled_at < (NOW() - INTERVAL ? HOUR)"
    );
    $stmt->execute([$retention_hours]);
    return $stmt->rowCount();
}

/* ----------------------------------------------------- ethernet diag */

function ethernet_health_record(int $device_id, array $s): void {
    if ($device_id <= 0) return;
    pdo()->prepare(
        "INSERT INTO ethernet_health
            (device_id, lan_port, link_speed_mbps, duplex,
             cable_length_m, cable_snr_db,
             pair_a_status, pair_b_status, pair_c_status, pair_d_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $device_id,
        (string)($s['lan_port']        ?? 'lan0'),
        $s['link_speed_mbps'] ?? null,
        (string)($s['duplex']          ?? 'unknown'),
        $s['cable_length_m'] ?? null,
        $s['cable_snr_db']   ?? null,
        (string)($s['pair_a_status']   ?? 'unknown'),
        (string)($s['pair_b_status']   ?? 'unknown'),
        (string)($s['pair_c_status']   ?? 'unknown'),
        (string)($s['pair_d_status']   ?? 'unknown'),
    ]);

    pdo()->prepare(
        "UPDATE devices
            SET lan_speed_mbps = COALESCE(?, lan_speed_mbps),
                cable_length_m = COALESCE(?, cable_length_m),
                cable_snr_db   = COALESCE(?, cable_snr_db)
          WHERE id = ?"
    )->execute([
        $s['link_speed_mbps'] ?? null,
        $s['cable_length_m']  ?? null,
        $s['cable_snr_db']    ?? null,
        $device_id,
    ]);
}

function ethernet_health_latest(int $device_id): ?array {
    if ($device_id <= 0) return null;
    $stmt = pdo()->prepare(
        "SELECT * FROM ethernet_health
          WHERE device_id = ?
          ORDER BY polled_at DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute([$device_id]);
    return $stmt->fetch() ?: null;
}

/* ------------------------------------------------- device_credentials */

function device_credentials_for(int $device_id, ?string $scheme = null): array {
    if ($device_id <= 0) return [];
    if ($scheme !== null) {
        $stmt = pdo()->prepare(
            "SELECT * FROM device_credentials WHERE device_id = ? AND scheme = ? LIMIT 1"
        );
        $stmt->execute([$device_id, $scheme]);
        $row = $stmt->fetch();
        return $row ? [$row] : [];
    }
    $stmt = pdo()->prepare(
        "SELECT * FROM device_credentials WHERE device_id = ? ORDER BY scheme ASC"
    );
    $stmt->execute([$device_id]);
    return $stmt->fetchAll();
}

/**
 * Save (insert or update) a device credential. Plain-text `password`,
 * `ssh_key`, `snmp_community` and `api_token` are encrypted via
 * sodium_secretbox before write. Empty values leave existing ciphertext
 * untouched (so editing the username doesn't blow away the password).
 */
function device_credentials_save(int $device_id, string $scheme, array $secrets, array $meta = []): int {
    if ($device_id <= 0) throw new InvalidArgumentException('device_id required');
    if (!in_array($scheme, CRED_SCHEMES, true)) {
        throw new InvalidArgumentException("Unknown credential scheme: $scheme");
    }

    $existing = device_credentials_for($device_id, $scheme);
    $row = $existing[0] ?? null;

    $username   = trim((string)($meta['username']   ?? $row['username']    ?? ''));
    $port       = isset($meta['port']) && is_numeric($meta['port'])
        ? max(1, min(65535, (int)$meta['port']))
        : ($row['port'] ?? null);
    $verify_tls = array_key_exists('verify_tls', $meta) ? ($meta['verify_tls'] ? 1 : 0)
        : (int)($row['verify_tls'] ?? 0);
    $notes      = trim((string)($meta['notes'] ?? $row['notes'] ?? ''));

    $pw_plain    = isset($secrets['password'])       ? (string)$secrets['password']       : '';
    $key_plain   = isset($secrets['ssh_key'])        ? (string)$secrets['ssh_key']        : '';
    $comm_plain  = isset($secrets['snmp_community']) ? (string)$secrets['snmp_community'] : '';
    $token_plain = isset($secrets['api_token'])      ? (string)$secrets['api_token']      : '';

    $pw_enc    = $pw_plain    !== '' ? encrypt_secret($pw_plain)    : ($row['password_enc']       ?? null);
    $key_enc   = $key_plain   !== '' ? encrypt_secret($key_plain)   : ($row['ssh_key_enc']        ?? null);
    $comm_enc  = $comm_plain  !== '' ? encrypt_secret($comm_plain)  : ($row['snmp_community_enc'] ?? null);
    $token_enc = $token_plain !== '' ? encrypt_secret($token_plain) : ($row['api_token_enc']      ?? null);

    if ($row) {
        pdo()->prepare(
            "UPDATE device_credentials
                SET username=?, password_enc=?, ssh_key_enc=?, snmp_community_enc=?,
                    api_token_enc=?, port=?, verify_tls=?, notes=?
              WHERE id=?"
        )->execute([
            $username, $pw_enc, $key_enc, $comm_enc, $token_enc,
            $port, $verify_tls, $notes, (int)$row['id'],
        ]);
        return (int)$row['id'];
    }

    pdo()->prepare(
        "INSERT INTO device_credentials
            (device_id, scheme, username, password_enc, ssh_key_enc,
             snmp_community_enc, api_token_enc, port, verify_tls, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $device_id, $scheme, $username, $pw_enc, $key_enc,
        $comm_enc, $token_enc, $port, $verify_tls, $notes,
    ]);
    return (int)pdo()->lastInsertId();
}

function device_credentials_delete(int $cred_id): bool {
    return pdo()->prepare("DELETE FROM device_credentials WHERE id = ?")->execute([$cred_id]);
}

function device_credentials_record_attempt(int $cred_id, bool $ok, string $error = ''): void {
    if ($cred_id <= 0) return;
    if ($ok) {
        pdo()->prepare(
            "UPDATE device_credentials
                SET last_auth_ok_at = NOW(), consecutive_fails = 0, last_auth_error = ''
              WHERE id = ?"
        )->execute([$cred_id]);
    } else {
        pdo()->prepare(
            "UPDATE device_credentials
                SET consecutive_fails = consecutive_fails + 1,
                    last_auth_error = ?
              WHERE id = ?"
        )->execute([substr($error, 0, 255), $cred_id]);
    }
}

/**
 * Decrypt a credential row into plaintext for use by a vendor adapter.
 * Returns ['username','password','ssh_key','snmp_community','api_token',
 *          'port','verify_tls','scheme','id'] or null.
 */
function device_credentials_unlock(array $row): ?array {
    if (!$row || empty($row['device_id'])) return null;
    return [
        'id'             => (int)$row['id'],
        'device_id'      => (int)$row['device_id'],
        'scheme'         => (string)$row['scheme'],
        'username'       => (string)$row['username'],
        'password'       => decrypt_secret($row['password_enc']       ?? null) ?? '',
        'ssh_key'        => decrypt_secret($row['ssh_key_enc']        ?? null) ?? '',
        'snmp_community' => decrypt_secret($row['snmp_community_enc'] ?? null) ?? '',
        'api_token'      => decrypt_secret($row['api_token_enc']      ?? null) ?? '',
        'port'           => $row['port'] !== null ? (int)$row['port'] : null,
        'verify_tls'     => !empty($row['verify_tls']),
    ];
}

/* ------------------------------------------------- change-job queue */

function wireless_change_job_enqueue(string $scope, int $scope_id, int $requested_by, array $payload, ?string $scheduled_for = null): int {
    if (!in_array($scope, CHANGE_SCOPES, true)) {
        throw new InvalidArgumentException("Unknown change scope: $scope");
    }
    if ($scope_id <= 0) throw new InvalidArgumentException('scope_id required');
    pdo()->prepare(
        "INSERT INTO wireless_change_jobs (scope, scope_id, requested_by, payload_json, scheduled_for)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $scope, $scope_id, $requested_by ?: null,
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        $scheduled_for !== null && $scheduled_for !== '' ? $scheduled_for : null,
    ]);
    return (int)pdo()->lastInsertId();
}

function wireless_change_job_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM wireless_change_jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function wireless_change_jobs_pending(int $limit = 20): array {
    $limit = max(1, min(200, $limit));
    $stmt = pdo()->prepare(
        "SELECT * FROM wireless_change_jobs
          WHERE status = 'queued'
            AND (scheduled_for IS NULL OR scheduled_for <= NOW())
          ORDER BY created_at ASC, id ASC
          LIMIT $limit"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function wireless_change_jobs_recent(?array $filters = null, int $limit = 50): array {
    $sql = "SELECT j.*, u.name AS requester_name
              FROM wireless_change_jobs j
              LEFT JOIN users u ON u.id = j.requested_by";
    $where = [];
    $args  = [];
    $f = $filters ?? [];
    if (!empty($f['status']) && in_array($f['status'], CHANGE_STATUSES, true)) {
        $where[] = 'j.status = ?'; $args[] = $f['status'];
    }
    if (!empty($f['scope']) && in_array($f['scope'], CHANGE_SCOPES, true)) {
        $where[] = 'j.scope = ?'; $args[] = $f['scope'];
    }
    if (!empty($f['scope_id'])) { $where[] = 'j.scope_id = ?'; $args[] = (int)$f['scope_id']; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $limit = max(1, min(500, $limit));
    $sql .= " ORDER BY j.created_at DESC LIMIT $limit";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function wireless_change_job_mark(int $id, string $status, array $patch = []): void {
    if (!in_array($status, CHANGE_STATUSES, true)) {
        throw new InvalidArgumentException("Unknown status: $status");
    }
    $sets = ['status = ?'];
    $args = [$status];
    if (array_key_exists('snapshot_json', $patch)) {
        $sets[] = 'snapshot_json = ?';
        $args[] = is_array($patch['snapshot_json'])
            ? json_encode($patch['snapshot_json'], JSON_UNESCAPED_SLASHES)
            : (string)$patch['snapshot_json'];
    }
    if (!empty($patch['error']))   { $sets[] = 'error = ?';       $args[] = substr((string)$patch['error'], 0, 500); }
    if (!empty($patch['attempts'])){ $sets[] = 'attempts = ?';    $args[] = (int)$patch['attempts']; }
    if ($status === 'applying' && empty($patch['no_started_at'])) { $sets[] = 'started_at = NOW()'; }
    if (in_array($status, ['applied','failed','rolled_back','cancelled'], true)) {
        $sets[] = 'finished_at = NOW()';
    }
    $args[] = $id;
    pdo()->prepare("UPDATE wireless_change_jobs SET " . implode(', ', $sets) . " WHERE id = ?")
        ->execute($args);
}

/* ------------------------------------------------ maintenance windows */

const MAINTENANCE_SCOPES = ['site','sector','device','tower','core'];

function maintenance_window_save(array $data, ?int $id = null): int {
    $scope = in_array($data['scope'] ?? '', MAINTENANCE_SCOPES, true) ? $data['scope'] : 'sector';
    $scope_id = (int)($data['scope_id'] ?? 0);
    if ($scope_id <= 0) throw new InvalidArgumentException('scope_id required');
    $starts = (string)($data['starts_at'] ?? '');
    $ends   = (string)($data['ends_at']   ?? '');
    if ($starts === '' || $ends === '') throw new InvalidArgumentException('starts_at and ends_at required');
    $args = [
        $scope, $scope_id, $starts, $ends,
        mb_substr((string)($data['reason'] ?? ''), 0, 255),
        !empty($data['notify_customers']) ? 1 : 0,
        !empty($data['created_by']) ? (int)$data['created_by'] : null,
    ];
    if ($id) {
        $args[] = $id;
        pdo()->prepare(
            "UPDATE maintenance_windows
                SET scope=?, scope_id=?, starts_at=?, ends_at=?, reason=?, notify_customers=?, created_by=?
              WHERE id=?"
        )->execute($args);
        return $id;
    }
    pdo()->prepare(
        "INSERT INTO maintenance_windows
            (scope, scope_id, starts_at, ends_at, reason, notify_customers, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute($args);
    return (int)pdo()->lastInsertId();
}

function maintenance_window_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM maintenance_windows WHERE id = ?")->execute([$id]);
}

function maintenance_windows_active(string $scope, int $scope_id): array {
    $stmt = pdo()->prepare(
        "SELECT * FROM maintenance_windows
          WHERE scope = ? AND scope_id = ?
            AND starts_at <= NOW() AND ends_at >= NOW()
          ORDER BY starts_at DESC"
    );
    $stmt->execute([$scope, $scope_id]);
    return $stmt->fetchAll();
}

function maintenance_windows_all(int $limit = 50): array {
    $limit = max(1, min(500, $limit));
    $stmt = pdo()->prepare(
        "SELECT mw.*, u.name AS creator_name
           FROM maintenance_windows mw
           LEFT JOIN users u ON u.id = mw.created_by
          ORDER BY (ends_at < NOW()), starts_at DESC
          LIMIT $limit"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function wireless_change_log_record(array $row): int {
    pdo()->prepare(
        "INSERT INTO wireless_change_log
            (job_id, scope, scope_id, device_id, actor_user_id, action,
             before_json, after_json, success, error)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        !empty($row['job_id']) ? (int)$row['job_id'] : null,
        (string)($row['scope']    ?? 'sector'),
        (int)($row['scope_id']    ?? 0),
        !empty($row['device_id']) ? (int)$row['device_id'] : null,
        !empty($row['actor_user_id']) ? (int)$row['actor_user_id'] : null,
        (string)($row['action']   ?? ''),
        isset($row['before']) ? json_encode($row['before'], JSON_UNESCAPED_SLASHES) : null,
        isset($row['after'])  ? json_encode($row['after'],  JSON_UNESCAPED_SLASHES) : null,
        !empty($row['success']) ? 1 : 0,
        substr((string)($row['error'] ?? ''), 0, 500),
    ]);
    return (int)pdo()->lastInsertId();
}

/* --------------------------------------------------------- regression */

/**
 * Ordinary least-squares linear regression over an array of
 * [['t' => unix_seconds, 'v' => value], …] points. Returns [slope, intercept]
 * with slope in value-units per second. Used by:
 *   bin/check-cable-snr.php  — cable_snr_db over 7 days
 *   bin/check-link-health.php — signal_dbm over 7 days
 */
function linreg_slope(array $points, string $tk = 't', string $vk = 'v'): array {
    $n = count($points);
    if ($n < 2) return [0.0, 0.0];
    $sx = $sy = $sxx = $sxy = 0.0;
    foreach ($points as $p) {
        $sx  += $p[$tk];
        $sy  += $p[$vk];
        $sxx += $p[$tk] * $p[$tk];
        $sxy += $p[$tk] * $p[$vk];
    }
    $denom = $n * $sxx - $sx * $sx;
    if ($denom == 0) return [0.0, $sy / $n];
    $slope = ($n * $sxy - $sx * $sy) / $denom;
    $inter = ($sy - $slope * $sx) / $n;
    return [$slope, $inter];
}

/* --------------------------------------------------------- distance */

/**
 * Great-circle distance in km between two lat/lng pairs.
 * Used by the polling worker to backfill wireless_links.distance_km
 * when both AP and CPE devices have a site_id with coordinates set.
 *
 * Guarded with function_exists because auth/sites.php declares an
 * identical helper, and any include order needs to be safe.
 */
if (!function_exists('haversine_km')) {
    function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $r = 6371.0088;
        $dlat = deg2rad($lat2 - $lat1);
        $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}

function distance_between_devices_km(int $a_device_id, int $b_device_id): ?float {
    if ($a_device_id <= 0 || $b_device_id <= 0) return null;
    $stmt = pdo()->prepare(
        "SELECT s.id AS site_id, s.lat AS lat, s.lng AS lng
           FROM devices d JOIN sites s ON s.id = d.site_id
          WHERE d.id IN (?, ?)"
    );
    $stmt->execute([$a_device_id, $b_device_id]);
    $rows = $stmt->fetchAll();
    if (count($rows) < 2) return null;
    return round(haversine_km(
        (float)$rows[0]['lat'], (float)$rows[0]['lng'],
        (float)$rows[1]['lat'], (float)$rows[1]['lng']
    ), 3);
}

/* ----------------------------------------------------- aggregations */

function wireless_links_degraded_count(int $threshold = 50): int {
    $stmt = pdo()->prepare(
        "SELECT COUNT(*) FROM wireless_links WHERE health_score IS NOT NULL AND health_score < ?"
    );
    $stmt->execute([$threshold]);
    return (int)$stmt->fetchColumn();
}

function wireless_change_jobs_pending_count(): int {
    return (int)pdo()->query(
        "SELECT COUNT(*) FROM wireless_change_jobs WHERE status IN ('queued','applying')"
    )->fetchColumn();
}
