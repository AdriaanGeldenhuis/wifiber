<?php
/**
 * Live link dashboard — the UISP-style "Link" tab.
 *
 * One page per wireless_links row. Server-rendered SVG charts (no JS
 * chart library) following the same pattern as device-view.php.
 *
 * Data sources:
 *   wireless_links            current state (signal/noise/SNR/CCQ/rates,
 *                             frequency, width, mode, security, SSID,
 *                             distance, throughput, capacity, airtime)
 *   link_health_samples       24h time-series for the signal/noise chart
 *   rf_environment_samples    last hour, per-frequency RSSI bars
 *   ethernet_health           latest sample → cable SNR + length + duplex
 *   device_health (ap & cpe)  CPU / memory / uptime
 */
$page_title = 'Wireless link';
$active_key = 'links';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sectors.php';

$id = (int)($_GET['id'] ?? 0);
$link = $id ? wireless_link_find($id) : null;
if (!$link) {
    echo '<div class="portal-card"><h2>Link not found</h2><p>Pick one from <a href="/admin/links.php">/admin/links.php</a>.</p></div>';
    return;
}

$ap_health  = device_recent_health((int)$link['ap_device_id'], 1);
$ap_h       = $ap_health[0] ?? null;
$cpe_health = $link['cpe_device_id'] ? device_recent_health((int)$link['cpe_device_id'], 1) : [];
$cpe_h      = $cpe_health[0] ?? null;

$ap_eth  = ethernet_health_latest((int)$link['ap_device_id']);
$cpe_eth = $link['cpe_device_id'] ? ethernet_health_latest((int)$link['cpe_device_id']) : null;

$tab = (string)($_GET['tab'] ?? 'link');

/* Fresnel zone radius at midpoint, 60% clearance recommendation, vs.
   AP/CPE site heights. Standard formula: r = 8.657 * sqrt(d_km / f_GHz)
   metres. We assume both endpoints share the same 5 GHz band the link
   is on (link.frequency_mhz, fall back to 5500). */
$ap_site  = !empty($link['ap_site_id'])  ? site_find((int)$link['ap_site_id'])  : null;
$cpe_site = !empty($link['cpe_site_id']) ? site_find((int)$link['cpe_site_id']) : null;
$dist_km  = $link['distance_km'] !== null ? (float)$link['distance_km']
          : (($ap_site && $cpe_site && $ap_site['lat'] !== null && $cpe_site['lat'] !== null)
              ? haversine_km((float)$ap_site['lat'], (float)$ap_site['lng'],
                             (float)$cpe_site['lat'], (float)$cpe_site['lng'])
              : null);
$freq_ghz = $link['frequency_mhz'] !== null ? ((int)$link['frequency_mhz']) / 1000.0 : 5.5;
$fresnel = null;
if ($dist_km !== null && $dist_km > 0) {
    $r_m_full = 8.657 * sqrt($dist_km / $freq_ghz);    // 1st Fresnel zone @ midpoint
    $r_m_60   = $r_m_full * 0.6;                       // recommended clearance
    // Earth bulge in metres: h = (d_km^2) / (8 * 1.33) for 4/3 effective radius
    $bulge = ($dist_km * $dist_km) / (8 * 1.33);
    $ap_h  = $ap_site['height_m']  ?? null;
    $cpe_h = $cpe_site['height_m'] ?? null;
    $needed_height = ($ap_h !== null && $cpe_h !== null)
        ? ($r_m_60 + $bulge)
        : null;
    $clearance = ($ap_h !== null && $cpe_h !== null)
        ? min((float)$ap_h, (float)$cpe_h) - $needed_height
        : null;
    $fresnel = [
        'distance_km'   => $dist_km,
        'frequency_ghz' => $freq_ghz,
        'r_full_m'      => $r_m_full,
        'r_60_m'        => $r_m_60,
        'earth_bulge_m' => $bulge,
        'ap_height_m'   => $ap_h,
        'cpe_height_m'  => $cpe_h,
        'needed_m'      => $needed_height,
        'clearance_m'   => $clearance,
    ];
}

