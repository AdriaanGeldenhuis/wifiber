<?php
/**
 * Cable SNR regression alert worker.
 *
 * Walks every device with at least 7 days of ethernet_health samples
 * and computes the linear-regression slope of cable_snr_db over that
 * window. If the slope is steeper than -3 dB / 7 days the cable is
 * almost certainly degrading (water ingress, UV-cracked sheath,
 * crimp working loose) and the operator gets an audit-log alert.
 *
 * UISP shows the live value; nobody else trends it. This is one of
 * the "more than UISP" features — catches problems weeks before they
 * become customer-visible.
 *
 * Recommended cron: nightly (slow query, reads 7 days of samples).
 *
 *   30 4 * * *  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/check-cable-snr.php --quiet >> ~/cable-snr.log 2>&1
 *
 * Flags:
 *   --quiet
 *   --threshold-db=N  alert threshold in dB drop over 7 days (default 3)
 *   --window-days=N   regression window (default 7)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/notifications.php';

$opts = ['quiet' => false, 'threshold-db' => 3, 'window-days' => 7];
foreach ($argv as $a) {
    if      ($a === '--quiet') $opts['quiet'] = true;
    elseif  (preg_match('/^--threshold-db=(\d+)$/', $a, $m)) $opts['threshold-db'] = max(1, min(20, (int)$m[1]));
    elseif  (preg_match('/^--window-days=(\d+)$/',  $a, $m)) $opts['window-days']  = max(2, min(60, (int)$m[1]));
}

$pdo = pdo();
$stmt = $pdo->prepare(
    "SELECT device_id, polled_at, cable_snr_db
       FROM ethernet_health
      WHERE polled_at >= NOW() - INTERVAL ? DAY
        AND cable_snr_db IS NOT NULL
      ORDER BY device_id ASC, polled_at ASC"
);
$stmt->execute([$opts['window-days']]);
$rows = $stmt->fetchAll();

$by_device = [];
foreach ($rows as $r) {
    $by_device[(int)$r['device_id']][] = [
        't' => strtotime((string)$r['polled_at']),
        'v' => (float)$r['cable_snr_db'],
    ];
}

$alerts = 0;
foreach ($by_device as $dev_id => $samples) {
    if (count($samples) < 24) continue; // need a useful sample set
    foreach ($samples as &$s) $s['snr'] = $s['v'];
    unset($s);
    [$slope, $intercept] = linreg_slope($samples);
    $window_seconds = $opts['window-days'] * 86400;
    // Slope is dB/sec — extrapolate over the window.
    $delta = $slope * $window_seconds;
    if ($delta < -$opts['threshold-db']) {
        $first = $samples[0]; $last = end($samples);
        $dev = device_find($dev_id);
        audit_log('cable_snr.alert', [
            'target_type' => 'device', 'target_id' => $dev_id,
            'meta' => [
                'first_polled_at' => date('c', $first['t']),
                'first_snr_db'    => $first['snr'],
                'last_polled_at'  => date('c', $last['t']),
                'last_snr_db'     => $last['snr'],
                'slope_db_per_window' => round($delta, 2),
                'samples'         => count($samples),
            ],
        ]);
        // Email the NOC team. If site_settings has 'noc_email' set,
        // send there; otherwise fall back to every admin user.
        $site = load_site_settings();
        $noc_email = trim((string)($site['noc_email'] ?? ''));
        $recipients = [];
        if ($noc_email !== '') {
            $recipients[] = ['id' => 0, 'email' => $noc_email, 'name' => 'NOC'];
        } else {
            $stmt = pdo()->prepare("SELECT id, email, name FROM users WHERE role = 'admin' AND email <> ''");
            $stmt->execute();
            $recipients = $stmt->fetchAll();
        }
        foreach ($recipients as $r) {
            notify_send($r, 'cable.snr_drop', [
                'device_name' => $dev['name'] ?? ('#' . $dev_id),
                'drop_db'     => $delta,
                'window_days' => $opts['window-days'],
            ], ['email']);
        }
        if (is_file(__DIR__ . '/../auth/inbox.php')) {
            require_once __DIR__ . '/../auth/inbox.php';
            inbox_post(
                'Cable SNR regression — ' . ($dev['name'] ?? ('#' . $dev_id)),
                sprintf("%.1f dB drop over %d days (%d samples). Likely water ingress, UV cracking or a working-loose crimp.",
                    $delta, (int)$opts['window-days'], count($samples)),
                [
                    'audience'   => 'noc',
                    'severity'   => 'warning',
                    'link'       => '/admin/devices.php?id=' . $dev_id,
                    'dedupe_key' => 'cable.snr_drop.' . $dev_id,
                ]
            );
        }
        $alerts++;
        if (!$opts['quiet']) {
            printf("[cable-snr] device #%d: %.1f dB drop over %d days (%d samples)\n",
                $dev_id, $delta, $opts['window-days'], count($samples));
        }
    }
}

if (!$opts['quiet']) {
    printf("[cable-snr] %d alert(s) across %d device(s).\n", $alerts, count($by_device));
}
exit(0);

// linreg_slope() lives in auth/wireless.php — shared with check-link-health.
