<?php
/**
 * Single-device health view.
 *
 * Header strip: vendor / model / role / site / status pill.
 * Stats:        last-24h uptime %, average RTT, sample count, last
 *               seen, last status flip.
 * Sparkline:    inline SVG of RTT over the last 24 h, with offline
 *               cycles drawn as red bars on the baseline.
 * Table:        most recent 100 samples for quick scrolling.
 *
 * No JS — server-rendered SVG. Phase 8 is when we add hourly
 * aggregation; until then, 24 h at 5-minute polls is ~288 points
 * which renders fine inline.
 */
$page_title = 'Device';
$active_key = 'devices';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sites.php';

$id = (int)($_GET['id'] ?? 0);
$d  = $id ? device_find($id) : null;

if (!$d) {
    flash('error', 'Device not found.');
    header('Location: /admin/devices.php');
    exit;
}

$site_name = null;
if ($d['site_id']) {
    $stmt = pdo()->prepare("SELECT name FROM sites WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$d['site_id']]);
    $site_name = $stmt->fetchColumn() ?: null;
}

// Last 24 h of samples, oldest-first for the sparkline.
$stmt = pdo()->prepare(
    "SELECT polled_at, status, rtt_ms FROM device_health
      WHERE device_id = ? AND polled_at >= (NOW() - INTERVAL 24 HOUR)
      ORDER BY polled_at ASC"
);
$stmt->execute([$id]);
$samples_24h = $stmt->fetchAll();

$total_24h   = count($samples_24h);
$online_24h  = 0;
$rtts        = [];
foreach ($samples_24h as $s) {
    if ($s['status'] === 'online') $online_24h++;
    if ($s['rtt_ms'] !== null)     $rtts[] = (float)$s['rtt_ms'];
}
$uptime_pct  = $total_24h > 0 ? round(($online_24h / $total_24h) * 100, 1) : null;
$avg_rtt     = $rtts ? round(array_sum($rtts) / count($rtts), 2) : null;
$max_rtt     = $rtts ? max($rtts) : null;
$min_rtt     = $rtts ? min($rtts) : null;

// Most recent 100 for the table (newest-first).
$recent_table = device_recent_health($id, 100);

$status_pill = function (string $status): string {
    $colors = ['online' => '#0c8', 'offline' => '#d44', 'unknown' => '#888', 'retired' => '#555'];
    $bg = $colors[$status] ?? '#888';
    return '<span style="display:inline-block;background:' . $bg
        . ';color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;">'
        . htmlspecialchars($status) . '</span>';
};

/* ---------- sparkline ---------- */
// Build an inline SVG. X axis = time, Y axis = RTT (clamped to a
// floor / ceiling so a single 2 s timeout doesn't squash the line).
$svg_w = 760;
$svg_h = 110;
$svg_pad_top = 8;
$svg_pad_bot = 14;
$plot_h = $svg_h - $svg_pad_top - $svg_pad_bot;

$sparkline = '';
if ($total_24h > 0) {
    $now_ts   = time();
    $start_ts = $now_ts - 86400;

    // Cap the upper bound so a flat line of 1-3 ms is still readable
    // and a single 800 ms RTT doesn't crush everything else flat.
    $rtt_cap = $rtts ? min(max(50, ($max_rtt ?? 50)), 250) : 50;

    $points    = [];
    $offlines  = [];
    foreach ($samples_24h as $s) {
        $t = strtotime((string)$s['polled_at']);
        if ($t < $start_ts) continue;
        $x = (int)round((($t - $start_ts) / 86400) * $svg_w);
        if ($s['status'] === 'online' && $s['rtt_ms'] !== null) {
            $r = max(0, min((float)$s['rtt_ms'], $rtt_cap));
            $y = $svg_pad_top + (int)round($plot_h - ($r / $rtt_cap) * $plot_h);
            $points[] = "$x,$y";
        } else {
            $offlines[] = $x;
        }
    }

    $path = '';
    if ($points) {
        $path = '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#0c8" stroke-width="1.5"/>';
    }
    $bars = '';
    foreach ($offlines as $x) {
        $bars .= '<rect x="' . ($x - 1) . '" y="' . ($svg_h - $svg_pad_bot - 4) . '" width="2" height="6" fill="#d44"/>';
    }
    // Faint grid line at 50% of the cap so the eye has a reference.
    $mid_y = $svg_pad_top + (int)round($plot_h / 2);
    $grid  = '<line x1="0" x2="' . $svg_w . '" y1="' . $mid_y . '" y2="' . $mid_y . '" stroke="rgba(255,255,255,0.08)" stroke-dasharray="2,3"/>';
    $axis  = '<line x1="0" x2="' . $svg_w . '" y1="' . ($svg_h - $svg_pad_bot) . '" y2="' . ($svg_h - $svg_pad_bot) . '" stroke="rgba(255,255,255,0.15)"/>';
    $cap_label = '<text x="4" y="' . ($svg_pad_top + 10) . '" fill="rgba(255,255,255,0.4)" font-size="10">' . htmlspecialchars((string)$rtt_cap) . ' ms</text>';
    $now_label = '<text x="' . ($svg_w - 30) . '" y="' . ($svg_h - 2) . '" fill="rgba(255,255,255,0.4)" font-size="10">now</text>';
    $start_label = '<text x="0" y="' . ($svg_h - 2) . '" fill="rgba(255,255,255,0.4)" font-size="10">−24 h</text>';

    $sparkline = '<svg viewBox="0 0 ' . $svg_w . ' ' . $svg_h . '" '
               . 'style="width:100%;height:auto;background:rgba(255,255,255,0.02);border-radius:6px;">'
               . $grid . $axis . $bars . $path . $cap_label . $now_label . $start_label
               . '</svg>';
}

$last_seen_age = '—';
if (!empty($d['last_seen_at'])) {
    $age = max(0, time() - strtotime((string)$d['last_seen_at']));
    if      ($age < 60)    $last_seen_age = $age . 's ago';
    elseif  ($age < 3600)  $last_seen_age = floor($age / 60)   . 'm ago';
    elseif  ($age < 86400) $last_seen_age = floor($age / 3600) . 'h ago';
    else                   $last_seen_age = floor($age / 86400) . 'd ago';
}
?>

<div class="portal-head">
  <h1>
    <?= htmlspecialchars($d['name']) ?>
    <span style="font-weight:normal;font-size:.6em;vertical-align:middle;"><?= $status_pill($d['status']) ?></span>
  </h1>
  <p class="portal-sub">
    <?= htmlspecialchars($d['vendor']) ?><?= $d['model'] ? ' · ' . htmlspecialchars($d['model']) : '' ?>
    &middot; role <?= htmlspecialchars($d['role']) ?>
    <?php if ($site_name): ?>
      &middot; site <?= htmlspecialchars($site_name) ?>
    <?php endif; ?>
    <?php if ($d['mgmt_ip']): ?>
      &middot; <code><?= htmlspecialchars($d['mgmt_ip']) ?><?= $d['mgmt_port'] ? ':' . (int)$d['mgmt_port'] : '' ?></code>
    <?php endif; ?>
  </p>
</div>

<p>
  <a href="/admin/devices.php" class="btn btn-ghost btn-sm">&larr; Back to devices</a>
</p>

<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Uptime (24 h)</span>
    <div class="card-num" style="color:<?= $uptime_pct === null ? 'var(--text-muted)' : ($uptime_pct >= 99 ? '#0c8' : ($uptime_pct >= 95 ? '#fbbf24' : '#d44')) ?>;">
      <?= $uptime_pct === null ? '—' : $uptime_pct . '%' ?>
    </div>
    <p class="card-sub muted"><?= $online_24h ?> / <?= $total_24h ?> samples online</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Avg RTT (24 h)</span>
    <div class="card-num"><?= $avg_rtt === null ? '—' : $avg_rtt . ' ms' ?></div>
    <p class="card-sub muted">
      <?php if ($min_rtt !== null && $max_rtt !== null): ?>
        min <?= number_format($min_rtt, 2) ?> &middot; max <?= number_format($max_rtt, 2) ?>
      <?php else: ?>
        no successful pings yet
      <?php endif; ?>
    </p>
  </div>
  <div class="portal-card">
    <span class="card-label">Last seen</span>
    <div class="card-num" style="font-size:1.4rem;"><?= htmlspecialchars($last_seen_age) ?></div>
    <p class="card-sub muted"><?= htmlspecialchars($d['last_seen_at'] ?: 'never polled successfully') ?></p>
  </div>
</div>

<div class="portal-card">
  <h2>RTT &middot; last 24 hours</h2>
  <?php if ($total_24h === 0): ?>
    <p class="muted" style="margin:0;">No samples yet. Once <code>bin/poll-devices.php</code> has run a couple of times, this will fill in.</p>
  <?php else: ?>
    <?= $sparkline ?>
    <p class="muted small" style="margin:6px 0 0;">Green line: ICMP RTT, capped at the upper number. Red ticks on the baseline mark cycles where the device was unreachable.</p>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Recent samples <span class="muted">(<?= count($recent_table) ?>)</span></h2>
  <?php if (!$recent_table): ?>
    <p class="muted" style="margin:0;">No health samples on record yet.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Polled</th><th>Status</th><th style="text-align:right;">RTT (ms)</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recent_table as $r): ?>
          <tr>
            <td><small><?= htmlspecialchars($r['polled_at']) ?></small></td>
            <td><?= $status_pill($r['status']) ?></td>
            <td style="text-align:right;"><small><?= $r['rtt_ms'] !== null ? number_format((float)$r['rtt_ms'], 2) : '—' ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