$samples = wireless_link_recent_samples($id, 288); // 24h at 5min cadence
$rf_ap   = rf_environment_recent((int)$link['ap_device_id'], 60);
$rf_cpe  = $link['cpe_device_id'] ? rf_environment_recent((int)$link['cpe_device_id'], 60) : [];

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fmt_uptime = function (?int $s): string {
    if (!$s) return '—';
    $d = intdiv($s, 86400); $s %= 86400;
    $hh = intdiv($s, 3600); $s %= 3600;
    $mm = intdiv($s, 60);
    if ($d > 0)  return sprintf('%d day%s %02d:%02d', $d, $d === 1 ? '' : 's', $hh, $mm);
    if ($hh > 0) return sprintf('%d:%02d:%02d', $hh, $mm, $s);
    return sprintf('%02d:%02d', $mm, $s);
};
$fmt_bytes = function (?int $b): string {
    if ($b === null) return '—';
    foreach (['B','K','M','G','T','P'] as $u) {
        if ($b < 1024) return number_format($b, $u === 'B' ? 0 : 2) . ' ' . $u;
        $b /= 1024;
    }
    return number_format($b, 2) . ' E';
};

/* ---------- SVG: signal / noise / interference (24h) ---------- */
$chart_w = 720; $chart_h = 160; $pad_l = 36; $pad_b = 22; $pad_t = 8;
$chart_signal_svg = '';
if ($samples) {
    // Reverse so x grows oldest → newest.
    $rows = array_reverse($samples);
    $now  = time();
    $xs = []; $sigs = []; $noise = []; $intr = [];
    foreach ($rows as $r) {
        $t = strtotime((string)$r['polled_at']) ?: $now;
        $xs[] = $t;
        $sigs[]  = $r['signal_local_dbm'] !== null ? (int)$r['signal_local_dbm'] : null;
        $noise[] = $r['noise_local_dbm']  !== null ? (int)$r['noise_local_dbm']  : null;
        // "interference + noise" is approximated as noise + (1 if airtime > 50%).
        $a  = (float)($r['airtime_local_pct'] ?? 0);
        $intr[]  = $r['noise_local_dbm']  !== null ? (int)$r['noise_local_dbm'] + ($a > 50 ? 6 : 2) : null;
    }
    $tmin = min($xs); $tmax = max($xs); $tspan = max(1, $tmax - $tmin);
    $ymin = -110; $ymax = -40;
    $sx = fn ($t) => $pad_l + ($t - $tmin) / $tspan * ($chart_w - $pad_l - 6);
    $sy = fn ($v) => $pad_t + ($ymax - $v) / ($ymax - $ymin) * ($chart_h - $pad_t - $pad_b);

    $line = function (array $vals, string $colour) use ($xs, $sx, $sy) {
        $d = '';
        $started = false;
        foreach ($vals as $i => $v) {
            if ($v === null) { $started = false; continue; }
            $d .= ($started ? 'L' : 'M') . round($sx($xs[$i]), 1) . ',' . round($sy($v), 1) . ' ';
            $started = true;
        }
        return $d === '' ? '' : '<path d="' . $d . '" fill="none" stroke="' . $colour . '" stroke-width="1.5"/>';
    };
    // Y-axis grid lines
    $grid = '';
    foreach ([-50, -70, -90, -110] as $y) {
        $py = $sy($y);
        $grid .= '<line x1="' . $pad_l . '" y1="' . $py . '" x2="' . ($chart_w - 6) . '" y2="' . $py . '" stroke="#eef" stroke-width="1"/>';
        $grid .= '<text x="' . ($pad_l - 4) . '" y="' . ($py + 3) . '" font-size="9" fill="#789" text-anchor="end">' . $y . '</text>';
    }
    $chart_signal_svg = '<svg viewBox="0 0 ' . $chart_w . ' ' . $chart_h . '" width="100%" preserveAspectRatio="none">'
        . '<rect x="' . $pad_l . '" y="' . $pad_t . '" width="' . ($chart_w - $pad_l - 6) . '" height="' . ($chart_h - $pad_t - $pad_b) . '" fill="rgba(34,197,94,0.06)"/>'
        . $grid
        . $line($sigs,  '#4477ff')
        . $line($intr,  '#e8a814')
        . $line($noise, '#22c55e')
        . '</svg>';
}

