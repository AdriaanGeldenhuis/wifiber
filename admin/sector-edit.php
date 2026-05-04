<?php
/**
 * Single-sector edit + push-to-radio queue.
 *
 * Two forms:
 *   1. Sector record (DB-only) — name, azimuth/beam, band, frequency,
 *      channel width, TX power, SSID, security, wireless mode, notes.
 *   2. "Apply to radio" — queues a wireless_change_jobs row that the
 *      bin/apply-wireless-changes.php worker will execute. The worker
 *      snapshots the live config first, applies CPE then AP, and rolls
 *      back automatically if the link doesn't reconverge.
 *
 * Recent change jobs for this sector are listed at the bottom so
 * operators can see queued / applying / failed without leaving the
 * page.
 */
$page_title = 'Edit sector';
$active_key = 'sectors';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/totp.php';

$id = (int)($_GET['id'] ?? 0);
$self = '/admin/sector-edit.php?id=' . $id;

$pdo = pdo();
$sector = $id ? $pdo->prepare("SELECT * FROM sectors WHERE id = ? LIMIT 1") : null;
if ($sector) { $sector->execute([$id]); $sector = $sector->fetch() ?: null; }
if (!$sector) {
    echo '<div class="portal-card"><h2>Sector not found</h2><p>Pick one from <a href="/admin/sectors.php">/admin/sectors.php</a>.</p></div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_basic') {
        try {
            sector_save([
                'tower_id'          => (int)$sector['tower_id'],
                'ap_device_id'      => $_POST['ap_device_id'] ?? null,
                'name'              => $_POST['name'] ?? $sector['name'],
                'azimuth_deg'       => $_POST['azimuth_deg']       ?? null,
                'beamwidth_deg'     => $_POST['beamwidth_deg']     ?? null,
                'band'              => $_POST['band']              ?? $sector['band'],
                'frequency_mhz'     => $_POST['frequency_mhz']     ?? null,
                'channel_width_mhz' => $_POST['channel_width_mhz'] ?? null,
                'tx_power_dbm'      => $_POST['tx_power_dbm']      ?? null,
                'max_clients'       => $_POST['max_clients']       ?? null,
                'notes'             => $_POST['notes']             ?? '',
            ], $id);
            // Extra columns added in Phase 10 — sector_save doesn't know them yet.
            $ssid     = trim((string)($_POST['ssid'] ?? ''));
            $security = in_array($_POST['security'] ?? '', WL_SECURITIES, true) ? $_POST['security'] : 'wpa2';
            $mode     = in_array($_POST['wireless_mode'] ?? '', WL_MODES, true) ? $_POST['wireless_mode'] : 'airmax_ac';
            $tdd      = trim((string)($_POST['tdd_framing'] ?? ''));
            $wpa_key  = (string)($_POST['wpa_key'] ?? '');
            $wpa_enc  = $wpa_key !== '' ? encrypt_secret($wpa_key) : null;
            $sql = "UPDATE sectors
                       SET ssid = ?, security = ?, wireless_mode = ?, tdd_framing = ?";
            $args = [$ssid, $security, $mode, $tdd];
            if ($wpa_enc !== null) { $sql .= ', wpa_key_enc = ?'; $args[] = $wpa_enc; }
            $sql .= ' WHERE id = ?';
            $args[] = $id;
            $pdo->prepare($sql)->execute($args);
            audit_log('sector.save', ['target_type' => 'sector', 'target_id' => $id]);
            flash('success', 'Sector updated.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'queue_apply') {
        $payload = [];
        foreach (['frequency_mhz', 'channel_width_mhz', 'tx_power_dbm'] as $k) {
            if (isset($_POST[$k]) && $_POST[$k] !== '') $payload[$k] = (int)$_POST[$k];
        }
        if (!empty($_POST['ssid']))     $payload['ssid']     = (string)$_POST['ssid'];
        if (!empty($_POST['security'])) $payload['security'] = (string)$_POST['security'];
        if (!empty($_POST['wpa_key']))  $payload['wpa_key']  = (string)$_POST['wpa_key'];
        if (!totp_require_step_up($user, (string)($_POST['totp_code'] ?? ''))) {
            flash('error', 'Two-factor code is required for push-to-radio actions.');
            header('Location: ' . $self);
            exit;
        }
        $sched = trim((string)($_POST['scheduled_for'] ?? ''));
        if ($sched !== '') $sched = str_replace('T', ' ', $sched) . ':00';
        try {
            $job_id = wireless_change_job_enqueue('sector', $id, (int)$user['id'], $payload, $sched ?: null);
            audit_log('sector.config_queued', [
                'target_type' => 'sector', 'target_id' => $id,
                'meta' => ['job_id' => $job_id, 'payload_keys' => array_keys($payload)],
            ]);
            flash('success', "Queued change job #$job_id. The worker will pick it up within 60s.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'cancel_job') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        $job = wireless_change_job_find($job_id);
        if ($job && $job['status'] === 'queued' && (int)$job['scope_id'] === $id) {
            wireless_change_job_mark($job_id, 'cancelled');
            audit_log('sector.config_cancelled', ['target_type' => 'sector', 'target_id' => $id]);
            flash('success', "Job #$job_id cancelled.");
        }
        header('Location: ' . $self);
        exit;
    }
}

$tower    = site_find((int)$sector['tower_id']);
$devices  = devices_all();
$ap_devs  = array_values(array_filter($devices, fn ($d) => in_array($d['role'], ['ap', 'backhaul'], true)));
$jobs     = wireless_change_jobs_recent(['scope' => 'sector', 'scope_id' => $id], 20);

// Phase 24 analytics — overlap detector, throughput trend, outage
// history, capacity forecast. All cheap (single query each, on
// indexed columns) so safe to load on every page render.
$overlaps        = sectors_overlap_check($id);
$throughput_24h  = sector_throughput_history($id, 24);
$outage_history  = sector_outage_history($id, 90, 20);
$capacity        = sector_capacity_forecast($id, 90);

// Live customer count — sectors_all() backfills this, but we hit
// sector_find() here which doesn't, so do a one-row count.
$cnt_stmt = pdo()->prepare("SELECT COUNT(*) FROM users WHERE role='client' AND sector_id = ?");
$cnt_stmt->execute([$id]);
$customer_count = (int)$cnt_stmt->fetchColumn();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$status_pill = function (string $s): string {
    $colours = [
        'queued' => '#888', 'applying' => '#4477ff',
        'applied' => '#0c8', 'failed' => '#d44',
        'rolled_back' => '#e8a814', 'cancelled' => '#aaa',
    ];
    $c = $colours[$s] ?? '#888';
    return '<span style="display:inline-block;background:' . $c
        . ';color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;">'
        . htmlspecialchars($s) . '</span>';
};
?>

<div class="portal-head">
  <h1>Sector — <?= $h($sector['name']) ?></h1>
  <p class="portal-sub">
    Tower: <strong><?= $h($tower['name'] ?? '#' . $sector['tower_id']) ?></strong>
    &nbsp;·&nbsp;
    Band: <strong><?= $h($sector['band']) ?></strong>
    &nbsp;·&nbsp;
    Customers: <strong><?= $customer_count ?><?= $sector['max_clients'] ? ' / ' . (int)$sector['max_clients'] : '' ?></strong>
  </p>
</div>

<?php
// SVG cone preview — purely declarative, no JS. Anchored on a 240×240
// canvas, sector cone drawn from the centre (tower) outward to a
// configurable range. Helps operators sanity-check azimuth + beamwidth
// without bouncing to /admin/map.php.
$svg_size  = 240;
$svg_centre = $svg_size / 2;
$svg_radius = $svg_size / 2 - 12;
$az = $sector['azimuth_deg']   !== null ? (int)$sector['azimuth_deg']   : null;
$bw = $sector['beamwidth_deg'] !== null ? (int)$sector['beamwidth_deg'] : 60;
$cone_path = '';
if ($az !== null) {
    $half  = $bw / 2.0;
    // SVG y-axis points down; bearings are clockwise from north so
    // angle 0 → straight up (centre.x, 0), 90 → right.
    $start_deg = $az - $half - 90; // shift so 0° points "up"
    $end_deg   = $az + $half - 90;
    $start_x = $svg_centre + $svg_radius * cos(deg2rad($start_deg));
    $start_y = $svg_centre + $svg_radius * sin(deg2rad($start_deg));
    $end_x   = $svg_centre + $svg_radius * cos(deg2rad($end_deg));
    $end_y   = $svg_centre + $svg_radius * sin(deg2rad($end_deg));
    $large   = $bw > 180 ? 1 : 0;
    $cone_path = "M{$svg_centre},{$svg_centre} L{$start_x},{$start_y} "
               . "A{$svg_radius},{$svg_radius} 0 {$large} 1 {$end_x},{$end_y} Z";
}
?>

<div class="portal-card" style="display:grid;grid-template-columns:auto 1fr;gap:24px;align-items:start;">
  <!-- Left: SVG cone preview -->
  <div>
    <h3 style="margin-top:0;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">Cone preview</h3>
    <svg width="<?= $svg_size ?>" height="<?= $svg_size ?>" viewBox="0 0 <?= $svg_size ?> <?= $svg_size ?>"
         style="background:#0a0d12;border-radius:50%;border:1px solid var(--border);">
      <!-- compass rings -->
      <circle cx="<?= $svg_centre ?>" cy="<?= $svg_centre ?>" r="<?= $svg_radius ?>" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="1"/>
      <circle cx="<?= $svg_centre ?>" cy="<?= $svg_centre ?>" r="<?= round($svg_radius * 2/3) ?>" fill="none" stroke="rgba(255,255,255,.05)" stroke-width="1"/>
      <circle cx="<?= $svg_centre ?>" cy="<?= $svg_centre ?>" r="<?= round($svg_radius * 1/3) ?>" fill="none" stroke="rgba(255,255,255,.05)" stroke-width="1"/>
      <!-- N S E W ticks -->
      <text x="<?= $svg_centre ?>" y="14"  text-anchor="middle" fill="rgba(255,255,255,.4)" font-size="10" font-family="Inter,sans-serif">N</text>
      <text x="<?= $svg_size - 8 ?>" y="<?= $svg_centre + 4 ?>" text-anchor="end"   fill="rgba(255,255,255,.4)" font-size="10" font-family="Inter,sans-serif">E</text>
      <text x="<?= $svg_centre ?>" y="<?= $svg_size - 6 ?>"  text-anchor="middle" fill="rgba(255,255,255,.4)" font-size="10" font-family="Inter,sans-serif">S</text>
      <text x="8" y="<?= $svg_centre + 4 ?>"                text-anchor="start"  fill="rgba(255,255,255,.4)" font-size="10" font-family="Inter,sans-serif">W</text>
      <!-- cone -->
      <?php if ($cone_path): ?>
        <path d="<?= $cone_path ?>" fill="rgba(5,218,253,.20)" stroke="#05DAFD" stroke-width="2"/>
      <?php else: ?>
        <text x="<?= $svg_centre ?>" y="<?= $svg_centre + 4 ?>" text-anchor="middle" fill="rgba(255,255,255,.4)" font-size="11">no azimuth set</text>
      <?php endif; ?>
      <!-- centre dot -->
      <circle cx="<?= $svg_centre ?>" cy="<?= $svg_centre ?>" r="3" fill="#05DAFD"/>
    </svg>
    <p class="muted" style="text-align:center;margin:8px 0 0;font-size:11px;">
      <?= $az !== null ? 'az ' . $az . '° · bw ' . $bw . '°' : '—' ?>
    </p>
  </div>

  <!-- Right: at-a-glance stats card grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;">
    <div class="sec-stat">
      <small>Customers</small>
      <strong><?= $customer_count ?><?php if ($sector['max_clients']): ?><span class="muted"> / <?= (int)$sector['max_clients'] ?></span><?php endif; ?></strong>
      <?php if ($capacity && $capacity['days_to_full'] !== null): ?>
        <em>fills in ~<?= $capacity['days_to_full'] ?> days</em>
      <?php elseif ($capacity && $capacity['rate_per_day'] === 0.0): ?>
        <em class="muted">no recent growth</em>
      <?php endif; ?>
    </div>
    <div class="sec-stat">
      <small>Capacity</small>
      <strong><?= $capacity ? $capacity['pct'] . '%' : '—' ?></strong>
      <?php if ($capacity): ?>
        <em><?= $capacity['recent_adds'] ?> adds in <?= $capacity['window_days'] ?>d</em>
      <?php endif; ?>
    </div>
    <div class="sec-stat<?= $overlaps ? ' sec-stat-warn' : '' ?>">
      <small>Co-channel overlap</small>
      <strong><?= count($overlaps) ?></strong>
      <em><?= $overlaps ? 'sectors interfering' : 'clean' ?></em>
    </div>
    <div class="sec-stat<?= $outage_history['count'] > 0 ? ' sec-stat-warn' : '' ?>">
      <small>Outages (90d)</small>
      <strong><?= $outage_history['count'] ?></strong>
      <?php if ($outage_history['down_minutes'] > 0): ?>
        <em><?= floor($outage_history['down_minutes'] / 60) ?>h <?= $outage_history['down_minutes'] % 60 ?>m total<?php if ($outage_history['mttr_minutes'] !== null): ?> · MTTR <?= $outage_history['mttr_minutes'] ?>m<?php endif; ?></em>
      <?php endif; ?>
    </div>
    <div class="sec-stat">
      <small>Throughput peak (24h)</small>
      <strong><?= number_format(max(array_column($throughput_24h, 'peak_mbps') ?: [0]), 1) ?> <small class="muted">Mbps</small></strong>
      <em>avg <?= number_format(array_sum(array_column($throughput_24h, 'avg_mbps')) / max(1, count($throughput_24h)), 1) ?> Mbps</em>
    </div>
  </div>
</div>

<?php if ($overlaps): ?>
<div class="portal-card" style="border-left:3px solid #f97316;">
  <h2>Co-channel overlap <span class="muted">(<?= count($overlaps) ?>)</span></h2>
  <p class="muted">These sectors share the band, have overlapping frequency windows, and are within <?= SECTOR_OVERLAP_DISTANCE_KM ?> km of this tower. Move one to a different channel or reduce TX power to clean it up.</p>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Sector</th><th>Tower</th><th>Band</th><th>Frequency</th><th>Overlap</th><th>Distance</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($overlaps as $o): ?>
        <tr>
          <td><strong><?= $h($o['sector_name']) ?></strong>
            <?php if ($o['azimuth_deg'] !== null): ?>
              <small class="muted">· az <?= $o['azimuth_deg'] ?>°</small>
            <?php endif; ?>
          </td>
          <td><?= $h($o['tower_name']) ?></td>
          <td><?= $h($o['band']) ?></td>
          <td><?= $o['frequency_mhz'] ?> MHz @ <?= $o['channel_width_mhz'] ?> MHz wide</td>
          <td><strong style="color:#f97316;"><?= $o['overlap_mhz'] ?> MHz</strong></td>
          <td><?= number_format($o['distance_km'], 2) ?> km</td>
          <td><a href="/admin/sector-edit.php?id=<?= $o['sector_id'] ?>" class="btn btn-ghost btn-sm">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php
// Throughput trend SVG sparkline. Drawn server-side so it works
// regardless of JS state. Two paths: an "avg" line and a translucent
// "peak" envelope behind it.
$thr_w = 760; $thr_h = 110; $thr_pad_t = 8; $thr_pad_b = 18;
$thr_plot_h = $thr_h - $thr_pad_t - $thr_pad_b;
$thr_max = 0.0;
foreach ($throughput_24h as $r) $thr_max = max($thr_max, (float)$r['peak_mbps']);
if ($thr_max < 1.0) $thr_max = 1.0;
$n = count($throughput_24h);
$x_step = $n > 1 ? ($thr_w - 12) / ($n - 1) : 0;
$pts_avg = []; $pts_peak = [];
foreach ($throughput_24h as $i => $r) {
    $x = 6 + $i * $x_step;
    $y_avg  = $thr_pad_t + $thr_plot_h - ($r['avg_mbps']  / $thr_max) * $thr_plot_h;
    $y_peak = $thr_pad_t + $thr_plot_h - ($r['peak_mbps'] / $thr_max) * $thr_plot_h;
    $pts_avg[]  = number_format($x, 1) . ',' . number_format($y_avg,  1);
    $pts_peak[] = number_format($x, 1) . ',' . number_format($y_peak, 1);
}
$baseline_y = $thr_pad_t + $thr_plot_h;
$peak_path  = 'M ' . implode(' L ', $pts_peak) . ' L ' . number_format($thr_w - 6, 1) . ",{$baseline_y} L 6,{$baseline_y} Z";
$avg_path   = 'M ' . implode(' L ', $pts_avg);
?>
<div class="portal-card">
  <h2>Throughput (last 24 h)</h2>
  <?php if ($n === 0 || $thr_max <= 1.0): ?>
    <p class="muted">No throughput samples yet. Make sure the sector has wireless_links and the wireless poll worker is running.</p>
  <?php else: ?>
    <svg width="100%" height="<?= $thr_h ?>" viewBox="0 0 <?= $thr_w ?> <?= $thr_h ?>" preserveAspectRatio="none" style="background:#0a0d12;border-radius:6px;">
      <line x1="6" x2="<?= $thr_w - 6 ?>" y1="<?= $baseline_y ?>" y2="<?= $baseline_y ?>" stroke="rgba(255,255,255,.1)" stroke-width="1"/>
      <path d="<?= $peak_path ?>" fill="rgba(5,218,253,.10)" stroke="none"/>
      <path d="<?= $avg_path  ?>" fill="none" stroke="#05DAFD" stroke-width="2" stroke-linejoin="round"/>
      <text x="6" y="<?= $thr_pad_t + 10 ?>" fill="rgba(255,255,255,.5)" font-size="10" font-family="Inter,sans-serif"><?= number_format($thr_max, 1) ?> Mbps</text>
      <text x="6" y="<?= $baseline_y - 4 ?>" fill="rgba(255,255,255,.3)" font-size="10" font-family="Inter,sans-serif">0</text>
      <text x="<?= $thr_w - 6 ?>" y="<?= $baseline_y - 4 ?>" text-anchor="end" fill="rgba(255,255,255,.3)" font-size="10" font-family="Inter,sans-serif">now</text>
      <text x="6" y="<?= $thr_h - 4 ?>" fill="rgba(255,255,255,.3)" font-size="10" font-family="Inter,sans-serif">−24 h</text>
    </svg>
    <p class="muted"><small>Solid line is hourly average across all wireless links on this sector; shaded area is per-hour peak. Source: <code>link_health_samples</code>.</small></p>
  <?php endif; ?>
</div>

<?php if ($outage_history['rows']): ?>
<div class="portal-card">
  <h2>Outage history (90 days) <span class="muted">(<?= $outage_history['count'] ?>)</span></h2>
  <p class="muted">
    Total downtime: <strong><?= floor($outage_history['down_minutes'] / 60) ?>h <?= $outage_history['down_minutes'] % 60 ?>m</strong>
    <?php if ($outage_history['mttr_minutes'] !== null): ?>
      · MTTR: <strong><?= $outage_history['mttr_minutes'] ?> minutes</strong>
    <?php endif; ?>
  </p>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Started</th><th>Resolved</th><th>Duration</th><th>Affected</th><th>Cause</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($outage_history['rows'] as $o): ?>
        <tr>
          <td><small><?= $h($o['started_at']) ?></small></td>
          <td><small><?= $h($o['resolved_at'] ?? '—') ?></small></td>
          <td><?= (int)$o['minutes'] ?>m</td>
          <td><?= (int)$o['affected_count'] ?></td>
          <td><small><?= $h($o['cause'] ?? '') ?></small></td>
          <td>
            <?php $oc = $o['status'] === 'active' ? '#d44' : '#888'; ?>
            <span style="display:inline-block;background:<?= $oc ?>;color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;"><?= $h($o['status']) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<style>
  .sec-stat {
    background: var(--bg-elev);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .sec-stat small {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    font-weight: 600;
  }
  .sec-stat strong {
    font-family: 'Space Grotesk', 'Inter', sans-serif;
    font-size: 22px;
    line-height: 1.1;
    color: var(--text);
  }
  .sec-stat em {
    font-style: normal;
    font-size: 11px;
    color: var(--text-muted);
  }
  .sec-stat-warn { border-color: #f97316; box-shadow: 0 0 0 1px rgba(249,115,22,.15); }
  .sec-stat-warn strong { color: #f97316; }
</style>

<div class="portal-card">
  <h2>Sector record</h2>
  <p class="muted">Edits here only update the database. To push changes to the actual radio, use the <strong>Apply to radio</strong> form below.</p>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_basic">

    <div class="field"><label>Name *</label>
      <input type="text" name="name" required value="<?= $h($sector['name']) ?>"></div>

    <div class="field"><label>AP device</label>
      <select name="ap_device_id">
        <option value="">— none —</option>
        <?php foreach ($ap_devs as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)$sector['ap_device_id'] === (int)$d['id'] ? 'selected' : '' ?>>
            <?= $h($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field"><label>Azimuth (°)</label>
      <input type="number" name="azimuth_deg" min="0" max="360" value="<?= $h($sector['azimuth_deg']) ?>"></div>
    <div class="field"><label>Beamwidth (°)</label>
      <input type="number" name="beamwidth_deg" min="0" max="360" value="<?= $h($sector['beamwidth_deg']) ?>"></div>

    <div class="field"><label>Band</label>
      <select name="band">
        <?php foreach (SECTOR_BANDS as $b): ?>
          <option value="<?= $b ?>" <?= $sector['band'] === $b ? 'selected' : '' ?>><?= $b ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Frequency (MHz)</label>
      <input type="number" name="frequency_mhz" value="<?= $h($sector['frequency_mhz']) ?>"></div>
    <div class="field"><label>Channel width (MHz)</label>
      <select name="channel_width_mhz">
        <option value="">—</option>
        <?php foreach ([5, 8, 10, 20, 30, 40, 60, 80, 160] as $w): ?>
          <option value="<?= $w ?>" <?= (int)$sector['channel_width_mhz'] === $w ? 'selected' : '' ?>><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>TX power (dBm)</label>
      <input type="number" name="tx_power_dbm" min="-10" max="40" value="<?= $h($sector['tx_power_dbm']) ?>"></div>

    <div class="field"><label>SSID</label>
      <input type="text" name="ssid" maxlength="64" value="<?= $h($sector['ssid'] ?? '') ?>"></div>
    <div class="field"><label>Security</label>
      <select name="security">
        <?php foreach (WL_SECURITIES as $s): ?>
          <option value="<?= $s ?>" <?= ($sector['security'] ?? 'wpa2') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>WPA key (leave blank to keep)</label>
      <input type="password" name="wpa_key" autocomplete="new-password"></div>
    <div class="field"><label>Wireless mode</label>
      <select name="wireless_mode">
        <?php foreach (WL_MODES as $m): ?>
          <option value="<?= $m ?>" <?= ($sector['wireless_mode'] ?? 'airmax_ac') === $m ? 'selected' : '' ?>><?= $m ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>TDD framing</label>
      <input type="text" name="tdd_framing" value="<?= $h($sector['tdd_framing'] ?? '') ?>"></div>

    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2"><?= $h($sector['notes'] ?? '') ?></textarea></div>

    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Save record</button>
      <a class="btn btn-ghost btn-sm" href="/admin/sectors.php">Back</a>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Apply to radio</h2>
  <p class="muted">Queues a job for <code>bin/apply-wireless-changes.php</code>. The worker snapshots the live radio config, applies CPE-side first then AP-side, and rolls back automatically if the link fails to reconverge in 60s. Customers are notified via the existing outage flow if rollback fails.</p>

  <form method="post" class="form form-grid"
        onsubmit="return confirm('This will reconfigure the live radio. Customers may briefly disconnect. Continue?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="queue_apply">
    <div class="field"><label>Frequency (MHz)</label>
      <input type="number" name="frequency_mhz" placeholder="<?= $h($sector['frequency_mhz']) ?>"></div>
    <div class="field"><label>Channel width (MHz)</label>
      <select name="channel_width_mhz">
        <option value="">unchanged</option>
        <?php foreach ([5, 8, 10, 20, 30, 40, 60, 80, 160] as $w): ?>
          <option value="<?= $w ?>"><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>TX power (dBm)</label>
      <input type="number" name="tx_power_dbm" placeholder="<?= $h($sector['tx_power_dbm']) ?>"></div>
    <div class="field"><label>SSID</label>
      <input type="text" name="ssid" placeholder="<?= $h($sector['ssid'] ?? '') ?>"></div>
    <div class="field"><label>Security</label>
      <select name="security">
        <option value="">unchanged</option>
        <?php foreach (WL_SECURITIES as $s): ?>
          <option value="<?= $s ?>"><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>New WPA key</label>
      <input type="password" name="wpa_key" autocomplete="new-password"></div>
    <div class="field"><label>Schedule for (optional)</label>
      <input type="datetime-local" name="scheduled_for">
      <small class="muted">Empty = run as soon as the worker picks it up.</small>
    </div>
    <?php if (!empty($user['totp_enabled'])): ?>
      <div class="field"><label>Two-factor code *</label>
        <input type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" name="totp_code" required autocomplete="one-time-code">
      </div>
    <?php endif; ?>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Queue change</button>
      <small class="muted">Only filled fields are queued. Empty fields don't change the radio.</small>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Recent change jobs</h2>
  <?php if (!$jobs): ?>
    <small class="muted">No jobs queued for this sector yet.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>#</th><th>Status</th><th>Requested by</th><th>Created</th><th>Started</th><th>Finished</th><th>Payload</th><th>Error</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td>#<?= (int)$j['id'] ?></td>
            <td><?= $status_pill($j['status']) ?></td>
            <td><?= $h($j['requester_name'] ?? '—') ?></td>
            <td><small><?= $h($j['created_at']) ?></small></td>
            <td><small><?= $h($j['started_at'] ?? '—') ?></small></td>
            <td><small><?= $h($j['finished_at'] ?? '—') ?></small></td>
            <td><small><code><?= $h($j['payload_json']) ?></code></small></td>
            <td><small style="color:#d44;"><?= $h($j['error']) ?></small></td>
            <td>
              <?php if ($j['status'] === 'queued'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cancel_job">
                  <input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Cancel</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
