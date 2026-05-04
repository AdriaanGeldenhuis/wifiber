<?php
/**
 * Predictive link-health alerts — Phase 10.
 *
 * Walks every wireless_link with > 24 h of link_health_samples and
 * computes three things per link:
 *
 *   1. Signal slope    — 7-day linear regression on signal_local_dbm.
 *                        Extrapolated drop > threshold (default 6 dB)
 *                        opens a 'signal_drop' alert + customer ticket.
 *   2. Link budget     — theoretical signal from
 *                        tx_power_dbm + ap_gain + cpe_gain - free_space_loss
 *                        vs. measured. If measured is > 8 dB worse than
 *                        budget → 'link_budget' alert (your install is
 *                        degrading or aimed wrong).
 *   3. Capacity sat    — 7-day avg of throughput / capacity. > 80% →
 *                        'capacity_saturation' alert (split the sector).
 *
 * Alerts are deduplicated on (link_id, kind, resolved_at IS NULL).
 * If the regression reverses on the next nightly run, the alert is
 * auto-resolved.
 *
 * Recommended cron (nightly, after the cable SNR worker):
 *
 *   45 4 * * *  /usr/bin/php ~/public_html/bin/check-link-health.php --quiet >> ~/check-link-health.log 2>&1
 *
 * Flags:
 *   --quiet
 *   --signal-drop-db=N      drop threshold (default 6)
 *   --budget-margin-db=N    measured-vs-budget threshold (default 8)
 *   --capacity-pct=N        saturation threshold (default 80)
 *   --window-days=N         regression window (default 7)
 *   --dry-run               compute, don't open alerts/tickets
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/devices.php';
require __DIR__ . '/../auth/wireless.php';
require __DIR__ . '/../auth/tickets.php';
require __DIR__ . '/../auth/notifications.php';

$opts = [
    'quiet'             => false,
    'dry-run'           => false,
    'signal-drop-db'    => 6,
    'budget-margin-db'  => 8,
    'fresnel-margin-db' => 15,   // Phase 25 — significantly worse than budget = likely path obstruction.
    'capacity-pct'      => 80,
    'window-days'       => 7,
];
foreach ($argv as $a) {
    if      ($a === '--quiet')   $opts['quiet']   = true;
    elseif  ($a === '--dry-run') $opts['dry-run'] = true;
    elseif  (preg_match('/^--signal-drop-db=(\d+)$/', $a, $m))    $opts['signal-drop-db']    = max(1, min(40, (int)$m[1]));
    elseif  (preg_match('/^--budget-margin-db=(\d+)$/', $a, $m))  $opts['budget-margin-db']  = max(1, min(40, (int)$m[1]));
    elseif  (preg_match('/^--fresnel-margin-db=(\d+)$/', $a, $m)) $opts['fresnel-margin-db'] = max(8, min(50, (int)$m[1]));
    elseif  (preg_match('/^--capacity-pct=(\d+)$/', $a, $m))      $opts['capacity-pct']      = max(50, min(99, (int)$m[1]));
    elseif  (preg_match('/^--window-days=(\d+)$/', $a, $m))       $opts['window-days']       = max(2, min(60, (int)$m[1]));
}

$pdo = pdo();
$links = $pdo->query(
    "SELECT wl.*, ap.antenna_gain_dbi AS ap_gain, cpe.antenna_gain_dbi AS cpe_gain,
            ap.name AS ap_name, cpe.name AS cpe_name,
            u.id AS customer_id, u.name AS customer_name
       FROM wireless_links wl
       JOIN devices ap        ON ap.id  = wl.ap_device_id
       LEFT JOIN devices cpe  ON cpe.id = wl.cpe_device_id
       LEFT JOIN users u      ON u.id   = wl.customer_id
      WHERE ap.status <> 'retired'"
)->fetchAll();

$opened = $resolved = 0;
foreach ($links as $link) {
    $link_id = (int)$link['id'];

    // Pull window-days of samples.
    $stmt = $pdo->prepare(
        "SELECT polled_at, signal_local_dbm, throughput_local_mbps, capacity_local_mbps
           FROM link_health_samples
          WHERE link_id = ? AND polled_at >= NOW() - INTERVAL ? DAY
          ORDER BY polled_at ASC"
    );
    $stmt->execute([$link_id, $opts['window-days']]);
    $samples = $stmt->fetchAll();
    if (count($samples) < 24) continue;

    /* ---------- 1. Signal slope ---------- */
    $sig_pts = [];
    foreach ($samples as $s) {
        if ($s['signal_local_dbm'] === null) continue;
        $sig_pts[] = ['t' => strtotime((string)$s['polled_at']),
                      'v' => (float)$s['signal_local_dbm']];
    }
    if (count($sig_pts) >= 24) {
        [$slope] = linreg_slope($sig_pts);
        $delta_dB = $slope * ($opts['window-days'] * 86400);
        if ($delta_dB < -$opts['signal-drop-db']) {
            $kind   = 'signal_drop';
            $notes  = sprintf('Signal dropped %.1f dB over %d days (regression slope).',
                $delta_dB, $opts['window-days']);
            _link_alert_open($link, $kind, 'warn',
                end($sig_pts)['v'], $sig_pts[0]['v'], $notes, $opts['dry-run']);
            $opened++;
        } else {
            $resolved += _link_alert_resolve($link_id, 'signal_drop', $opts['dry-run']);
        }
    }

    /* ---------- 2. Link budget vs measured ---------- */
    if ($link['frequency_mhz'] && $link['distance_km'] && $link['ap_gain'] !== null
        && $link['cpe_gain'] !== null && $link['tx_power_dbm_local'] !== null
        && $link['signal_dbm'] !== null) {
        $fsl_db = 20 * log10((float)$link['distance_km'] * 1000)
                + 20 * log10((float)$link['frequency_mhz'] * 1e6)
                + 20 * log10(4 * M_PI / 299_792_458);
        $budget_dBm = (float)$link['tx_power_dbm_local']
                    + (float)$link['ap_gain'] + (float)$link['cpe_gain']
                    - $fsl_db;
        $measured = (int)$link['signal_dbm'];
        $shortfall = $budget_dBm - $measured;
        if ($shortfall > $opts['budget-margin-db']) {
            $notes = sprintf('Measured %d dBm vs link budget %.1f dBm (%.1f dB worse than expected).',
                $measured, $budget_dBm, $shortfall);
            _link_alert_open($link, 'link_budget',
                $shortfall > 12 ? 'crit' : 'warn',
                $measured, $budget_dBm, $notes, $opts['dry-run']);
            $opened++;
        } else {
            $resolved += _link_alert_resolve($link_id, 'link_budget', $opts['dry-run']);
        }

        /* ---------- 2b. Fresnel-zone obstruction ----------
           When the shortfall is very large (default ≥15 dB) on a link
           where everything else looks good, the most likely culprit is
           a physical obstruction in the Fresnel zone — trees, buildings,
           a new pole someone put up. Same shortfall + same baseline as
           link_budget but a separate alert kind so the NOC can prioritise
           "send a climber" vs "tweak settings". */
        if ($shortfall >= $opts['fresnel-margin-db']) {
            $r60_m = 0.6 * 8.657
                   * sqrt((float)$link['distance_km']
                        / max(0.01, (float)$link['frequency_mhz'] / 1000.0));
            $notes = sprintf(
                'Severe shortfall (%.1f dB) — likely Fresnel obstruction. Recommended ≥60%% clearance: %.1f m at midpoint over %.2f km @ %d MHz.',
                $shortfall, $r60_m, (float)$link['distance_km'], (int)$link['frequency_mhz']
            );
            _link_alert_open($link, 'fresnel_blocked',
                $shortfall > 25 ? 'crit' : 'warn',
                $measured, $budget_dBm, $notes, $opts['dry-run']);
            $opened++;
        } else {
            $resolved += _link_alert_resolve($link_id, 'fresnel_blocked', $opts['dry-run']);
        }
    }

    /* ---------- 3. Capacity saturation ---------- */
    $util_pts = 0; $util_sum = 0;
    foreach ($samples as $s) {
        $cap = (float)$s['capacity_local_mbps'];
        $tput = (float)$s['throughput_local_mbps'];
        if ($cap > 0) {
            $util_sum += $tput / $cap;
            $util_pts++;
        }
    }
    if ($util_pts >= 24) {
        $avg_pct = ($util_sum / $util_pts) * 100;
        if ($avg_pct >= $opts['capacity-pct']) {
            $notes = sprintf('Sustained %.0f%% capacity utilisation over %d days. Sector likely needs splitting.',
                $avg_pct, $opts['window-days']);
            _link_alert_open($link, 'capacity_saturation', 'warn',
                $avg_pct, $opts['capacity-pct'], $notes, $opts['dry-run']);
            $opened++;
        } else {
            $resolved += _link_alert_resolve($link_id, 'capacity_saturation', $opts['dry-run']);
        }
    }
}

