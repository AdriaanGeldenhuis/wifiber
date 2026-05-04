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
require_once __DIR__ . '/../auth/totp.php';

$self = '/admin/freq-planner.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_rec') {
        $sector_id = (int)($_POST['sector_id'] ?? 0);
        $freq      = (int)($_POST['frequency_mhz'] ?? 0);
        $width     = (int)($_POST['channel_width_mhz'] ?? 20);
        if (!totp_require_step_up($user, (string)($_POST['totp_code'] ?? ''))) {
            flash('error', 'Two-factor code is required for push-to-radio actions.');
            header('Location: ' . $self);
            exit;
        }
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

$band = $_GET['band'] ?? '5GHz';
$channels = [];
switch ($band) {
    case '2.4GHz':
        // 2.4 GHz channels 1-13 in 5 MHz steps (centre frequencies).
        for ($mhz = 2412; $mhz <= 2472; $mhz += 5) $channels[] = $mhz;
        break;
    case '6GHz':
        // U-NII-5 + 6 + 7 + 8: 5945..7125 MHz in 20 MHz steps.
        for ($mhz = 5945; $mhz <= 7125; $mhz += 20) $channels[] = $mhz;
        break;
    case '60GHz':
        // 802.11ad/ay: 4 channels at 2.16 GHz each.
        $channels = [58320, 60480, 62640, 64800];
        break;
    case '5GHz':
    default:
        // 5 GHz channels: 5180..5825 MHz in 20 MHz steps.
        for ($mhz = 5180; $mhz <= 5825; $mhz += 20) $channels[] = $mhz;
}
// Filter sectors to the chosen band so the matrix isn't a sea of greys.
$sectors = array_values(array_filter($sectors, fn ($s) => $s['band'] === $band));

// Per-sector RF map: aggregate the worst RSSI seen on each channel
// over the last 24h, from the AP device's rf_environment_samples.
// Also pull MAX(polled_at) so the stale-data banner has something to
// compare against.
$pdo = pdo();
$grid = []; // [sector_id][freq_mhz] = worst rssi (null if no data)
$last_scan_by_sector = []; // [sector_id] = DATETIME or null
foreach ($sectors as $s) {
    if (empty($s['ap_device_id'])) {
        $grid[$s['id']] = array_fill_keys($channels, null);
        $last_scan_by_sector[$s['id']] = null;
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

    $last = $pdo->prepare(
        "SELECT MAX(polled_at) FROM rf_environment_samples WHERE device_id = ?"
    );
    $last->execute([(int)$s['ap_device_id']]);
    $lv = $last->fetchColumn();
    $last_scan_by_sector[$s['id']] = $lv ?: null;
}

// Live-detected DFS holds, indexed by frequency for fast cell lookup.
// Combined with the static is_dfs_channel() rule below.
$active_dfs = [];
foreach (pdo()->query(
    "SELECT freq_mhz, MAX(blocked_until) AS until_at
       FROM dfs_channel_events
      WHERE blocked_until > NOW()
      GROUP BY freq_mhz"
)->fetchAll() as $r) {
    $active_dfs[(int)$r['freq_mhz']] = (string)$r['until_at'];
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

// Recommendation logic — pick the quietest channel, but skip:
//   1. DFS-blocked channels (live radar event in last 30m)
//   2. Statically-DFS channels unless $allow_dfs=true
//   3. Channels where a neighbour sector on the same band is already
//      broadcasting an overlapping window (cross-tower co-channel guard)
$allow_dfs = !empty($_GET['allow_dfs']);
$best_for = function (array $row, int $sector_id, string $band, int $width)
            use ($channels, $active_dfs, $allow_dfs): ?int {
    $candidates = [];
    foreach ($channels as $c) {
        if (isset($active_dfs[$c])) continue;
        if (!$allow_dfs && is_dfs_channel($c, $width)) continue;
        // Score: actual RSSI when known, -101 when unscanned (treat as
        // quietest), then penalise channels with active neighbour
        // conflicts so we prefer "clean" ones at equal RSSI.
        $rssi = $row[$c];
        $score = $rssi === null ? -101 : $rssi;
        $hits = freq_planner_neighbour_conflicts($sector_id, $band, $c, $width);
        if ($hits) $score += 30; // penalise but don't exclude entirely
        $candidates[] = ['freq' => $c, 'score' => $score, 'hits' => $hits];
    }
    if (!$candidates) return null;
    usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
    return $candidates[0]['freq'];
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
  <p class="portal-sub">Channel × sector matrix coloured by 24h rf_environment_samples interference. Black outline marks the sector's current channel; green outline marks the recommended quietest channel. Click "Apply recommendation" to queue a coordinated AP↔CPE move.
    &nbsp;·&nbsp;
    <?php if ($allow_dfs): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['allow_dfs' => 0])) ?>">Hide DFS channels</a>
    <?php else: ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['allow_dfs' => 1])) ?>">Allow DFS in recommendations</a>
    <?php endif; ?>
  </p>
