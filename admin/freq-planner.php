<?php
/**
 * Frequency planner — UISP-equivalent and then some.
 *
 * Builds a sector × channel matrix coloured by accumulated
 * rf_environment_samples interference over the last 24 hours, and
 * recommends the least-noisy channel per sector. Click "Apply
 * recommendation" to enqueue a Phase-4 job per sector — the
 * apply-wireless-changes worker handles the coordinated AP↔CPE move
 * and rollback if something goes wrong.
 *
 * Channel grid is the standard 5 GHz 20 MHz subset; widen it once we
 * support 6 GHz or 80 MHz blocks (TODO Phase 5 polish).
 */
$page_title = 'Frequency planner';
$active_key = 'freq-planner';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/sectors.php';

$self = '/admin/freq-planner.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_rec') {
        $sector_id = (int)($_POST['sector_id'] ?? 0);
        $freq      = (int)($_POST['frequency_mhz'] ?? 0);
        $width     = (int)($_POST['channel_width_mhz'] ?? 20);
        if ($sector_id && $freq) {
            $job_id = wireless_change_job_enqueue('sector', $sector_id, (int)$user['id'], [
                'frequency_mhz'     => $freq,
                'channel_width_mhz' => $width,
            ]);
            audit_log('freq_planner.queued', [
                'target_type' => 'sector', 'target_id' => $sector_id,
                'meta' => ['job_id' => $job_id, 'frequency_mhz' => $freq, 'channel_width_mhz' => $width],
            ]);
            flash('success', "Queued change job #$job_id for sector #$sector_id → $freq MHz.");
        }
        header('Location: ' . $self);
        exit;
    }
}

$sectors = sectors_all(null);

// 5 GHz channels: 5180..5825 MHz in 20 MHz steps. Drop DFS-only
// frequencies for sectors with dfs_enabled=0 (we ignore that nuance
// here; planner is informational).
$channels = [];
for ($mhz = 5180; $mhz <= 5825; $mhz += 20) $channels[] = $mhz;

// Per-sector RF map: aggregate the worst RSSI seen on each channel
// over the last 24h, from the AP device's rf_environment_samples.
$pdo = pdo();
$grid = []; // [sector_id][freq_mhz] = worst rssi (null if no data)
foreach ($sectors as $s) {
    if (empty($s['ap_device_id'])) {
        $grid[$s['id']] = array_fill_keys($channels, null);
        continue;
    }
    $stmt = $pdo->prepare(
        "SELECT freq_mhz, MAX(rssi_dbm) AS worst
           FROM rf_environment_samples
          WHERE device_id = ? AND polled_at >= NOW() - INTERVAL 24 HOUR
          GROUP BY freq_mhz"
    );
    $stmt->execute([(int)$s['ap_device_id']]);
    $row = array_fill_keys($channels, null);
    foreach ($stmt->fetchAll() as $r) {
        $f = (int)$r['freq_mhz'];
        $w = (int)$r['worst'];
        // Snap the scan freq to the closest 20 MHz channel.
        $closest = $channels[0];
        foreach ($channels as $c) if (abs($c - $f) < abs($closest - $f)) $closest = $c;
        if ($row[$closest] === null || $w > $row[$closest]) $row[$closest] = $w;
    }
    $grid[$s['id']] = $row;
}

$cell_colour = function (?int $rssi): string {
    if ($rssi === null) return '#f6f7f8';
    // -100 dBm → quiet (green), -50 dBm → busy (red).
    $clamp = max(-100, min(-30, $rssi));
    $busy  = (-30 - $clamp) / 70; // 0..1, 1=busy
    $r = (int)round(34   + $busy * (212 - 34));
    $g = (int)round(197  - $busy * (197 - 68));
    $b = (int)round(94   - $busy * (94  - 68));
    return sprintf('rgb(%d,%d,%d)', $r, $g, $b);
};