if (!$opts['quiet']) {
    printf("[check-link-health] opened=%d resolved=%d links_seen=%d%s\n",
        $opened, $resolved, count($links), $opts['dry-run'] ? ' (dry-run)' : '');
}
exit(0);

/* ---------------------------------------------------------- helpers */

function _link_alert_open(array $link, string $kind, string $severity,
    float $observed, float $expected, string $notes, bool $dry_run): void
{
    if ($dry_run) {
        printf("  [%s] link #%d %s: %s\n", $kind, (int)$link['id'], $severity, $notes);
        return;
    }
    // Dedup: if an active alert of this kind already exists, just refresh its notes.
    $stmt = pdo()->prepare(
        "SELECT id FROM link_alerts WHERE link_id = ? AND kind = ? AND resolved_at IS NULL LIMIT 1"
    );
    $stmt->execute([(int)$link['id'], $kind]);
    if ($existing = $stmt->fetchColumn()) {
        pdo()->prepare("UPDATE link_alerts SET notes = ?, severity = ? WHERE id = ?")
            ->execute([$notes, $severity, (int)$existing]);
        return;
    }
    pdo()->prepare(
        "INSERT INTO link_alerts (link_id, kind, severity, observed_db, expected_db, notes)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([(int)$link['id'], $kind, $severity, $observed, $expected, $notes]);
    $alert_id = (int)pdo()->lastInsertId();

    audit_log('link_alert.open', [
        'target_type' => 'wireless_link', 'target_id' => (int)$link['id'],
        'meta' => ['kind' => $kind, 'severity' => $severity, 'alert_id' => $alert_id],
    ]);

    // Open a customer ticket for the link-affecting kinds, page the NOC.
    if (in_array($kind, ['signal_drop', 'link_budget', 'fresnel_blocked'], true) && !empty($link['customer_id'])) {
        $subj = match ($kind) {
            'signal_drop'     => 'Signal degradation on your wireless link',
            'link_budget'     => 'Wireless link underperforming budget',
            'fresnel_blocked' => 'Path obstruction suspected on your wireless link',
        };
        $body = $notes . "\n\nLink: " . ($link['ap_name'] ?? '?') . ' → '
              . ($link['cpe_name'] ?? '?') . "\nA technician will be in touch.";
        try {
            $tid = ticket_create_system((int)$link['customer_id'], $subj, $body);
            pdo()->prepare("UPDATE link_alerts SET ticket_id = ? WHERE id = ?")
                ->execute([$tid, $alert_id]);
            // And tell the customer.
            $cust = pdo()->prepare("SELECT * FROM users WHERE id = ?");
            $cust->execute([(int)$link['customer_id']]);
            $crow = $cust->fetch();
            if ($crow) {
                notify_send($crow, 'link.signal_drop', ['drop_db' => abs((int)$observed - (int)$expected)]);
            }
        } catch (Throwable $e) {
            error_log('link_alert ticket creation failed: ' . $e->getMessage());
        }
    }
}

function _link_alert_resolve(int $link_id, string $kind, bool $dry_run): int {
    if ($dry_run) return 0;
    $stmt = pdo()->prepare(
        "UPDATE link_alerts
            SET resolved_at = NOW()
          WHERE link_id = ? AND kind = ? AND resolved_at IS NULL"
    );
    $stmt->execute([$link_id, $kind]);
    $n = $stmt->rowCount();
    if ($n > 0) {
        audit_log('link_alert.resolve', [
            'target_type' => 'wireless_link', 'target_id' => $link_id,
            'meta' => ['kind' => $kind, 'count' => $n],
        ]);
    }
    return $n;
}
