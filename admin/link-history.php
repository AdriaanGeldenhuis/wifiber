<?php
/**
 * Per-link history page — 7d / 30d trends for signal, SNR, throughput,
 * airtime, retries, ACK%. Complements the 24h live dashboard at
 * /admin/link-view.php with the longer-window picture an operator
 * needs to spot slow degradation (e.g. seasonal interference, gradual
 * antenna drift) that doesn't show up in a one-day window.
 *
 * Server-rendered SVG, downsampled to one bucket per hour for 7 days
 * and one bucket per 6 hours for 30 days so the page stays cheap.
 */
$page_title = 'Link history';
$active_key = 'links';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/devices.php';

$id   = (int)($_GET['id']   ?? 0);
$days = max(1, min(90, (int)($_GET['days'] ?? 7)));
$link = $id ? wireless_link_find($id) : null;
if (!$link) {
    echo '<div class="portal-card"><h2>Link not found</h2><p>Pick one from <a href="/admin/links.php">/admin/links.php</a>.</p></div>';
    return;
}

$ap  = device_find((int)$link['ap_device_id']);
$cpe = !empty($link['cpe_device_id']) ? device_find((int)$link['cpe_device_id']) : null;

// Bucket size — 1h for ≤7d, 6h for >7d. Keeps SVG path lengths
// reasonable regardless of polling cadence.
$bucket_minutes = $days <= 7 ? 60 : 360;
$bucket_format  = $bucket_minutes >= 360 ? '%Y-%m-%d %H:00' : '%Y-%m-%d %H:00';

$stmt = pdo()->prepare(
    "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(polled_at) / (?*60)) * (?*60)) AS bucket,
            AVG(signal_local_dbm)        AS signal_local_avg,
            MIN(signal_local_dbm)        AS signal_local_min,
            AVG(signal_remote_dbm)       AS signal_remote_avg,
            AVG(snr_local_db)            AS snr_local_avg,
            MIN(snr_local_db)            AS snr_local_min,
            AVG(ccq_pct)                 AS ccq_avg,
            AVG(COALESCE(throughput_local_mbps,0)
              + COALESCE(throughput_remote_mbps,0)) AS throughput_avg,
            MAX(COALESCE(throughput_local_mbps,0)
              + COALESCE(throughput_remote_mbps,0)) AS throughput_peak,
            AVG(airtime_local_pct)       AS airtime_local_avg,
            AVG(airtime_remote_pct)      AS airtime_remote_avg,
            AVG(tx_retries)              AS tx_retries_avg,
            AVG(rx_retries)              AS rx_retries_avg,
            AVG(ack_pct)                 AS ack_pct_avg,
            COUNT(*)                     AS samples
       FROM link_health_samples
      WHERE link_id = ?
        AND polled_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      GROUP BY bucket
      ORDER BY bucket ASC"
);
$stmt->execute([$bucket_minutes, $bucket_minutes, $id, $days]);
$rows = $stmt->fetchAll();

$alerts = pdo()->prepare(
    "SELECT id, kind, severity, opened_at, resolved_at, observed_db, expected_db, notes
       FROM link_alerts
      WHERE link_id = ? AND opened_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      ORDER BY opened_at DESC"
);
$alerts->execute([$id, $days]);
$alert_rows = $alerts->fetchAll();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

/* ---------- SVG sparkline helper ----------
   Renders a single-metric chart from ($rows, $col) into an inline SVG
   string. ymin/ymax can be passed to lock the scale (e.g. for SNR vs
   absolute dBm). Empty/null buckets render a gap rather than 0. */
