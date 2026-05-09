<?php
/**
 * RADIUS / NAS helpers.
 *
 * The DB tables (nas, radcheck, radreply, radusergroup, radacct, ...) are
 * laid out so an external FreeRADIUS server can rlm_sql straight into our
 * MariaDB. The functions below are what *our* admin app calls to keep the
 * RADIUS rows in sync with the customer record:
 *
 *   radius_provision_user()   — write radcheck/radreply/radusergroup for a customer
 *   radius_suspend()          — flip group → suspended (captive-portal)
 *   radius_disconnect()       — flip group → disconnected (Auth-Type:=Reject) + PoD
 *   radius_reactivate()       — flip group → active and re-bind rate-limit
 *   radius_send_pod()         — fire CoA-Disconnect (RFC 5176) at the NAS
 *   radius_session_for_user() — read latest open session from radacct
 *   radius_set_password()     — store + persist Cleartext-Password attribute
 *
 * Rate-limit values come from the user's product (down_mbps / up_mbps)
 * and use the Mikrotik-Rate-Limit string format (rx-rate/tx-rate from the
 * subscriber's perspective, so down/up). On RouterOS the same attribute
 * works; on AirOS/Cambium with FreeRADIUS in front it's accepted as a
 * generic VSA. NAS-specific adapters can later override format here.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const RADIUS_GROUPS = ['active', 'suspended', 'disconnected'];

/* --------------------------------------------------------------- nas list */

function nas_all(): array {
    return pdo()->query("SELECT * FROM nas ORDER BY shortname, id")->fetchAll();
}

function nas_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM nas WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function nas_save(array $data, ?int $id = null): int {
    $args = [
        'nasname'     => trim((string)($data['nasname']     ?? '')),
        'shortname'   => trim((string)($data['shortname']   ?? '')),
        'type'        => trim((string)($data['type']        ?? 'other')),
        'ports'       => $data['ports'] === '' || $data['ports'] === null ? null : (int)$data['ports'],
        'secret'      => (string)($data['secret']      ?? ''),
        'server'      => trim((string)($data['server']      ?? '')) ?: null,
        'community'   => trim((string)($data['community']   ?? '')) ?: null,
        'description' => trim((string)($data['description'] ?? '')) ?: null,
        'pod_port'    => max(1, min(65535, (int)($data['pod_port'] ?? 3799))),
        'device_id'   => $data['device_id'] ?? null,
    ];
    if ($args['nasname'] === '')   throw new InvalidArgumentException('NAS hostname / IP is required.');
    if ($args['shortname'] === '') throw new InvalidArgumentException('Shortname is required.');
    if ($args['secret'] === '')    throw new InvalidArgumentException('Shared secret is required.');
    if ($args['device_id'] !== null && (int)$args['device_id'] <= 0) $args['device_id'] = null;
    if ($args['device_id'] !== null) $args['device_id'] = (int)$args['device_id'];

    if ($id) {
        $stmt = pdo()->prepare(
            "UPDATE nas
                SET nasname=?, shortname=?, type=?, ports=?, secret=?, server=?,
                    community=?, description=?, pod_port=?, device_id=?
              WHERE id=?"
        );
        $stmt->execute([
            $args['nasname'], $args['shortname'], $args['type'], $args['ports'],
            $args['secret'], $args['server'], $args['community'], $args['description'],
            $args['pod_port'], $args['device_id'], $id,
        ]);
        return $id;
    }
    $stmt = pdo()->prepare(
        "INSERT INTO nas (nasname, shortname, type, ports, secret, server,
                          community, description, pod_port, device_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $args['nasname'], $args['shortname'], $args['type'], $args['ports'],
        $args['secret'], $args['server'], $args['community'], $args['description'],
        $args['pod_port'], $args['device_id'],
    ]);
    return (int)pdo()->lastInsertId();
}

function nas_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM nas WHERE id = ?")->execute([$id]);
}

/* --------------------------------------------------------- user provision */

/**
 * Effective RADIUS username for a customer.  Prefers an explicit override
 * (radius_username) so a Splynx-imported PPPoE login keeps working without
 * renaming the portal account.
 */
function radius_username_for(array $user): string {
    $u = trim((string)($user['radius_username'] ?? ''));
    if ($u !== '') return $u;
    return (string)($user['username'] ?? '');
}

function radius_rate_limit_string(?array $product): ?string {
    if (!$product) return null;
    $down = (float)($product['down_mbps'] ?? 0);
    $up   = (float)($product['up_mbps']   ?? 0);
    if ($down <= 0 || $up <= 0) return null;
    // Mikrotik-Rate-Limit syntax is "rx-rate/tx-rate" relative to the NAS.
    // For a subscriber that's <download>/<upload>. Mbps → kbps × 1024.
    $rx = (int)round($down * 1024);
    $tx = (int)round($up   * 1024);
    return $rx . 'k/' . $tx . 'k';
}

