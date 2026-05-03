<?php
/**
 * Active diagnostics — Phase 12.
 *
 * Wraps the four diagnostic kinds (iperf3, traceroute, ping_n,
 * bgp_lookup) over a uniform DTO so bin/run-diagnostic.php is a thin
 * orchestrator. Each kind:
 *
 *   diagnostic_KIND_run($device, $cred, $payload): array
 *     returns ['ok'=>bool, 'output'=>string, 'parsed'=>array, 'error'=>string]
 *
 * SSH delivery uses /usr/bin/ssh via proc_open with a per-call
 * known_hosts file so the host key changes don't lock us out across
 * firmware upgrades. (Avoiding phpseclib so we don't add a vendor
 * dependency.)
 *
 * Speed-test results land in link_speedtests when scope='link'.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/wireless.php';

const DIAG_KINDS = ['iperf3', 'traceroute', 'ping_n', 'bgp_lookup'];

function diagnostic_job_enqueue(string $kind, string $scope, int $scope_id,
    int $requested_by, array $payload = []): int
{
    if (!in_array($kind,  DIAG_KINDS,            true)) throw new InvalidArgumentException("kind");
    if (!in_array($scope, ['link', 'device'],    true)) throw new InvalidArgumentException("scope");
    if ($scope_id <= 0) throw new InvalidArgumentException('scope_id required');
    pdo()->prepare(
        "INSERT INTO diagnostic_jobs (kind, scope, scope_id, requested_by, payload_json)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$kind, $scope, $scope_id, $requested_by ?: null,
                json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    return (int)pdo()->lastInsertId();
}

function diagnostic_job_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM diagnostic_jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function diagnostic_jobs_pending(int $limit = 5): array {
    $limit = max(1, min(50, $limit));
    return pdo()->query(
        "SELECT * FROM diagnostic_jobs WHERE status = 'queued'
          ORDER BY created_at ASC LIMIT $limit"
    )->fetchAll();
}

function diagnostic_job_mark(int $id, string $status, ?array $result = null, string $error = ''): void {
    $sets = ['status = ?'];
    $args = [$status];
    if ($status === 'running') $sets[] = 'started_at = NOW()';
    if (in_array($status, ['done','failed'], true)) $sets[] = 'finished_at = NOW()';
    if ($result !== null) {
        $sets[] = 'result_json = ?';
        $args[] = json_encode($result, JSON_UNESCAPED_SLASHES);
    }
    if ($error !== '') {
        $sets[] = 'error = ?';
        $args[] = mb_substr($error, 0, 500);
    }
    $args[] = $id;
    pdo()->prepare("UPDATE diagnostic_jobs SET " . implode(', ', $sets) . " WHERE id = ?")
        ->execute($args);
}

function diagnostic_jobs_recent(?array $filters = null, int $limit = 50): array {
    $sql = "SELECT j.*, u.name AS requester_name
              FROM diagnostic_jobs j
              LEFT JOIN users u ON u.id = j.requested_by";
    $where = []; $args = [];
    $f = $filters ?? [];
    if (!empty($f['scope']) && in_array($f['scope'], ['link','device'], true)) {
        $where[] = 'j.scope = ?'; $args[] = $f['scope'];
    }
    if (!empty($f['scope_id'])) { $where[] = 'j.scope_id = ?'; $args[] = (int)$f['scope_id']; }
    if (!empty($f['kind']) && in_array($f['kind'], DIAG_KINDS, true)) {
        $where[] = 'j.kind = ?'; $args[] = $f['kind'];
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $limit = max(1, min(500, $limit));
    $sql .= " ORDER BY j.created_at DESC LIMIT $limit";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/* ----------------------------------------------------- diagnostic kinds */

function _diag_ssh_exec(array $device, array $cred, string $cmd, int $timeout_s = 30): array {
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'output' => '', 'error' => 'proc_open disabled'];
    }
    $port = (int)($cred['port'] ?? $device['mgmt_port'] ?? 22);
    $user = (string)($cred['username'] ?? 'admin');
    $pass = (string)($cred['password'] ?? '');
    $ip   = (string)$device['mgmt_ip'];

    // sshpass for password auth; otherwise rely on a key in
    // ~/.ssh/id_ed25519 mounted on the host.
    $sshpass = $pass !== '' ? '/usr/bin/sshpass -p ' . escapeshellarg($pass) . ' ' : '';
    $ssh_cmd = $sshpass . 'ssh '
             . '-o StrictHostKeyChecking=no '
             . '-o UserKnownHostsFile=/dev/null '
             . '-o ConnectTimeout=10 '
             . '-p ' . $port . ' '
             . escapeshellarg($user . '@' . $ip) . ' '
             . escapeshellarg($cmd);

    $proc = proc_open('timeout ' . (int)$timeout_s . ' ' . $ssh_cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'output' => '', 'error' => 'proc_open failed'];
    }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [
        'ok'     => $code === 0,
        'output' => $stdout . $stderr,
        'error'  => $code === 0 ? '' : "ssh exit $code",
    ];
}