</div>

<?php
// Stale-data banner — fire when at least one sector's most recent RF
// scan is older than 48 hours. The frequency recommendation only
// reflects what we last saw, so flag stale data loudly so the operator
// doesn't blindly trust a recommendation backed by week-old samples.
$stale_threshold = strtotime('-48 hours');
$stale_sectors = [];
$ever_scanned  = 0;
foreach ($last_scan_by_sector as $sid => $ts) {
    if ($ts === null) continue;
    $ever_scanned++;
    if (strtotime($ts) < $stale_threshold) $stale_sectors[$sid] = $ts;
}
$never_scanned = count($last_scan_by_sector) - $ever_scanned;
if ($stale_sectors || $never_scanned > 0): ?>
<div class="portal-card" style="border-left:3px solid #fa0;background:rgba(255,170,0,.06);">
  <h3 style="color:#fa0;margin-top:0;">⚠ RF scan data is stale</h3>
  <p>
    <?php if ($stale_sectors): ?>
      <strong><?= count($stale_sectors) ?></strong> sector<?= count($stale_sectors) === 1 ? '' : 's' ?>
      haven't logged a passive scan in 48+ hours.
    <?php endif; ?>
    <?php if ($never_scanned > 0): ?>
      <strong><?= $never_scanned ?></strong> sector<?= $never_scanned === 1 ? ' has' : 's have' ?> never been scanned (no AP credentials, or scan endpoint unsupported).
    <?php endif; ?>
    Recommendations based on stale data are unreliable — re-run <code>bin/poll-wireless.php</code>
    or check vendor <code>scan.cgi</code> permissions before applying any move.
  </p>
</div>
<?php endif; ?>

<div class="portal-card">
  <h2>Band</h2>
  <form method="get" class="form" style="display:flex;gap:6px;align-items:center;">
    <?php foreach (['2.4GHz','5GHz','6GHz','60GHz'] as $b): ?>
      <a href="?band=<?= urlencode($b) ?>"
         class="btn btn-<?= $band === $b ? 'primary' : 'ghost' ?> btn-sm"><?= htmlspecialchars($b) ?></a>
    <?php endforeach; ?>
  </form>
</div>

<div class="portal-card">
  <h2>Legend</h2>
  <div class="fp-legend">
    <span style="background:rgb(34,197,94);">quiet</span>
    <span style="background:rgb(140,140,90);">moderate</span>
    <span style="background:rgb(212,68,68);">busy</span>
    <span style="background:#f6f7f8;color:#789;">unscanned</span>
    <span style="background:#444;color:#fff;">DFS</span>
    <span style="background:#7a4dff;color:#fff;">DFS held</span>
    <span style="outline:2px solid #222;outline-offset:-2px;background:#fff;color:#222;padding:2px 8px;">current</span>
    <span style="outline:2px solid #0c8;outline-offset:-2px;background:#fff;color:#222;padding:2px 8px;">recommended</span>
  </div>
</div>

<?php if (!$sectors): ?>
  <div class="portal-card">
    <p class="muted">No sectors configured yet — start in <a href="/admin/sectors.php">/admin/sectors.php</a>.</p>
  </div>