$sparkline = function (array $rows, string $col, string $label, ?float $ymin = null, ?float $ymax = null, string $stroke = '#05DAFD', string $fill = 'rgba(5,218,253,.10)'): string {
    $w = 760; $h = 110; $pad_t = 14; $pad_b = 18;
    $plot_h = $h - $pad_t - $pad_b;

    $vals = array_filter(array_map(fn($r) => $r[$col], $rows), fn($v) => $v !== null);
    if (!$vals) {
        return '<div style="background:#0a0d12;border-radius:6px;padding:30px;text-align:center;color:rgba(255,255,255,.4);font-size:12px;">no ' . htmlspecialchars($label) . ' samples in this window</div>';
    }
    $auto_min = (float)min($vals);
    $auto_max = (float)max($vals);
    if ($auto_min === $auto_max) { $auto_min -= 1; $auto_max += 1; }
    $ymin = $ymin ?? $auto_min;
    $ymax = $ymax ?? $auto_max;
    if ($ymax <= $ymin) $ymax = $ymin + 1;

    $n = count($rows);
    $x_step = $n > 1 ? ($w - 12) / ($n - 1) : 0;
    $pts = []; $area_pts = [];
    foreach ($rows as $i => $r) {
        $v = $r[$col];
        if ($v === null) {
            // Break the path at gaps with an explicit "M" segment.
            $pts[] = null;
            continue;
        }
        $x = 6 + $i * $x_step;
        $y = $pad_t + $plot_h - (((float)$v - $ymin) / ($ymax - $ymin)) * $plot_h;
        $pts[] = number_format($x, 1) . ',' . number_format($y, 1);
    }
    // Build path with M-after-gap so the line doesn't connect across nulls.
    $path = '';
    $started = false;
    foreach ($pts as $p) {
        if ($p === null) { $started = false; continue; }
        $path .= ($started ? ' L ' : ' M ') . $p;
        $started = true;
    }

    $baseline_y = $pad_t + $plot_h;
    $svg  = '<svg width="100%" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" style="background:#0a0d12;border-radius:6px;">';
    $svg .= '<line x1="6" x2="' . ($w - 6) . '" y1="' . $baseline_y . '" y2="' . $baseline_y . '" stroke="rgba(255,255,255,.1)" stroke-width="1"/>';
    $svg .= '<path d="' . $path . '" fill="none" stroke="' . $stroke . '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>';
    $svg .= '<text x="6" y="' . ($pad_t + 8) . '" fill="rgba(255,255,255,.55)" font-size="10" font-family="Inter,sans-serif">' . htmlspecialchars($label) . ' · max ' . number_format((float)$ymax, 1) . '</text>';
    $svg .= '<text x="6" y="' . ($baseline_y - 4) . '" fill="rgba(255,255,255,.35)" font-size="10" font-family="Inter,sans-serif">' . number_format((float)$ymin, 1) . '</text>';
    $svg .= '</svg>';
    return $svg;
};

$total_samples = array_sum(array_column($rows, 'samples'));
?>

<div class="portal-head">
  <h1>Link history</h1>
  <p class="portal-sub">
    <a href="/admin/links.php">← All links</a>
    &nbsp;·&nbsp;
    <a href="/admin/link-view.php?id=<?= $id ?>">Live (24h) ↗</a>
    &nbsp;·&nbsp;
    <strong><?= $h($ap['name'] ?? '?') ?></strong>
    →
    <strong><?= $h($cpe['name'] ?? '(unattached)') ?></strong>
    &nbsp;·&nbsp;
    <?= $total_samples ?> samples in <?= $days ?>d
  </p>
</div>

<div class="portal-card" style="display:flex;gap:6px;align-items:center;">
  <strong style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">Window</strong>
  <a href="?id=<?= $id ?>&days=7"  class="btn btn-ghost btn-sm <?= $days === 7  ? 'map-mode-active' : '' ?>">7 days</a>
  <a href="?id=<?= $id ?>&days=14" class="btn btn-ghost btn-sm <?= $days === 14 ? 'map-mode-active' : '' ?>">14 days</a>
  <a href="?id=<?= $id ?>&days=30" class="btn btn-ghost btn-sm <?= $days === 30 ? 'map-mode-active' : '' ?>">30 days</a>
  <a href="?id=<?= $id ?>&days=90" class="btn btn-ghost btn-sm <?= $days === 90 ? 'map-mode-active' : '' ?>">90 days</a>
  <span style="margin-left:auto;color:var(--text-muted);font-size:12px;">
    bucket: <?= $bucket_minutes >= 60 ? ($bucket_minutes / 60) . 'h' : $bucket_minutes . 'm' ?>
  </span>