$best_for = function (array $row) use ($channels): ?int {
    $best = null; $best_rssi = null;
    foreach ($channels as $c) {
        $v = $row[$c];
        $score = $v === null ? -101 : $v; // unscanned = quietest
        if ($best === null || $score < $best_rssi) {
            $best = $c; $best_rssi = $score;
        }
    }
    return $best;
};

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<style>
  .fp-grid { width:100%; border-collapse:collapse; font-size:11px; }
  .fp-grid th, .fp-grid td { padding:3px 5px; text-align:center; border:1px solid #fff; min-width:38px; }
  .fp-grid th { background:#f4f4f6; color:#456; font-weight:500; }
  .fp-cell.current { outline:2px solid #222; outline-offset:-2px; }
  .fp-cell.recommend { outline:2px solid #0c8; outline-offset:-2px; }
  .fp-cell { color:#fff; font-size:10px; }
  .fp-legend span { display:inline-block; padding:2px 8px; border-radius:6px; color:#fff; font-size:11px; margin-right:8px; }
</style>

<div class="portal-head">
  <h1>Frequency planner</h1>
  <p class="portal-sub">Channel × sector matrix coloured by 24h rf_environment_samples interference. Black outline marks the sector's current channel; green outline marks the recommended quietest channel. Click "Apply recommendation" to queue a coordinated AP↔CPE move.</p>
</div>

<div class="portal-card">
  <h2>Legend</h2>
  <div class="fp-legend">
    <span style="background:rgb(34,197,94);">quiet</span>
    <span style="background:rgb(140,140,90);">moderate</span>
    <span style="background:rgb(212,68,68);">busy</span>
    <span style="background:#f6f7f8;color:#789;">unscanned</span>
  </div>
</div>

<?php if (!$sectors): ?>
  <div class="portal-card">
    <p class="muted">No sectors configured yet — start in <a href="/admin/sectors.php">/admin/sectors.php</a>.</p>
  </div>
<?php else: ?>
<div class="portal-card">
  <h2>5 GHz channel utilisation (last 24h)</h2>
  <div style="overflow-x:auto;">
  <table class="fp-grid">
    <thead>
      <tr>
        <th style="text-align:left;">Sector</th>
        <th style="text-align:left;">Current</th>
        <?php foreach ($channels as $c): ?><th><?= $c ?></th><?php endforeach; ?>
        <th>Recommend</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sectors as $s):
        $row = $grid[$s['id']];
        $cur = $s['frequency_mhz'] ? (int)$s['frequency_mhz'] : null;
        $rec = $best_for($row);
      ?>
        <tr>
          <td style="text-align:left;"><strong><?= $h($s['name']) ?></strong>
            <br><small class="muted"><?= $h($s['tower_name'] ?? '#' . $s['tower_id']) ?></small>
          </td>
          <td style="text-align:left;">
            <?= $cur !== null ? $cur . ' MHz' : '—' ?>
            <?php if ($s['channel_width_mhz']): ?>
              <br><small class="muted">@ <?= (int)$s['channel_width_mhz'] ?> MHz</small>
            <?php endif; ?>
          </td>
          <?php foreach ($channels as $c):
            $rssi = $row[$c];
            $bg = $cell_colour($rssi);
            $cls = 'fp-cell';
            if ($cur !== null && $cur === $c) $cls .= ' current';
            if ($rec !== null && $rec === $c) $cls .= ' recommend';
          ?>
            <td class="<?= $cls ?>" style="background:<?= $bg ?>;"
                title="<?= $rssi !== null ? $rssi . ' dBm worst' : 'no scan data' ?>">
              <?= $rssi !== null ? $rssi : '·' ?>
            </td>
          <?php endforeach; ?>
          <td style="text-align:left;">
            <?php if ($rec !== null && $rec !== $cur): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Queue freq move on <?= $h($s['name']) ?>: <?= $cur ?? '?' ?> → <?= $rec ?> MHz?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply_rec">
                <input type="hidden" name="sector_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="frequency_mhz" value="<?= $rec ?>">
                <input type="hidden" name="channel_width_mhz" value="<?= (int)($s['channel_width_mhz'] ?: 20) ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= $rec ?> MHz</button>
              </form>
            <?php elseif ($rec === $cur && $rec !== null): ?>
              <small class="muted">already optimal</small>
            <?php else: ?>
              <small class="muted">—</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted" style="margin-top:12px;">Recommendation logic: pick the 20 MHz channel with the lowest worst-RSSI seen in the last 24 hours. Sectors with no RF scan data (poll worker hasn't run yet, or AirOS scan endpoint disabled) show "—" everywhere.</p>
</div>
<?php endif; ?>