<?php else: ?>
<div class="portal-card">
  <h2><?= htmlspecialchars($band) ?> channel utilisation (last 24h)</h2>
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
        $width = (int)($s['channel_width_mhz'] ?: 20);
        $rec = $best_for($row, (int)$s['id'], $band, $width);
        $neighbour_conflicts = $rec !== null ? freq_planner_neighbour_conflicts((int)$s['id'], $band, $rec, $width) : [];
        $current_snr = freq_planner_sector_snr((int)$s['id']);
        $tx_suggestion = freq_planner_tx_power_suggestion(
            $s['tx_power_dbm'] !== null ? (int)$s['tx_power_dbm'] : null,
            $current_snr
        );
        $last_scan = $last_scan_by_sector[$s['id']] ?? null;
      ?>
        <tr>
          <td style="text-align:left;"><strong><?= $h($s['name']) ?></strong>
            <br><small class="muted"><?= $h($s['tower_name'] ?? '#' . $s['tower_id']) ?></small>
            <?php if ($last_scan === null): ?>
              <br><small style="color:#fa0;">never scanned</small>
            <?php elseif (strtotime($last_scan) < $stale_threshold): ?>
              <br><small style="color:#fa0;">last scan <?= $h($last_scan) ?></small>
            <?php endif; ?>
          </td>
          <td style="text-align:left;">
            <?= $cur !== null ? $cur . ' MHz' : '—' ?>
            <?php if ($s['channel_width_mhz']): ?>
              <br><small class="muted">@ <?= (int)$s['channel_width_mhz'] ?> MHz</small>
            <?php endif; ?>
            <?php if ($cur !== null && (is_dfs_channel($cur, $width) || isset($active_dfs[$cur]))): ?>
              <br><small style="color:#7a4dff;">DFS<?= isset($active_dfs[$cur]) ? ' held' : '' ?></small>
            <?php endif; ?>
            <?php if ($s['tx_power_dbm'] !== null): ?>
              <br><small class="muted"><?= (int)$s['tx_power_dbm'] ?> dBm</small>
            <?php endif; ?>
          </td>
          <?php foreach ($channels as $c):
            $rssi = $row[$c];
            $is_dfs    = is_dfs_channel($c, $width);
            $is_held   = isset($active_dfs[$c]);
            // DFS cells: dark grey for static-DFS, purple for live-held.
            // Override the RSSI colour so the operator can't accidentally
            // pick one even if it happens to be quiet.
            if      ($is_held) $bg = '#7a4dff';
            elseif  ($is_dfs)  $bg = '#444';
            else               $bg = $cell_colour($rssi);
            $cls = 'fp-cell';
            if ($cur !== null && $cur === $c) $cls .= ' current';
            if ($rec !== null && $rec === $c) $cls .= ' recommend';
            $title = $rssi !== null ? $rssi . ' dBm worst' : 'no scan data';
            if ($is_held) $title .= ' · DFS hold-down until ' . $active_dfs[$c];
            elseif ($is_dfs) $title .= ' · DFS channel (radar zone)';
          ?>
            <td class="<?= $cls ?>" style="background:<?= $bg ?>;" title="<?= $h($title) ?>">
              <?= $is_held ? '⏳' : ($is_dfs ? '⚠' : ($rssi !== null ? $rssi : '·')) ?>
            </td>
          <?php endforeach; ?>
          <td style="text-align:left;min-width:180px;">
            <?php if ($rec !== null && $rec !== $cur): ?>
              <form method="post" style="display:inline"
                    onsubmit="<?= !empty($user['totp_enabled'])
                        ? "var c=prompt('Two-factor code:');if(!c)return false;this.totp_code.value=c;"
                        : '' ?>return confirm('Queue freq move on <?= $h($s['name']) ?>: <?= $cur ?? '?' ?> → <?= $rec ?> MHz?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply_rec">
                <input type="hidden" name="sector_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="frequency_mhz" value="<?= $rec ?>">
                <input type="hidden" name="channel_width_mhz" value="<?= $width ?>">
                <input type="hidden" name="totp_code" value="">
                <button class="btn btn-primary btn-sm" type="submit"><?= $rec ?> MHz</button>
              </form>
              <?php if ($neighbour_conflicts): ?>
                <br><small style="color:#f97316;">⚠ overlaps <?= count($neighbour_conflicts) ?> neighbour<?= count($neighbour_conflicts) === 1 ? '' : 's' ?></small>
                <details style="display:inline-block;">
                  <summary style="cursor:pointer;color:#888;font-size:11px;">why?</summary>
                  <ul style="margin:4px 0;padding-left:16px;font-size:11px;">
                    <?php foreach (array_slice($neighbour_conflicts, 0, 3) as $nc): ?>
                      <li><?= $h($nc['sector_name']) ?> (<?= $h($nc['tower_name']) ?>) ·
                        <?= $nc['frequency_mhz'] ?> MHz · <?= $nc['distance_km'] ?> km</li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php endif; ?>
            <?php elseif ($rec === $cur && $rec !== null): ?>
              <small class="muted">already optimal</small>
            <?php else: ?>
              <small class="muted">—</small>
            <?php endif; ?>
            <?php if ($tx_suggestion): ?>
              <br><small style="color:#0a8;" title="<?= $h($tx_suggestion['reason']) ?>">
                ⓘ TX <?= $tx_suggestion['recommended_dbm'] ?> dBm
                <?= $s['tx_power_dbm'] !== null && $tx_suggestion['recommended_dbm'] < (int)$s['tx_power_dbm']
                    ? '(↓' . ((int)$s['tx_power_dbm'] - $tx_suggestion['recommended_dbm']) . ')' : '' ?>
              </small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted" style="margin-top:12px;">Recommendation logic: pick the lowest worst-RSSI 20 MHz channel from the last 24 h, skipping DFS (unless allowed) and de-prioritising channels that overlap with neighbour sectors within 10 km.
    TX-power hints reduce power when SNR has &gt;6 dB headroom over the 25 dB target — lower power means less interference contributed back to the area.</p>
</div>

<?php
// Cross-band hint — for each sector on this band, compare its
// recommended channel's noise floor against the same AP's quietest
// observation on a different band. If the alt band is materially
// cleaner the planner suggests considering a band swap. Only meaningful
// when a sector has scan samples on both bands (i.e. the radio is dual-
// band capable AND the operator polled both at some point).
$alt_band = match ($band) {
    '5GHz'   => '6GHz',
    '6GHz'   => '5GHz',
    default  => null,
};
if ($alt_band) {
    $cross = [];
    foreach ($sectors as $s) {
        if (empty($s['ap_device_id'])) continue;
        // Best (lowest) RSSI on the current band.
        $cur_best = null;
        foreach ($grid[$s['id']] as $c => $v) if ($v !== null && ($cur_best === null || $v < $cur_best)) $cur_best = $v;
        // Best on the alt band.
        $stmt = $pdo->prepare(
            "SELECT MIN(rssi_dbm) FROM rf_environment_samples
              WHERE device_id = ? AND polled_at >= NOW() - INTERVAL 24 HOUR
                AND freq_mhz BETWEEN ? AND ?"
        );
        [$lo, $hi] = $alt_band === '6GHz' ? [5945, 7125] : [5180, 5825];
        $stmt->execute([(int)$s['ap_device_id'], $lo, $hi]);
        $alt_best = $stmt->fetchColumn();
        if ($alt_best === false || $alt_best === null) continue;
        if ($cur_best !== null && (int)$alt_best < $cur_best - 10) {
            $cross[] = [
                'sector_id'   => (int)$s['id'],
                'sector_name' => (string)$s['name'],
                'tower_name'  => (string)($s['tower_name'] ?? '#' . $s['tower_id']),
                'cur_best'    => $cur_best,
                'alt_best'    => (int)$alt_best,
                'delta'       => $cur_best - (int)$alt_best,
            ];
        }
    }
    if ($cross):
?>
<div class="portal-card" style="border-left:3px solid #0a8;">
  <h2>Cross-band suggestions</h2>
  <p class="muted">These sectors would see a meaningfully lower noise floor (≥10 dB cleaner) by moving from <?= $h($band) ?> to <?= $h($alt_band) ?>. The planner can't queue a band move automatically (different radio config) — change the sector record on the right page.</p>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Sector</th><th>Tower</th><th>Best on <?= $h($band) ?></th><th>Best on <?= $h($alt_band) ?></th><th>Δ</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($cross as $c): ?>
        <tr>
          <td><strong><?= $h($c['sector_name']) ?></strong></td>
          <td><?= $h($c['tower_name']) ?></td>
          <td><?= $c['cur_best'] ?> dBm</td>
          <td style="color:#0a8;"><?= $c['alt_best'] ?> dBm</td>
          <td><strong style="color:#0a8;">−<?= $c['delta'] ?> dB</strong></td>
          <td><a href="/admin/sector-edit.php?id=<?= $c['sector_id'] ?>" class="btn btn-ghost btn-sm">Open sector</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; }
?>
<?php endif; ?>