function diagnostic_iperf3_run(array $device, array $cred, array $payload): array {
    $target = (string)($payload['target_ip'] ?? '');
    $secs   = max(5, min(60, (int)($payload['duration_s'] ?? 10)));
    if ($target === '') return ['ok' => false, 'error' => 'target_ip required'];
    $cmd = "iperf3 -c " . escapeshellarg($target) . " -t {$secs} -J";
    $r = _diag_ssh_exec($device, $cred, $cmd, $secs + 15);
    if (!$r['ok']) return $r;
    $j = json_decode($r['output'], true);
    if (!is_array($j)) return ['ok' => false, 'output' => $r['output'], 'error' => 'iperf3 JSON parse failed'];
    $sum = $j['end']['sum_received'] ?? $j['end']['sum'] ?? [];
    $mbps = isset($sum['bits_per_second']) ? (float)$sum['bits_per_second'] / 1e6 : null;
    $jitter = $j['end']['streams'][0]['udp']['jitter_ms'] ?? null;
    $loss   = $j['end']['streams'][0]['udp']['lost_percent'] ?? null;
    return [
        'ok' => true, 'output' => $r['output'],
        'parsed' => [
            'mbps_down' => $mbps,
            'mbps_up'   => null,
            'jitter_ms' => $jitter !== null ? (float)$jitter : null,
            'loss_pct'  => $loss   !== null ? (float)$loss   : null,
            'duration_s'=> $secs,
        ],
    ];
}

function diagnostic_traceroute_run(array $device, array $cred, array $payload): array {
    $target = (string)($payload['target_ip'] ?? '');
    if ($target === '') return ['ok' => false, 'error' => 'target_ip required'];
    $cmd = "traceroute -n -w 2 -q 1 -m 20 " . escapeshellarg($target);
    $r = _diag_ssh_exec($device, $cred, $cmd, 60);
    if (!$r['ok']) return $r;
    $hops = [];
    foreach (explode("\n", $r['output']) as $line) {
        if (preg_match('/^\s*(\d+)\s+([\d.]+)\s+([0-9.]+)\s*ms/', $line, $m)) {
            $hops[] = ['hop' => (int)$m[1], 'ip' => $m[2], 'rtt_ms' => (float)$m[3]];
        }
    }
    return ['ok' => true, 'output' => $r['output'], 'parsed' => ['hops' => $hops]];
}

function diagnostic_ping_n_run(array $device, array $cred, array $payload): array {
    $target = (string)($payload['target_ip'] ?? '');
    $count  = max(10, min(2000, (int)($payload['count'] ?? 100)));
    if ($target === '') return ['ok' => false, 'error' => 'target_ip required'];
    $cmd = "ping -c {$count} -i 0.2 " . escapeshellarg($target);
    $r = _diag_ssh_exec($device, $cred, $cmd, 60 + intdiv($count, 5));
    if (!$r['ok']) return $r;
    $parsed = ['count' => $count];
    if (preg_match('/(\d+)\s+packets transmitted,\s*(\d+)\s+received(?:,\s*\d+\s+errors)?,\s*(\d+(?:\.\d+)?)%\s*(?:packet\s+)?loss/i', $r['output'], $m)) {
        $parsed['transmitted'] = (int)$m[1];
        $parsed['received']    = (int)$m[2];
        $parsed['loss_pct']    = (float)$m[3];
    }
    if (preg_match('/(?:rtt|round-trip)[^=]+=\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+)\/([0-9.]+)\s*ms/', $r['output'], $m)) {
        $parsed['rtt_min_ms']    = (float)$m[1];
        $parsed['rtt_avg_ms']    = (float)$m[2];
        $parsed['rtt_max_ms']    = (float)$m[3];
        $parsed['rtt_mdev_ms']   = (float)$m[4];
    }
    return ['ok' => true, 'output' => $r['output'], 'parsed' => $parsed];
}

function diagnostic_bgp_lookup_run(array $device, array $cred, array $payload): array {
    $prefix = (string)($payload['prefix'] ?? '');
    if ($prefix === '') return ['ok' => false, 'error' => 'prefix required'];
    // RouterOS-flavoured: /ip route print where dst-address~"X"
    $cmd = "/ip route print where dst-address~" . escapeshellarg($prefix);
    $r = _diag_ssh_exec($device, $cred, $cmd, 20);
    return ['ok' => $r['ok'], 'output' => $r['output'], 'error' => $r['error']];
}

/* --------------------------------------------------------- speedtests */

function link_speedtest_record(int $link_id, ?int $job_id, array $parsed): int {
    pdo()->prepare(
        "INSERT INTO link_speedtests
            (link_id, job_id, mbps_down, mbps_up, jitter_ms, loss_pct)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $link_id, $job_id,
        $parsed['mbps_down'] ?? null,
        $parsed['mbps_up']   ?? null,
        $parsed['jitter_ms'] ?? null,
        $parsed['loss_pct']  ?? null,
    ]);
    return (int)pdo()->lastInsertId();
}

function link_speedtests_recent(int $link_id, int $limit = 50): array {
    $stmt = pdo()->prepare(
        "SELECT * FROM link_speedtests
          WHERE link_id = ?
          ORDER BY polled_at DESC, id DESC
          LIMIT $limit"
    );
    $stmt->execute([$link_id]);
    return $stmt->fetchAll();
}