/* ---------- SVG: RF environment bars ---------- */
$rf_bars = function (array $rf) use ($h): string {
    if (!$rf) return '<small class="muted">No RF scan samples yet.</small>';
    $w = 720; $bar_h = 40;
    $freqs = array_column($rf, 'freq_mhz');
    $fmin = min($freqs); $fmax = max($freqs);
    $fspan = max(1, $fmax - $fmin);
    $bars = '';
    foreach ($rf as $r) {
        $x = ($r['freq_mhz'] - $fmin) / $fspan * ($w - 2);
        $rssi = (int)$r['rssi_dbm']; // -100..-30
        $intensity = max(0, min(1, (-30 - $rssi) / 70));
        $colour = sprintf('rgba(34,134,231,%.2f)', 0.2 + 0.8 * (1 - $intensity));
        $bars .= '<rect x="' . round($x, 1) . '" y="0" width="3" height="' . $bar_h . '" fill="' . $colour . '"/>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $bar_h . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . '<rect x="0" y="0" width="' . $w . '" height="' . $bar_h . '" fill="rgba(34,134,231,0.06)"/>'
        . $bars . '</svg>'
        . '<div style="display:flex;justify-content:space-between;font-size:11px;color:#789;">'
        . '<span>' . $h($fmin) . ' MHz</span>'
        . '<span>' . $h($fmax) . ' MHz</span></div>';
};

/* ---------- CINR gauge ---------- */
$cinr_gauge = function (?int $snr) {
    if ($snr === null) return '<small class="muted">No CINR samples.</small>';
    $w = 360; $hh = 40;
    $clamped = max(0, min(40, $snr));
    $x = $clamped / 40 * ($w - 4);
    $colour = $snr >= 25 ? '#0c8' : ($snr >= 15 ? '#e8a814' : '#d44');
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . '<rect x="0" y="14" width="' . $w . '" height="12" fill="rgba(0,0,0,0.05)"/>'
        . '<rect x="0" y="14" width="' . $x . '" height="12" fill="' . $colour . '"/>'
        . '<text x="' . ($w / 2) . '" y="9" font-size="10" fill="#789" text-anchor="middle">CINR ' . $snr . ' dB</text>'
        . '</svg>'
        . '<div style="display:flex;justify-content:space-between;font-size:10px;color:#789;">'
        . '<span>0</span><span>10</span><span>20</span><span>30</span><span>40</span></div>';
};
?>

<style>
  .lv-grid     { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
  .lv-grid > * { min-width:0; }
  .lv-bigstat  { font-size:32px; font-weight:300; line-height:1; }
  .lv-suffix   { font-size:13px; color:#789; }
  .lv-label    { font-size:11px; color:#789; text-transform:uppercase; letter-spacing:.05em; }
  .lv-row      { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid rgba(0,0,0,0.04); font-size:13px; }
  .lv-row:last-child { border-bottom:none; }
  .lv-row b    { font-weight:500; color:#345; }
  .lv-pill     { display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;color:#fff;font-weight:600;letter-spacing:.02em; }
  .lv-tabs     { display:flex; gap:4px; padding:4px; background:rgba(0,0,0,0.04); border-radius:9px; width:max-content; margin:0 auto; }
  .lv-tab      { padding:4px 14px; border-radius:7px; font-size:12px; }
  .lv-tab.active { background:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.08); }
  .lv-banner   { display:flex; align-items:center; gap:18px; padding:14px 18px; background:#fff; border-radius:9px; box-shadow:0 1px 6px rgba(0,0,0,0.06); }
  .lv-banner .lv-side { flex:1; display:flex; align-items:center; gap:14px; }
  .lv-banner .lv-mid  { flex:1.4; text-align:center; }
  .lv-banner-distance { display:inline-block;background:#222;color:#fff;padding:6px 14px;border-radius:14px;font-size:13px; }
  .lv-dial     { display:inline-flex; align-items:center; justify-content:center; width:78px; height:78px; border-radius:50%;
                 border:3px solid #4cafe9; flex-direction:column; }
  .lv-dial small { display:block; font-size:9px; text-align:center; color:#789; line-height:1; }
  .lv-dial b     { font-size:18px; font-weight:400; }
  .legend      { display:flex; gap:20px; flex-wrap:wrap; font-size:12px; color:#456; padding:8px 0 0; }
  .legend-dot  { display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:middle; }
</style>

<div class="lv-banner">
  <div class="lv-side">
    <div class="lv-label">Local</div>
    <div>
      <strong><?= $h($link['ap_name']) ?></strong>
      <div class="lv-label"><?= $h($link['ap_vendor']) ?> · <?= $h($link['ap_model']) ?></div>
      <div class="lv-label">TX power <?= $link['tx_power_dbm_local'] !== null ? (int)$link['tx_power_dbm_local'] . ' dBm' : '—' ?></div>
    </div>
    <div class="lv-dial">
      <small>throughput<br>capacity</small>
      <b><?= $link['capacity_local_mbps'] !== null ? number_format((float)$link['capacity_local_mbps'], 2) : '—' ?></b>
      <small>Mbps</small>
    </div>
  </div>
  <div class="lv-mid">
    <span class="lv-banner-distance">🔒
      <?= $link['distance_km'] !== null
          ? number_format((float)$link['distance_km'] * 1000 / 0.3048, 2) . ' ft'
          : '—' ?>
    </span>
    <div class="lv-label" style="margin-top:6px;">
      Airtime <?= $link['airtime_local_pct'] !== null ? number_format((float)$link['airtime_local_pct'], 1) . '%' : '—' ?>
      &nbsp;·&nbsp;
      <?= $link['airtime_remote_pct'] !== null ? number_format((float)$link['airtime_remote_pct'], 1) . '%' : '—' ?>
    </div>
  </div>
  <div class="lv-side" style="justify-content:flex-end;">
    <div class="lv-dial">
      <small>throughput<br>capacity</small>
      <b><?= $link['capacity_remote_mbps'] !== null ? number_format((float)$link['capacity_remote_mbps'], 2) : '—' ?></b>
      <small>Mbps</small>
    </div>
    <div style="text-align:right;">
      <strong><?= $h($link['cpe_name'] ?? '—') ?></strong>
      <div class="lv-label"><?= $h($link['cpe_vendor'] ?? '') ?> · <?= $h($link['cpe_model'] ?? '') ?></div>
      <div class="lv-label">TX power <?= $link['tx_power_dbm_remote'] !== null ? (int)$link['tx_power_dbm_remote'] . ' dBm' : '—' ?></div>
    </div>
    <div class="lv-label">Remote</div>
  </div>
</div>

<div class="lv-tabs" style="margin:18px auto;">
  <a class="lv-tab" href="/admin/map.php">Map</a>
  <a class="lv-tab <?= $tab === 'link' ? 'active' : '' ?>" href="?id=<?= (int)$link['id'] ?>&tab=link">Link</a>
  <a class="lv-tab <?= $tab === 'fresnel' ? 'active' : '' ?>" href="?id=<?= (int)$link['id'] ?>&tab=fresnel">Fresnel</a>
</div>

<?php if ($tab === 'fresnel'): ?>
<div class="portal-card">
  <h2>Fresnel zone &amp; line-of-sight</h2>
  <?php if (!$fresnel): ?>
    <small class="muted">Need both endpoint sites with lat/lng + a measured distance to compute. Open the AP and CPE devices and confirm they are attached to a site with coordinates.</small>
  <?php else: ?>
    <p class="muted">Recommended: ≥60 % of the first Fresnel zone clear at the midpoint, plus an allowance for 4/3-Earth bulge.</p>
    <div class="lv-row"><span><b>Distance</b></span>
      <span><?= number_format($fresnel['distance_km'], 3) ?> km</span></div>
    <div class="lv-row"><span><b>Frequency</b></span>
      <span><?= number_format($fresnel['frequency_ghz'], 3) ?> GHz</span></div>
    <div class="lv-row"><span><b>1st Fresnel radius (midpoint)</b></span>
      <span><?= number_format($fresnel['r_full_m'], 2) ?> m</span></div>
    <div class="lv-row"><span><b>60 % clearance recommended</b></span>
      <span><?= number_format($fresnel['r_60_m'], 2) ?> m</span></div>
    <div class="lv-row"><span><b>Earth-bulge allowance (4/3 R)</b></span>
      <span><?= number_format($fresnel['earth_bulge_m'], 2) ?> m</span></div>
    <div class="lv-row"><span><b>AP / CPE site height</b></span>
      <span>
        <?= $fresnel['ap_height_m']  !== null ? number_format($fresnel['ap_height_m'], 1)  . ' m' : '—' ?>
        /
        <?= $fresnel['cpe_height_m'] !== null ? number_format($fresnel['cpe_height_m'], 1) . ' m' : '—' ?>
      </span></div>
    <?php if ($fresnel['needed_m'] !== null): ?>
      <div class="lv-row"><span><b>Min height needed</b></span>
        <span><?= number_format($fresnel['needed_m'], 2) ?> m</span></div>
      <div class="lv-row"><span><b>Clearance margin</b></span>
        <span style="color:<?= $fresnel['clearance_m'] >= 0 ? '#0c8' : '#d44' ?>;font-weight:600;">
          <?= ($fresnel['clearance_m'] >= 0 ? '+' : '') . number_format($fresnel['clearance_m'], 2) ?> m
          <?php if ($fresnel['clearance_m'] < 0): ?>
            <span style="background:#d44;color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;margin-left:6px;">obstructed</span>
          <?php endif; ?>
        </span></div>
    <?php else: ?>
      <small class="muted">Add a height_m on both endpoint sites to compute clearance.</small>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="lv-grid">
  <div class="portal-card">
    <h3 class="lv-label">Local device</h3>

    <div class="lv-label" style="margin-top:8px;">RF environment (last hour)</div>
    <?= $rf_bars($rf_ap) ?>

    <div class="lv-row" style="margin-top:14px;">
      <span><b>Signal</b> <span class="muted">noise floor</span></span>
      <span>
        <strong><?= $link['signal_dbm'] !== null ? (int)$link['signal_dbm'] : '—' ?> dBm</strong>
        &nbsp; <span class="muted"><?= $link['noise_dbm'] !== null ? (int)$link['noise_dbm'] . ' dBm' : '—' ?></span>
      </span>
    </div>
    <div class="lv-row">
      <span><b>SNR</b></span>
      <span><?= $link['snr_db'] !== null ? (int)$link['snr_db'] . ' dB' : '—' ?></span>
    </div>
    <div class="lv-row">
      <span><b>RX rate</b> <span class="muted">expected</span></span>
      <span>
        <?= $link['rx_rate_mbps'] !== null ? number_format((float)$link['rx_rate_mbps'], 0) . ' Mbps' : '—' ?>
        &nbsp; <span class="muted"><?= $link['expected_rate_mbps'] !== null ? number_format((float)$link['expected_rate_mbps'], 0) . ' Mbps' : '' ?></span>
      </span>
    </div>

    <h4 class="lv-label" style="margin-top:18px;">Signal · Noise · Interference (24h)</h4>
    <?= $chart_signal_svg ?: '<small class="muted">No samples yet — wait for the next poll.</small>' ?>
    <div class="legend">
      <span><span class="legend-dot" style="background:#4477ff;"></span>Average signal <?= $link['signal_dbm'] !== null ? (int)$link['signal_dbm'] . ' dBm' : '' ?></span>
      <span><span class="legend-dot" style="background:#e8a814;"></span>Interference + noise <?= $link['noise_dbm'] !== null ? (int)$link['noise_dbm'] . ' dBm' : '' ?></span>
      <span><span class="legend-dot" style="background:#22c55e;"></span>Noise floor <?= $link['noise_dbm'] !== null ? (int)$link['noise_dbm'] . ' dBm' : '' ?></span>
    </div>

    <h4 class="lv-label" style="margin-top:18px;">CINR (dB)</h4>
    <?= $cinr_gauge($link['snr_db'] !== null ? (int)$link['snr_db'] : null) ?>
  </div>

  <div class="portal-card">
    <h3 class="lv-label">Remote device</h3>

    <div class="lv-label" style="margin-top:8px;">RF environment (last hour)</div>
    <?= $rf_bars($rf_cpe) ?>

    <div class="lv-row" style="margin-top:14px;">
      <span><b>Signal</b> <span class="muted">noise floor</span></span>
      <span>
        <strong><?= $link['signal_dbm_remote'] !== null ? (int)$link['signal_dbm_remote'] : '—' ?> dBm</strong>
        &nbsp; <span class="muted"><?= $link['noise_dbm_remote'] !== null ? (int)$link['noise_dbm_remote'] . ' dBm' : '—' ?></span>
      </span>
    </div>
    <div class="lv-row">
      <span><b>SNR</b></span>
      <span><?= $link['snr_db_remote'] !== null ? (int)$link['snr_db_remote'] . ' dB' : '—' ?></span>
    </div>
    <div class="lv-row">
      <span><b>TX rate</b></span>
      <span><?= $link['tx_rate_mbps'] !== null ? number_format((float)$link['tx_rate_mbps'], 0) . ' Mbps' : '—' ?></span>
    </div>

    <h4 class="lv-label" style="margin-top:18px;">CINR (dB)</h4>
    <?= $cinr_gauge($link['snr_db_remote'] !== null ? (int)$link['snr_db_remote'] : null) ?>
  </div>
</div>

<div class="lv-grid" style="margin-top:18px;">
  <div class="portal-card">
    <h3 class="lv-label">More details — local</h3>
    <div class="lv-row"><span><b>Device model</b></span><span><?= $h($link['ap_model']) ?></span></div>
    <div class="lv-row"><span><b>Version</b></span>     <span><?= $h($link['ap_firmware']) ?></span></div>
    <div class="lv-row"><span><b>UNMS</b> connected</span> <span><?= $ap_h ? $h($ap_h['polled_at']) : '—' ?></span></div>
    <div class="lv-row"><span><b>Uptime</b></span>      <span><?= $fmt_uptime($ap_h['uptime_seconds'] ?? null) ?></span></div>
    <div class="lv-row">
      <span><b>Memory</b></span>
      <span><?= $ap_h && $ap_h['mem_pct'] !== null ? number_format((float)$ap_h['mem_pct'], 0) . ' %' : '—' ?></span>
    </div>
    <div class="lv-row">
      <span><b>CPU</b></span>
      <span><?= $ap_h && $ap_h['cpu_pct'] !== null ? number_format((float)$ap_h['cpu_pct'], 0) . ' %' : '—' ?></span>
    </div>

    <h3 class="lv-label" style="margin-top:18px;">Wireless</h3>
    <div class="lv-row"><span><b>Wireless mode</b></span><span><?= $h($link['wireless_mode'] ?? '') ?></span></div>
    <div class="lv-row"><span><b>Security</b></span>     <span><?= $h(strtoupper($link['security'] ?? 'open')) ?></span></div>
    <div class="lv-row"><span><b>SSID</b></span>         <span><?= $h($link['ssid'] ?? '') ?></span></div>
    <div class="lv-row"><span><b>Distance</b></span>
      <span><?= $link['distance_km'] !== null ? number_format((float)$link['distance_km'], 2) . ' km' : '—' ?></span></div>
    <div class="lv-row"><span><b>TX / RX bytes</b></span>
      <span><?= $fmt_bytes($link['tx_bytes'] !== null ? (int)$link['tx_bytes'] : null) ?>
            / <?= $fmt_bytes($link['rx_bytes'] !== null ? (int)$link['rx_bytes'] : null) ?></span></div>

    <h3 class="lv-label" style="margin-top:18px;">Ethernet</h3>
    <?php if ($ap_eth): ?>
      <div class="lv-row"><span><b><?= $h($ap_eth['lan_port']) ?> speed</b></span>
        <span><?= $ap_eth['link_speed_mbps'] !== null ? number_format((float)$ap_eth['link_speed_mbps'], 0) . ' Mbps · ' . $h($ap_eth['duplex']) : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable SNR</b></span>
        <span><?= $ap_eth['cable_snr_db'] !== null ? '+' . number_format((float)$ap_eth['cable_snr_db'], 0) . ' dB' : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable length</b></span>
        <span><?= $ap_eth['cable_length_m'] !== null ? number_format((float)$ap_eth['cable_length_m'], 0) . ' m / ' . number_format((float)$ap_eth['cable_length_m'] / 0.3048, 0) . ' ft' : '—' ?></span></div>
    <?php else: ?>
      <small class="muted">No cable diagnostics yet.</small>
    <?php endif; ?>
  </div>

  <div class="portal-card">
    <h3 class="lv-label">More details — remote</h3>
    <?php if ($cpe_h): ?>
      <div class="lv-row"><span><b>Device model</b></span><span><?= $h($link['cpe_model']) ?></span></div>
      <div class="lv-row"><span><b>Version</b></span>     <span><?= $h($link['cpe_firmware']) ?></span></div>
      <div class="lv-row"><span><b>UNMS</b> connected</span> <span><?= $h($cpe_h['polled_at']) ?></span></div>
      <div class="lv-row"><span><b>Uptime</b></span>      <span><?= $fmt_uptime($cpe_h['uptime_seconds'] ?? null) ?></span></div>
      <div class="lv-row"><span><b>Memory</b></span>
        <span><?= $cpe_h['mem_pct'] !== null ? number_format((float)$cpe_h['mem_pct'], 0) . ' %' : '—' ?></span></div>
      <div class="lv-row"><span><b>CPU</b></span>
        <span><?= $cpe_h['cpu_pct'] !== null ? number_format((float)$cpe_h['cpu_pct'], 0) . ' %' : '—' ?></span></div>
    <?php else: ?>
      <small class="muted">CPE not polled yet — register credentials in /admin/devices.php.</small>
    <?php endif; ?>

    <h3 class="lv-label" style="margin-top:18px;">Wireless</h3>
    <div class="lv-row"><span><b>Wireless mode</b></span><span><?= $h($link['wireless_mode'] ?? '') ?></span></div>
    <div class="lv-row"><span><b>Connection time</b></span>
      <span><?= $fmt_uptime($link['uptime_seconds'] !== null ? (int)$link['uptime_seconds'] : null) ?></span></div>
    <div class="lv-row"><span><b>Remote IP</b></span><span class="muted">via DHCP</span></div>
    <div class="lv-row"><span><b>Distance</b></span>
      <span><?= $link['distance_km'] !== null ? number_format((float)$link['distance_km'], 2) . ' km' : '—' ?></span></div>
    <div class="lv-row"><span><b>TX / RX bytes</b></span>
      <span><?= $fmt_bytes($link['tx_bytes'] !== null ? (int)$link['tx_bytes'] : null) ?>
            / <?= $fmt_bytes($link['rx_bytes'] !== null ? (int)$link['rx_bytes'] : null) ?></span></div>

    <h3 class="lv-label" style="margin-top:18px;">Ethernet</h3>
    <?php if ($cpe_eth): ?>
      <div class="lv-row"><span><b><?= $h($cpe_eth['lan_port']) ?> speed</b></span>
        <span><?= $cpe_eth['link_speed_mbps'] !== null ? number_format((float)$cpe_eth['link_speed_mbps'], 0) . ' Mbps · ' . $h($cpe_eth['duplex']) : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable SNR</b></span>
        <span><?= $cpe_eth['cable_snr_db'] !== null ? '+' . number_format((float)$cpe_eth['cable_snr_db'], 0) . ' dB' : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable length</b></span>
        <span><?= $cpe_eth['cable_length_m'] !== null ? number_format((float)$cpe_eth['cable_length_m'], 0) . ' m / ' . number_format((float)$cpe_eth['cable_length_m'] / 0.3048, 0) . ' ft' : '—' ?></span></div>
    <?php else: ?>
      <small class="muted">No cable diagnostics yet.</small>
    <?php endif; ?>
  </div>
</div>

<div style="margin-top:18px;display:flex;gap:8px;align-items:center;justify-content:space-between;">
  <div>
    <a class="btn btn-ghost btn-sm" href="/admin/links.php">← All links</a>
    <?php if ($link['sector_id']): ?>
      <a class="btn btn-ghost btn-sm" href="/admin/sector-edit.php?id=<?= (int)$link['sector_id'] ?>">Open sector</a>
    <?php endif; ?>
  </div>
  <small class="muted">last evaluated: <?= $h($link['last_evaluated_at'] ?? '—') ?></small>
</div>
<?php endif; ?>