/**
 * Look up the customer's currently-attached product, if any. Tolerates the
 * fact that not every install has products yet.
 */
function radius_product_for_user(array $user): ?array {
    $pid = (int)($user['product_id'] ?? 0);
    if ($pid <= 0) return null;
    try {
        $stmt = pdo()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Map our customer-status enum onto a RADIUS group.
 *   active       → active
 *   suspended    → suspended
 *   disconnected → disconnected
 *   lead         → disconnected (no service yet)
 */
function radius_group_for_status(string $status): string {
    return match ($status) {
        'active'       => 'active',
        'suspended'    => 'suspended',
        'disconnected' => 'disconnected',
        default        => 'disconnected',
    };
}

/**
 * Generate a fresh service password for a freshly-provisioned customer.
 * Stored encrypted in users.service_password_enc and written in cleartext
 * to radcheck so FreeRADIUS can verify Access-Requests.
 */
function radius_generate_password(int $length = 12): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

function radius_set_password(int $user_id, string $cleartext): bool {
    $u = find_user_by_id($user_id);
    if (!$u) return false;
    $enc = encrypt_secret($cleartext);
    if ($enc === null) return false;
    $stmt = pdo()->prepare("UPDATE users SET service_password_enc = ? WHERE id = ?");
    $stmt->execute([$enc, $user_id]);

    $username = radius_username_for($u);
    radius_replace_check($username, 'Cleartext-Password', ':=', $cleartext);
    audit_log('radius.password_set', ['target_type' => 'user', 'target_id' => $user_id]);
    return true;
}

function radius_get_password(array $user): ?string {
    $blob = $user['service_password_enc'] ?? null;
    if (!$blob) return null;
    return decrypt_secret(is_resource($blob) ? stream_get_contents($blob) : (string)$blob);
}

/**
 * Idempotent provision: ensure the user has radcheck (password), radreply
 * (rate-limit if a product is attached) and radusergroup rows that match
 * their current status. Generates and stores a service password the first
 * time around. Returns true if anything changed.
 */
/* "Is this customer ready to authenticate against the NAS?" — used as
   a sign-off gate by /admin/install-view.php. Returns ['ready'=>bool,
   'reason'=>string]. We require a radcheck password row AND group
   membership to count as ready. */
function radius_user_provisioned(int $user_id): array {
    $u = find_user_by_id($user_id);
    if (!$u || ($u['role'] ?? '') !== 'client') {
        return ['ready' => false, 'reason' => 'not a customer record'];
    }
    $username = radius_username_for($u);
    if ($username === '') {
        return ['ready' => false, 'reason' => 'no RADIUS username'];
    }
    try {
        $pw_stmt = pdo()->prepare("SELECT COUNT(*) FROM radcheck WHERE username = ?");
        $pw_stmt->execute([$username]);
        $has_pw = (int)$pw_stmt->fetchColumn() > 0;

        $grp_stmt = pdo()->prepare("SELECT COUNT(*) FROM radusergroup WHERE username = ?");
        $grp_stmt->execute([$username]);
        $has_grp = (int)$grp_stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return ['ready' => false, 'reason' => 'RADIUS tables not reachable: ' . $e->getMessage()];
    }
    if (!$has_pw)  return ['ready' => false, 'reason' => 'no radcheck password row'];
    if (!$has_grp) return ['ready' => false, 'reason' => 'no radusergroup row'];
    return ['ready' => true, 'reason' => 'ok'];
}

function radius_provision_user(int $user_id): bool {
    $u = find_user_by_id($user_id);
    if (!$u || ($u['role'] ?? '') !== 'client') return false;

    $username = radius_username_for($u);
    if ($username === '') return false;

    // First-time provision: mint a password and persist it.
    $password = radius_get_password($u);
    if ($password === null || $password === '') {
        $password = radius_generate_password();
        radius_set_password($user_id, $password);
    } else {
        // Make sure radcheck still holds the current password (cheap idempotent write).
        radius_replace_check($username, 'Cleartext-Password', ':=', $password);
    }

    // Reply: rate-limit from product (if any).
    $product = radius_product_for_user($u);
    $rate    = radius_rate_limit_string($product);
    if ($rate !== null) {
        radius_replace_reply($username, 'Mikrotik-Rate-Limit', ':=', $rate);
    } else {
        // No product → drop any stale rate-limit so the NAS falls back to
        // the group default rather than enforcing yesterday's plan.
        radius_delete_reply($username, 'Mikrotik-Rate-Limit');
    }

    // Group: track the customer's lifecycle status.
    $group     = radius_group_for_status((string)($u['status'] ?? 'active'));
    $prev_grp  = (string)($u['radius_group'] ?? '');
    radius_set_group($username, $group);

    // Persist the chosen group on the user row so the RADIUS page can show
    // it without joining radusergroup every render.
    if ($prev_grp !== $group) {
        pdo()->prepare("UPDATE users SET radius_group = ? WHERE id = ?")
             ->execute([$group, $user_id]);
        // A move *away* from active means an open PPP session is now stale
        // (suspended user staying connected at full speed, or disconnected
        // user still online). Kick the session so the new group takes effect.
        if ($prev_grp === 'active' && $group !== 'active') {
            radius_send_pod($username);
        }
    }
    audit_log('radius.provision', [
        'target_type' => 'user',
        'target_id'   => $user_id,
        'meta'        => ['username' => $username, 'group' => $group, 'rate' => $rate],
    ]);
    return true;
}

function radius_suspend(int $user_id, string $reason = ''): bool {
    $u = find_user_by_id($user_id);
    if (!$u) return false;
    $username = radius_username_for($u);
    if ($username === '') return false;

    radius_set_group($username, 'suspended');
    pdo()->prepare("UPDATE users SET radius_group = 'suspended' WHERE id = ?")->execute([$user_id]);
    audit_log('radius.suspend', [
        'target_type' => 'user', 'target_id' => $user_id,
        'meta' => ['username' => $username, 'reason' => $reason],
    ]);
    radius_send_pod($username); // boot any in-flight session so the new group sticks
    return true;
}

function radius_disconnect(int $user_id, string $reason = ''): bool {
    $u = find_user_by_id($user_id);
    if (!$u) return false;
    $username = radius_username_for($u);
    if ($username === '') return false;

    radius_set_group($username, 'disconnected');
    pdo()->prepare("UPDATE users SET radius_group = 'disconnected' WHERE id = ?")->execute([$user_id]);
    audit_log('radius.disconnect', [
        'target_type' => 'user', 'target_id' => $user_id,
        'meta' => ['username' => $username, 'reason' => $reason],
    ]);
    radius_send_pod($username);
    return true;
}

function radius_reactivate(int $user_id): bool {
    return radius_provision_user($user_id);
}

/* ----------------------------------------------------------- attribute IO */

function radius_replace_check(string $username, string $attribute, string $op, string $value): void {
    pdo()->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = ?")
         ->execute([$username, $attribute]);
    pdo()->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)")
         ->execute([$username, $attribute, $op, $value]);
}

function radius_replace_reply(string $username, string $attribute, string $op, string $value): void {
    pdo()->prepare("DELETE FROM radreply WHERE username = ? AND attribute = ?")
         ->execute([$username, $attribute]);
    pdo()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ?, ?)")
         ->execute([$username, $attribute, $op, $value]);
}