</div>

<?php if (!$rows): ?>
  <div class="portal-card">
    <div class="empty-state">
      <h3>No samples in this window</h3>
      <p>The wireless polling worker hasn't recorded any data for this link in the last <?= $days ?> days. Check that <code>bin/poll-wireless.php</code> is in cron and the link's AP/CPE devices have credentials saved.</p>
    </div>
  </div>
<?php else: ?>

<div class="portal-card">
  <h2>Signal &amp; SNR</h2>
  <h4 class="lv-label">Signal local (dBm) — average per bucket, gaps = no data</h4>
  <?= $sparkline($rows, 'signal_local_avg', 'signal local (dBm)', null, null, '#05DAFD') ?>
  <h4 class="lv-label" style="margin-top:14px;">SNR local (dB) — minimum per bucket (worst point of the period)</h4>
  <?= $sparkline($rows, 'snr_local_min', 'SNR local min (dB)', 0, null, '#22c55e') ?>
</div>

<div class="portal-card">
  <h2>Throughput &amp; airtime</h2>
  <h4 class="lv-label">Throughput peak (Mbps) — local + remote summed</h4>
  <?= $sparkline($rows, 'throughput_peak', 'throughput peak (Mbps)', 0, null, '#84cc16', 'rgba(132,204,22,.10)') ?>
  <h4 class="lv-label" style="margin-top:14px;">Airtime local (%)</h4>
  <?= $sparkline($rows, 'airtime_local_avg', 'airtime local (%)', 0, 100, '#eab308') ?>
</div>

<div class="portal-card">
  <h2>PHY counters</h2>
  <p class="muted"><small>Phase-25 PHY-level metrics — populated when the radio firmware exposes them. Older AirOS / RouterOS may not.</small></p>
  <h4 class="lv-label">ACK %</h4>
  <?= $sparkline($rows, 'ack_pct_avg', 'ACK (%)', 0, 100, '#22c55e') ?>
  <h4 class="lv-label" style="margin-top:14px;">TX retries (avg per bucket)</h4>
  <?= $sparkline($rows, 'tx_retries_avg', 'TX retries', 0, null, '#f97316') ?>
  <h4 class="lv-label" style="margin-top:14px;">RX retries (avg per bucket)</h4>
  <?= $sparkline($rows, 'rx_retries_avg', 'RX retries', 0, null, '#f97316') ?>
</div>

<?php endif; ?>

<?php if ($alert_rows): ?>
<div class="portal-card">
  <h2>Alerts in window <span class="muted">(<?= count($alert_rows) ?>)</span></h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Kind</th><th>Severity</th><th>Opened</th><th>Resolved</th><th>Observed</th><th>Expected</th><th>Notes</th></tr></thead>
    <tbody>
      <?php foreach ($alert_rows as $a):
        $sc = ['warn'=>'#fa0', 'crit'=>'#d44', 'info'=>'#888'][$a['severity']] ?? '#888'; ?>
        <tr>
          <td><strong><?= $h($a['kind']) ?></strong></td>
          <td><span style="display:inline-block;background:<?= $sc ?>;color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;"><?= $h($a['severity']) ?></span></td>
          <td><small><?= $h($a['opened_at']) ?></small></td>
          <td><small><?= $h($a['resolved_at'] ?? '—') ?></small></td>
          <td><?= $a['observed_db'] !== null ? number_format((float)$a['observed_db'], 1) : '—' ?></td>
          <td><?= $a['expected_db'] !== null ? number_format((float)$a['expected_db'], 1) : '—' ?></td>
          <td><small><?= $h($a['notes']) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
