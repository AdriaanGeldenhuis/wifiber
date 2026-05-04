<?php
/**
 * Webhooks fan-out worker — Phase 16.
 *
 * Picks queued/retry-due webhook_deliveries rows and POSTs them to
 * the subscriber URL with an HMAC-SHA256 signature header. Retries
 * on 5xx / network failure with exponential backoff (1m, 5m, 30m, 2h,
 * 12h). After 5 attempts marks the row 'giving_up' and stops retrying.
 *
 * Recommended cron (every minute):
 *
 *   *  *  *  *  *  /usr/bin/php ~/public_html/bin/webhooks-fanout.php --quiet >> ~/webhooks.log 2>&1
 *
 * Flags:
 *   --quiet
 *   --max-jobs=N (default 50)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/webhooks.php';

$opts = ['quiet' => false, 'max-jobs' => 50];
foreach ($argv as $a) {
    if      ($a === '--quiet') $opts['quiet'] = true;
    elseif  (preg_match('/^--max-jobs=(\d+)$/', $a, $m)) $opts['max-jobs'] = max(1, min(500, (int)$m[1]));
}

$lockfile = __DIR__ . '/../data/webhooks.lock';
$lh = @fopen($lockfile, 'c');
if (!$lh || !flock($lh, LOCK_EX | LOCK_NB)) {
    if (!$opts['quiet']) fwrite(STDERR, "[webhooks] another run in progress\n");
    exit(0);
}

$pdo = pdo();
$jobs = $pdo->prepare(
    "SELECT d.*, w.url, w.secret
       FROM webhook_deliveries d
       JOIN webhooks w ON w.id = d.webhook_id
      WHERE d.status IN ('queued','failed')
        AND d.next_attempt_at <= NOW()
        AND w.is_active = 1
      ORDER BY d.next_attempt_at ASC
      LIMIT " . (int)$opts['max-jobs']
);
$jobs->execute();
$jobs = $jobs->fetchAll();

$ok = $fail = 0;
foreach ($jobs as $job) {
    $body = (string)$job['payload_json'];
    $sig  = hash_hmac('sha256', $body, (string)$job['secret']);
    $ch = curl_init((string)$job['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Wifiber-Event: ' . $job['event'],
            'X-Wifiber-Delivery: ' . $job['id'],
            'X-Wifiber-Signature: sha256=' . $sig,
            'User-Agent: WiFIBER-Webhooks/1.0',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $attempts = (int)$job['attempts'] + 1;
    $success  = $http >= 200 && $http < 300;

    if ($success) {
        $pdo->prepare(
            "UPDATE webhook_deliveries SET status = 'sent', attempts = ?, last_response = ?, finished_at = NOW() WHERE id = ?"
        )->execute([$attempts, $http, (int)$job['id']]);
        $pdo->prepare("UPDATE webhooks SET last_fired_at = NOW(), last_status = ?, fail_count = 0 WHERE id = ?")
            ->execute([$http, (int)$job['webhook_id']]);
        $ok++;
        continue;
    }

    if ($attempts >= count(WEBHOOK_BACKOFF_MINUTES)) {
        $pdo->prepare(
            "UPDATE webhook_deliveries SET status = 'giving_up', attempts = ?, last_response = ?, last_error = ?, finished_at = NOW() WHERE id = ?"
        )->execute([$attempts, $http ?: null, mb_substr($err, 0, 500), (int)$job['id']]);
    } else {
        $back = WEBHOOK_BACKOFF_MINUTES[$attempts - 1] ?? 60;
        $pdo->prepare(
            "UPDATE webhook_deliveries SET status = 'failed', attempts = ?, last_response = ?, last_error = ?, next_attempt_at = (NOW() + INTERVAL ? MINUTE) WHERE id = ?"
        )->execute([$attempts, $http ?: null, mb_substr($err, 0, 500), $back, (int)$job['id']]);
    }
    $pdo->prepare("UPDATE webhooks SET last_status = ?, fail_count = fail_count + 1 WHERE id = ?")
        ->execute([$http ?: null, (int)$job['webhook_id']]);
    $fail++;
}

flock($lh, LOCK_UN); fclose($lh);
if (!$opts['quiet']) printf("[webhooks] sent=%d failed=%d processed=%d\n", $ok, $fail, count($jobs));
exit(0);