function radius_delete_reply(string $username, string $attribute): void {
    pdo()->prepare("DELETE FROM radreply WHERE username = ? AND attribute = ?")
         ->execute([$username, $attribute]);
}

function radius_set_group(string $username, string $group): void {
    if (!in_array($group, RADIUS_GROUPS, true)) $group = 'disconnected';
    pdo()->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]);
    pdo()->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
         ->execute([$username, $group]);
}

function radius_purge_user(string $username): void {
    pdo()->prepare("DELETE FROM radcheck     WHERE username = ?")->execute([$username]);
    pdo()->prepare("DELETE FROM radreply     WHERE username = ?")->execute([$username]);
    pdo()->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]);
}

/* --------------------------------------------------------------- accounting */

function radius_session_for_user(int $user_id): ?array {
    $u = find_user_by_id($user_id);
    if (!$u) return null;
    $username = radius_username_for($u);
    if ($username === '') return null;
    $stmt = pdo()->prepare(
        "SELECT * FROM radacct
          WHERE username = ?
            AND acctstoptime IS NULL
          ORDER BY acctstarttime DESC
          LIMIT 1"
    );
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

function radius_sessions_open(int $limit = 200): array {
    $limit = max(1, min(2000, $limit));
    $stmt = pdo()->prepare(
        "SELECT a.*, u.id AS user_id, u.name AS client_name
           FROM radacct a
           LEFT JOIN users u
             ON u.username = a.username OR u.radius_username = a.username
          WHERE a.acctstoptime IS NULL
          ORDER BY a.acctstarttime DESC
          LIMIT $limit"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Aggregate octets per user for a billing period.
 *
 * @param string $period_start_iso  YYYY-MM-DD (inclusive)
 * @param string $period_end_iso    YYYY-MM-DD (exclusive)
 */
function radius_usage_by_user(string $period_start_iso, string $period_end_iso): array {
    $stmt = pdo()->prepare(
        "SELECT username,
                SUM(COALESCE(acctinputoctets,  0)) AS in_octets,
                SUM(COALESCE(acctoutputoctets, 0)) AS out_octets,
                COUNT(*)                            AS sessions
           FROM radacct
          WHERE acctstarttime >= ? AND acctstarttime < ?
          GROUP BY username
          ORDER BY (SUM(COALESCE(acctinputoctets, 0)) +
                    SUM(COALESCE(acctoutputoctets, 0))) DESC
          LIMIT 1000"
    );
    $stmt->execute([$period_start_iso, $period_end_iso]);
    return $stmt->fetchAll();
}

/* --------------------------------------------------------- packet-of-disconnect */

/**
 * Send an RFC 5176 CoA-Disconnect ("Packet-of-Disconnect") to every NAS
 * that has an open session for this user. We sign the packet with the
 * NAS's shared secret so the NAS will accept it.
 *
 * Implementation: minimal RADIUS framing — Disconnect-Request (code 40)
 * with NAS-Identifier(32) + User-Name(1) + Acct-Session-Id(44). We don't
 * wait for the reply; treat fire-and-forget. Returns the count of NAS
 * sockets we wrote to (so the caller can flash a count).
 */
function radius_send_pod(string $username): int {
    if ($username === '') return 0;
    $stmt = pdo()->prepare(
        "SELECT a.acctsessionid, a.nasipaddress, n.id AS nas_id, n.secret, n.pod_port
           FROM radacct a
           LEFT JOIN nas n ON n.nasname = a.nasipaddress OR n.shortname = a.nasipaddress
          WHERE a.username = ? AND a.acctstoptime IS NULL"
    );
    $stmt->execute([$username]);
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    $sent = 0;
    foreach ($rows as $r) {
        $secret = (string)($r['secret'] ?? '');
        $nas_ip = (string)($r['nasipaddress'] ?? '');
        $port   = (int)($r['pod_port'] ?? 3799);
        if ($secret === '' || $nas_ip === '') continue;
        if (!filter_var($nas_ip, FILTER_VALIDATE_IP)) continue;

        $packet = radius_build_disconnect_packet($username, (string)$r['acctsessionid'], $secret);
        $sock = @fsockopen('udp://' . $nas_ip, $port, $errno, $errstr, 1.5);
        if (!$sock) {
            audit_log('radius.pod.fail', ['meta' => [
                'username' => $username, 'nas' => $nas_ip,
                'errno' => $errno, 'error' => $errstr,
            ]]);
            continue;
        }
        @stream_set_timeout($sock, 1, 0);
        $ok = @fwrite($sock, $packet);
        @fclose($sock);
        if ($ok !== false) $sent++;
    }
    audit_log('radius.pod', ['meta' => ['username' => $username, 'sent' => $sent]]);
    return $sent;
}

/**
 * Build a RADIUS Disconnect-Request packet (RFC 5176 / RFC 2865 framing).
 *
 *   header = 1B code | 1B id | 2B length | 16B authenticator
 *   then User-Name (type 1) and Acct-Session-Id (type 44) attributes.
 *
 * The authenticator is HMAC-style: MD5(code | id | length | zeros | attrs | secret).
 */
function radius_build_disconnect_packet(string $username, string $session_id, string $secret): string {
    $code = chr(40);                          // Disconnect-Request
    $id   = chr(random_int(0, 255));

    $attrs  = chr(1)  . chr(2 + strlen($username))   . $username;        // User-Name
    if ($session_id !== '') {
        $attrs .= chr(44) . chr(2 + strlen($session_id)) . $session_id;  // Acct-Session-Id
    }
    $length = 1 + 1 + 2 + 16 + strlen($attrs);
    $len_bin = pack('n', $length);
    $zeros   = str_repeat("\0", 16);
    $auth    = md5($code . $id . $len_bin . $zeros . $attrs . $secret, true);
    return $code . $id . $len_bin . $auth . $attrs;
}

/* ------------------------------------------------------ formatting helpers */

function radius_format_octets(?int $bytes): string {
    $bytes = (int)($bytes ?? 0);
    if ($bytes < 1024)             return $bytes . ' B';
    if ($bytes < 1024 * 1024)      return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 ** 3)        return number_format($bytes / (1024 ** 2), 1) . ' MB';
    if ($bytes < 1024 ** 4)        return number_format($bytes / (1024 ** 3), 2) . ' GB';
    return number_format($bytes / (1024 ** 4), 2) . ' TB';
}

function radius_format_duration(?int $seconds): string {
    $s = max(0, (int)($seconds ?? 0));
    if ($s < 60) return $s . 's';
    if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
    if ($s < 86400) return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    return floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
}
