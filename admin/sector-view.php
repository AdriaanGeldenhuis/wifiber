<?php
/**
 * Sector dashboard — UISP-style "Sector" details for a single sectors
 * row. Same banner-and-cards layout as link-view.php / site-link-view.php
 * so the operator's eye doesn't have to retrain between pages.
 *
 *   Banner       Tower (parent site) icon + tower name + height + GPS,
 *                channel-utilisation dial in the centre, frequency dial,
 *                AP-device card on the right with status pill + uptime.
 *   Tabs         Map · Sector · Frequency planner · Edit
 *   Cards        AP device's RF environment (last hour) heat-bar
 *                with the operating channel highlighted; current state
 *                (band / frequency / width / TX power / wireless mode /
 *                security / SSID / TDD framing / airmax priority); CINR
 *                gauge driven by the freshest link sample on the sector.
 *   Stations     Connected wireless_links rows: customer name, signal,
 *                noise, SNR, CCQ, TX/RX rate, distance, health pill,
 *                deep-linkable to the per-radio dashboard.
 *   Capacity     customer_count vs max_clients bar + signal-quality
 *                histogram across the sector's CPEs.
 *   Bottom       Recent push-to-radio change jobs scoped to this sector.
 *
 * Data sources:
 *   sectors                 the row itself.
 *   sites                   parent tower (lat, lng, height).
 *   devices                 AP device + status / firmware / model.
 *   device_health           latest CPU / memory / uptime / RTT for AP.
 *   ethernet_health         latest LAN speed / cable SNR / cable length.
 *   rf_environment_samples  last 60 min on the AP device.
 *   wireless_links          connected stations on this sector.
 *   wireless_change_jobs    recent push-to-radio jobs for this sector.
 */
$page_title = 'Sector';
$active_key = 'sectors';
$auto_refresh_seconds = 60;
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/poll_status.php';
require_once __DIR__ . '/_link-charts.php';

$id = (int)($_GET['id'] ?? 0);
$sector = $id ? sector_find($id) : null;
if (!$sector) {
    echo '<div class="portal-card"><h2>Sector not found</h2>'
       . '<p>Pick one from <a href="/admin/sectors.php">/admin/sectors.php</a>.</p></div>';
    return;
}

$tower = $sector['tower_id'] ? site_find((int)$sector['tower_id']) : null;
$ap    = $sector['ap_device_id'] ? device_find((int)$sector['ap_device_id']) : null;

$tab = (string)($_GET['tab'] ?? 'sector');

/* AP device live state. */
$ap_health = $ap ? (device_recent_health((int)$ap['id'], 1)[0] ?? null) : null;
$ap_eth    = $ap ? ethernet_health_latest((int)$ap['id']) : null;
$rf        = $ap ? rf_environment_recent((int)$ap['id'], 60) : [];

/* Connected stations on this sector (wireless_links rows). */
$stations = wireless_links_all(['sector_id' => (int)$sector['id']]);

/* Aggregate sector stats from the connected stations. */
$signals = []; $snrs = []; $ccqs = []; $caps = []; $thrus = []; $health_scores = [];
foreach ($stations as $s) {
    if ($s['signal_dbm']    !== null) $signals[] = (int)$s['signal_dbm'];
    if ($s['snr_db']        !== null) $snrs[]    = (int)$s['snr_db'];
    if ($s['ccq_pct']       !== null) $ccqs[]    = (float)$s['ccq_pct'];
    if (isset($s['capacity_local_mbps'])    && $s['capacity_local_mbps']    !== null) $caps[]  = (float)$s['capacity_local_mbps'];
    if (isset($s['throughput_local_mbps'])  && $s['throughput_local_mbps']  !== null) $thrus[] = (float)$s['throughput_local_mbps'];
    if ($s['health_score']  !== null) $health_scores[] = (int)$s['health_score'];
}
$avg_signal = $signals ? (int)round(array_sum($signals) / count($signals)) : null;
$avg_snr    = $snrs    ? (int)round(array_sum($snrs)    / count($snrs))    : null;
$avg_ccq    = $ccqs    ? round(array_sum($ccqs) / count($ccqs), 0)         : null;
$total_cap  = $caps    ? array_sum($caps) : null;
$total_thru = $thrus   ? array_sum($thrus) : null;
$avg_health = $health_scores ? (int)round(array_sum($health_scores) / count($health_scores)) : null;

/* Signal-quality histogram bins for the connected stations. */
$bins = ['excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0];
foreach ($signals as $sig) {
    if      ($sig >= -55) $bins['excellent']++;
    elseif  ($sig >= -67) $bins['good']++;
    elseif  ($sig >= -75) $bins['fair']++;
    else                  $bins['poor']++;
}

/* Recent push-to-radio jobs scoped to this sector. */
$jobs = wireless_change_jobs_recent(['scope' => 'sector', 'scope_id' => (int)$sector['id']], 10);

$band      = (string)($sector['band'] ?? '');
$freq_mhz  = $sector['frequency_mhz'];
$width_mhz = $sector['channel_width_mhz'];
$cust_cnt  = (int)($sector['customer_count'] ?? 0);
$max_cli   = $sector['max_clients'] !== null ? (int)$sector['max_clients'] : null;
$cust_pct  = ($max_cli && $max_cli > 0) ? min(100.0, $cust_cnt / $max_cli * 100) : null;

/* Frequency dial value. */
$freq_ghz_val = $freq_mhz !== null ? $freq_mhz / 1000.0 : null;
?>

<style>
  .lv-grid     { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
  .lv-grid > * { min-width:0; }
  @media (max-width: 980px) { .lv-grid { grid-template-columns: 1fr; } }

  .lv-bigstat  { font-size:34px; font-weight:300; line-height:1; color:var(--text); letter-spacing:-0.02em; }
  .lv-suffix   { font-size:13px; color:var(--text-muted); }
  .lv-label    { font-size:10.5px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.07em; font-weight:600; }
  .lv-row      { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04); font-size:13px; gap:12px; }
  .lv-row:last-child { border-bottom:none; }
  .lv-row b    { font-weight:500; color:var(--text-dim); }
  .lv-row span:last-child { color:var(--text); font-variant-numeric:tabular-nums; }

  .lv-pill     { display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;color:#001218;font-weight:700;letter-spacing:.02em;text-transform:uppercase; }
  .lv-tag      { display:inline-block;padding:1px 8px;border-radius:8px;font-size:10.5px;color:var(--text-dim);background:rgba(255,255,255,0.04);border:1px solid var(--border);letter-spacing:.05em; }

  .lv-tabs     { display:flex; gap:4px; padding:4px; background:var(--bg-elev); border:1px solid var(--border); border-radius:9px; width:max-content; margin:18px auto; }
  .lv-tab      { padding:5px 16px; border-radius:7px; font-size:12px; color:var(--text-dim); }
  .lv-tab:hover { color:var(--text); }
  .lv-tab.active { background:var(--accent-soft); color:var(--accent); box-shadow:inset 0 0 0 1px var(--accent); }

  .lv-banner {
    display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; gap:20px;
    padding:18px 22px; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
  }
  .lv-banner .lv-side  { display:flex; align-items:center; gap:16px; }
  .lv-banner .lv-side.lv-end { justify-content:flex-end; }
  .lv-banner .lv-mid   { text-align:center; }
  .lv-icon {
    width:46px; height:46px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
    border-radius:50%; background:var(--bg-elev); border:1px solid var(--border-strong); color:var(--accent);
  }
  .lv-banner-distance {
    display:inline-flex; align-items:center; gap:8px;
    background:#000; color:#f4f6f8; padding:8px 16px; border-radius:18px; font-size:13px;
    border:1px solid var(--border-strong); font-variant-numeric:tabular-nums;
  }
  .lv-airtime  { font-size:11px; color:var(--text-muted); margin-top:6px; letter-spacing:.04em; }
  .lv-airtime b { color:var(--text-dim); font-weight:500; }
  .lv-arrow    {
    flex:1; display:flex; align-items:center; justify-content:center; gap:8px;
    color:var(--text-muted); font-size:11px;
  }
  .lv-arrow .lv-arrow-line { flex:1; height:1px; background:linear-gradient(90deg, transparent, var(--border-strong), transparent); }

  .lv-dial {
    display:inline-flex; flex-direction:column; align-items:center; justify-content:center;
    width:88px; height:88px; border-radius:50%;
    border:3px solid var(--accent); background:var(--bg-elev);
    box-shadow:0 0 0 4px var(--accent-soft); flex-shrink:0;
  }
  .lv-dial small { display:block; font-size:8.5px; color:var(--text-muted); text-align:center; line-height:1.05; text-transform:uppercase; letter-spacing:.04em; }
  .lv-dial b     { font-size:18px; font-weight:500; color:var(--text); margin:2px 0; font-variant-numeric:tabular-nums; }
  .lv-dial .lv-dial-unit { font-size:9px; color:var(--text-muted); }

  .lv-endpoint h4 { margin:0 0 2px; font-size:14px; font-weight:600; color:var(--text); }

  .lv-grid-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
  .lv-grid-hdr h3 { margin:0; }
  .lv-mini-section { padding-top:14px; border-top:1px solid var(--border); margin-top:14px; }
  .lv-mini-section h4 { font-size:11px; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); margin:0 0 8px; font-weight:600; }

  .lv-meter    { display:flex; align-items:center; gap:10px; }
  .lv-meter .lv-bar { flex:1; height:8px; border-radius:4px; background:rgba(255,255,255,0.05); overflow:hidden; }
  .lv-meter .lv-bar > span { display:block; height:100%; border-radius:4px; }
  .lv-meter .lv-mem  > span { background:#a25cf0; }
  .lv-meter .lv-cpu  > span { background:#05DAFD; }
  .lv-meter .lv-cap  > span { background:#05DAFD; }
  .lv-meter b  { font-weight:500; color:var(--text); font-variant-numeric:tabular-nums; min-width:62px; text-align:right; }

  .sig-bar     { display:grid; grid-template-columns: 80px 1fr 50px; align-items:center; gap:10px; font-size:12px; padding:4px 0; }
  .sig-bar .name { color:var(--text-dim); }
  .sig-bar .bar  { height:8px; border-radius:4px; background:rgba(255,255,255,0.05); overflow:hidden; }
  .sig-bar .bar > span { display:block; height:100%; border-radius:4px; }
  .sig-bar .cnt { text-align:right; color:var(--text); font-variant-numeric:tabular-nums; }

  .data-table.compact th, .data-table.compact td { padding:8px 10px; font-size:12.5px; }
  .data-table tr.row-poor td { background:rgba(212,68,68,0.06); }
  .data-table tr.row-fair td { background:rgba(232,168,20,0.06); }
</style>

<div class="lv-banner">
  <div class="lv-side lv-endpoint">
    <span class="lv-icon" title="Tower · <?= lv_h($tower['name'] ?? '—') ?>">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 18 12 4l7 14"/><path d="M9 14h6"/><path d="M12 4v16"/>
      </svg>
    </span>
    <div>
      <div class="lv-label">Tower</div>
      <h4><?= lv_h($tower['name'] ?? '—') ?></h4>
      <?php if ($tower): ?>
        <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
          <?= number_format((float)$tower['lat'], 5) ?>, <?= number_format((float)$tower['lng'], 5) ?>
          <?php if ($tower['height_m'] !== null): ?> · <?= number_format((float)$tower['height_m'], 1) ?> m<?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        Sector · <strong style="color:var(--text);"><?= lv_h($sector['name']) ?></strong>
      </div>
    </div>
    <div class="lv-dial" title="Connected stations / capacity">
      <small>Stations<br>Capacity</small>
      <b><?= $cust_cnt ?> / <?= $max_cli !== null ? $max_cli : '—' ?></b>
      <span class="lv-dial-unit">clients</span>
    </div>
  </div>

  <div class="lv-mid">
    <div class="lv-arrow">
      <span class="lv-arrow-line"></span>
      <span class="lv-banner-distance">
        <?php if ($sector['azimuth_deg'] !== null): ?>
          <strong><?= (int)$sector['azimuth_deg'] ?>°</strong>
          <span class="muted"><?= $sector['beamwidth_deg'] !== null ? '· ' . (int)$sector['beamwidth_deg'] . '° beam' : '' ?></span>
        <?php else: ?>
          <strong><?= lv_h($band ?: 'Sector') ?></strong>
        <?php endif; ?>
      </span>
      <span class="lv-arrow-line"></span>
    </div>
    <div class="lv-airtime">
      <b>Band</b> <?= lv_h($band ?: '—') ?>
      <?php if ($freq_mhz !== null): ?>&nbsp;·&nbsp; <b>Frequency</b> <?= (int)$freq_mhz ?> MHz<?php endif; ?>
      <?php if ($width_mhz !== null): ?>&nbsp;·&nbsp; <b>Width</b> <?= (int)$width_mhz ?> MHz<?php endif; ?>
    </div>
    <div class="lv-airtime"><b>TX power</b> <?= $sector['tx_power_dbm'] !== null ? (int)$sector['tx_power_dbm'] . ' dBm' : '—' ?>
      &nbsp;·&nbsp; <b>Health</b> <?= lv_health_pill($avg_health) ?></div>
    <div class="lv-airtime"><b>Throughput</b>
      <?= $total_thru !== null ? number_format($total_thru, 1) . ' Mbps' : '—' ?>
      <?php if ($total_cap !== null): ?> / <?= number_format($total_cap, 0) ?> Mbps capacity<?php endif; ?>
    </div>
  </div>

  <div class="lv-side lv-end lv-endpoint">
    <div class="lv-dial" title="Frequency">
      <small>Frequency</small>
      <b><?= $freq_ghz_val !== null ? number_format($freq_ghz_val, 2) : '—' ?></b>
      <span class="lv-dial-unit"><?= $freq_ghz_val !== null ? 'GHz' : '—' ?></span>
    </div>
    <div style="text-align:right;">
      <div class="lv-label">AP device</div>
      <h4><?= lv_h($ap['name'] ?? '— not assigned —') ?>
        <?php if ($ap): ?><?= lv_status_pill($ap['status'] ?? null) ?><?php endif; ?>
      </h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?php if ($ap): ?>
          <?= lv_h(ucfirst((string)($ap['vendor'] ?? ''))) ?> · <?= lv_h($ap['model'] ?? '') ?>
        <?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        <?php if ($ap_health): ?>
          Uptime <?= lv_fmt_uptime((int)($ap_health['uptime_seconds'] ?? 0)) ?>
        <?php endif; ?>
      </div>
    </div>
    <span class="lv-icon" title="AP">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 12a7 7 0 0 1 14 0"/><path d="M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>
      </svg>
    </span>
  </div>
</div>

<?php
  $sector_freshness = poll_classify(poll_sector_latest_at((int)$sector['id']));
  $sector_pollable  = $ap && !empty($ap['mgmt_ip']) && in_array($ap['vendor'] ?? '', ['ubiquiti','mikrotik','cambium','mimosa'], true);
?>
<div style="display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:14px;">
  <?= poll_badge_html($sector_freshness, 'Newest sample across stations on this sector') ?>
  <?php if ($sector_pollable): ?>
    <button type="button" class="btn btn-ghost btn-sm" data-poll-device-now="<?= (int)$ap['id'] ?>" data-poll-device-name="<?= lv_h($ap['name']) ?>" title="Run the vendor adapter against the AP for this sector">Poll AP now</button>
    <a class="btn btn-primary btn-sm" href="/admin/sector-commission.php?id=<?= (int)$sector['id'] ?>" title="Mobile-first live AP dashboard for commissioning a fresh install">Commission ↗</a>
  <?php endif; ?>
  <a class="btn btn-ghost btn-sm" href="/admin/diagnostics.php">Polling status ↗</a>
</div>

<div class="lv-tabs">
  <a class="lv-tab" href="/admin/map.php?focus=sector&amp;id=<?= (int)$sector['id'] ?>">Map</a>
  <a class="lv-tab <?= $tab === 'sector'    ? 'active' : '' ?>" href="?id=<?= (int)$sector['id'] ?>&amp;tab=sector">Sector</a>
  <a class="lv-tab" href="/admin/freq-planner.php?sector_id=<?= (int)$sector['id'] ?>">Frequency planner</a>
  <a class="lv-tab" href="/admin/sector-edit.php?id=<?= (int)$sector['id'] ?>">Edit</a>
  <?php if ($ap): ?>
    <a class="lv-tab" href="/admin/device-view.php?id=<?= (int)$ap['id'] ?>">AP device ↗</a>
  <?php endif; ?>
</div>

<div class="lv-grid">
  <!-- Left: AP device live state + RF environment -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">AP device · RF environment</h3>
      <span class="lv-tag"><?= $freq_mhz !== null ? (int)$freq_mhz . ' MHz' : '—' ?>
        <?php if ($width_mhz !== null): ?> · <?= (int)$width_mhz ?> MHz<?php endif; ?></span>
    </div>
    <h4 class="lv-label">RF environment <span style="color:var(--text-muted);">(last hour)</span></h4>
    <?= lv_rf_bars($rf, $freq_mhz !== null ? (int)$freq_mhz : null,
                       $width_mhz !== null ? (int)$width_mhz : null) ?>

    <div class="lv-mini-section" style="border-top:0;padding-top:10px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
        <div>
          <div class="lv-label">Avg signal · sector</div>
          <strong class="lv-bigstat"><?= $avg_signal !== null ? (int)$avg_signal : '—' ?></strong>
          <span class="lv-suffix">dBm · <?= count($signals) ?> stations</span>
        </div>
        <div style="text-align:right;">
          <div class="lv-label">Avg CCQ</div>
          <strong style="font-size:18px;font-weight:400;color:var(--text);">
            <?= $avg_ccq !== null ? number_format($avg_ccq, 0) . ' %' : '—' ?>
          </strong>
        </div>
      </div>
    </div>

    <div class="lv-mini-section">
      <h4>CINR (avg dB across stations)</h4>
      <?= lv_cinr_gauge($avg_snr) ?>
    </div>

    <div class="lv-mini-section">
      <h4>Signal-quality distribution</h4>
      <?php
      $colour_map = ['excellent' => '#4ade80', 'good' => '#05DAFD', 'fair' => '#e8a814', 'poor' => '#ff5470'];
      $thresholds = ['excellent' => '≥ −55 dBm', 'good' => '−55 to −67', 'fair' => '−67 to −75', 'poor' => '< −75'];
      $total_sig = max(1, array_sum($bins));
      foreach ($bins as $name => $cnt):
        $pct = $cnt / $total_sig * 100;
      ?>
        <div class="sig-bar">
          <span class="name"><?= ucfirst($name) ?> <small class="muted"><?= $thresholds[$name] ?></small></span>
          <span class="bar"><span style="width:<?= $pct ?>%;background:<?= $colour_map[$name] ?>;"></span></span>
          <span class="cnt"><?= $cnt ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Right: Sector configuration + AP health -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Sector configuration</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/sector-edit.php?id=<?= (int)$sector['id'] ?>">Edit ↗</a>
    </div>
    <div class="lv-row"><span><b>Name</b></span>          <span><?= lv_h($sector['name']) ?></span></div>
    <div class="lv-row"><span><b>Tower</b></span>
      <span><?php if ($tower): ?>
        <a href="/admin/site-view.php?id=<?= (int)$tower['id'] ?>"><?= lv_h($tower['name']) ?></a>
      <?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Band</b></span>          <span><?= lv_h($band ?: '—') ?></span></div>
    <div class="lv-row"><span><b>Frequency</b></span>     <span><?= $freq_mhz !== null ? (int)$freq_mhz . ' MHz' : '—' ?></span></div>
    <div class="lv-row"><span><b>Channel width</b></span> <span><?= $width_mhz !== null ? (int)$width_mhz . ' MHz' : '—' ?></span></div>
    <div class="lv-row"><span><b>TX power</b></span>      <span><?= $sector['tx_power_dbm'] !== null ? (int)$sector['tx_power_dbm'] . ' dBm' : '—' ?></span></div>
    <div class="lv-row"><span><b>Azimuth / beam</b></span>
      <span><?= $sector['azimuth_deg'] !== null ? (int)$sector['azimuth_deg'] . '°' : '—' ?>
        / <?= $sector['beamwidth_deg'] !== null ? (int)$sector['beamwidth_deg'] . '°' : '—' ?></span></div>
    <div class="lv-row"><span><b>Wireless mode</b></span><span><?= lv_h($sector['wireless_mode'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>SSID</b></span>          <span><?= lv_h($sector['ssid'] ?? '') ?: '—' ?></span></div>
    <div class="lv-row"><span><b>Security</b></span>      <span><?= lv_h(strtoupper((string)($sector['security'] ?? 'open'))) ?></span></div>
    <div class="lv-row"><span><b>TDD framing</b></span>   <span><?= lv_h($sector['tdd_framing'] ?? '') ?: '—' ?></span></div>
    <div class="lv-row"><span><b>airMAX priority</b></span><span><?= lv_h($sector['airmax_ac_priority'] ?? '') ?: '—' ?></span></div>
    <div class="lv-row"><span><b>DFS</b></span>           <span><?= !empty($sector['dfs_enabled']) ? 'Enabled' : 'Disabled' ?></span></div>
    <div class="lv-row"><span><b>Max clients</b></span>   <span><?= $max_cli !== null ? $max_cli : '—' ?></span></div>

    <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Capacity</h3>
    <div class="lv-row">
      <span><b>Stations</b> <?= $cust_cnt ?> / <?= $max_cli !== null ? $max_cli : '∞' ?></span>
      <span class="lv-meter" style="min-width:200px;">
        <span class="lv-bar lv-cap"><span style="width:<?= $cust_pct !== null ? $cust_pct : 0 ?>%;"></span></span>
        <b><?= $cust_pct !== null ? number_format($cust_pct, 0) . ' %' : '—' ?></b>
      </span>
    </div>

    <?php if ($ap_health): ?>
      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">AP health</h3>
      <div class="lv-row"><span><b>Date (synced)</b></span><span><?= lv_h(lv_fmt_dt($ap_health['polled_at'] ?? null)) ?></span></div>
      <div class="lv-row"><span><b>Uptime</b></span>      <span><?= lv_fmt_uptime((int)($ap_health['uptime_seconds'] ?? 0)) ?></span></div>
      <?php $mem = $ap_health['mem_pct'] ?? null; $cpu = $ap_health['cpu_pct'] ?? null; ?>
      <div class="lv-row">
        <span><b>Memory</b></span>
        <span class="lv-meter" style="min-width:200px;">
          <span class="lv-bar lv-mem"><span style="width:<?= $mem !== null ? (int)$mem : 0 ?>%;"></span></span>
          <b><?= $mem !== null ? (int)$mem . ' %' : '—' ?></b>
        </span>
      </div>
      <div class="lv-row">
        <span><b>CPU</b></span>
        <span class="lv-meter" style="min-width:200px;">
          <span class="lv-bar lv-cpu"><span style="width:<?= $cpu !== null ? (int)$cpu : 0 ?>%;"></span></span>
          <b><?= $cpu !== null ? (int)$cpu . ' %' : '—' ?></b>
        </span>
      </div>
    <?php endif; ?>

    <?php if ($ap_eth): ?>
      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Ethernet (AP)</h3>
      <div class="lv-row"><span><b>LAN speed</b></span>
        <span><?= $ap_eth['link_speed_mbps'] !== null
            ? number_format((float)$ap_eth['link_speed_mbps'], 0) . ' Mbps-' . lv_h(ucfirst((string)$ap_eth['duplex']))
            : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable SNR</b></span>
        <span><?= $ap_eth['cable_snr_db'] !== null
            ? '+' . number_format((float)$ap_eth['cable_snr_db'], 0) . ' dB' : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable length</b></span>
        <span><?= $ap_eth['cable_length_m'] !== null
            ? number_format((float)$ap_eth['cable_length_m'] / 0.3048, 0) . ' ft · '
              . number_format((float)$ap_eth['cable_length_m'], 0) . ' m'
            : '—' ?></span></div>
    <?php endif; ?>
  </div>
</div>

<!-- Connected stations table -->
<div class="portal-card" style="margin-top:18px;">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">Connected stations <span style="color:var(--text-muted);">(<?= count($stations) ?>)</span></h3>
    <a class="btn btn-ghost btn-sm" href="/admin/links.php?sector_id=<?= (int)$sector['id'] ?>">All links ↗</a>
  </div>
  <?php if (!$stations): ?>
    <small class="muted">No stations registered on this sector yet. Once the AP polls and a CPE associates, links auto-register here.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table compact">
      <thead>
        <tr>
          <th>Health</th>
          <th>CPE</th>
          <th>Customer</th>
          <th>Signal</th>
          <th>SNR</th>
          <th>CCQ</th>
          <th>TX / RX</th>
          <th>Distance</th>
          <th>Last sample</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stations as $st):
          $row_class = '';
          if ($st['health_score'] !== null) {
            if      ($st['health_score'] < 50) $row_class = 'row-poor';
            elseif  ($st['health_score'] < 75) $row_class = 'row-fair';
          }
          $cust = trim((string)($st['customer_name'] ?? '') . ' ' . (string)($st['customer_surname'] ?? ''));
        ?>
          <tr class="<?= $row_class ?>">
            <td><?= lv_health_pill($st['health_score']) ?></td>
            <td>
              <strong><?= lv_h($st['cpe_name'] ?? '—') ?></strong>
              <?php if (!empty($st['cpe_model'])): ?><br><small class="muted"><?= lv_h($st['cpe_model']) ?></small><?php endif; ?>
            </td>
            <td><?= lv_h($cust) ?: '<span class="muted">—</span>' ?></td>
            <td><?= $st['signal_dbm']  !== null ? (int)$st['signal_dbm']  . ' <small class="muted">dBm</small>' : '<small class="muted">—</small>' ?></td>
            <td><?= $st['snr_db']      !== null ? (int)$st['snr_db']      . ' <small class="muted">dB</small>'  : '<small class="muted">—</small>' ?></td>
            <td><?= $st['ccq_pct']     !== null ? number_format((float)$st['ccq_pct'], 0) . '%' : '<small class="muted">—</small>' ?></td>
            <td>
              <?php if ($st['tx_rate_mbps'] !== null): ?>
                <?= number_format((float)$st['tx_rate_mbps'], 0) ?> /
                <?= number_format((float)($st['rx_rate_mbps'] ?? 0), 0) ?>
                <small class="muted">Mbps</small>
              <?php else: ?><small class="muted">—</small><?php endif; ?>
            </td>
            <td><?= $st['distance_km'] !== null ? '<small>' . number_format((float)$st['distance_km'], 2) . ' km</small>' : '<small class="muted">—</small>' ?></td>
            <td><small><?= $st['last_evaluated_at'] ? lv_freshness_html($st['last_evaluated_at']) : '<span class="muted">never</span>' ?></small></td>
            <td><a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=<?= (int)$st['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<!-- Recent push-to-radio jobs -->
<?php if ($jobs): ?>
<div class="portal-card" style="margin-top:18px;">
  <h3 class="lv-label" style="font-size:11px;">Recent push-to-radio jobs <span style="color:var(--text-muted);">(<?= count($jobs) ?>)</span></h3>
  <table class="data-table compact">
    <thead><tr><th>Job</th><th>Status</th><th>Requested by</th><th>Created</th><th>Finished</th><th>Notes</th></tr></thead>
    <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td>#<?= (int)$j['id'] ?></td>
          <td><span class="lv-pill" style="background:<?= match($j['status']) {
              'applied' => '#4ade80',
              'failed' => '#ff5470',
              'queued' => '#6b7480',
              'applying' => '#05DAFD',
              default => '#6b7480',
          } ?>;color:#001218;"><?= lv_h($j['status']) ?></span></td>
          <td><?= lv_h($j['requester_name'] ?? '—') ?></td>
          <td><small><?= lv_h($j['created_at']) ?></small></td>
          <td><small><?= lv_h($j['finished_at'] ?? '—') ?></small></td>
          <td><small class="muted"><?= lv_h($j['error'] ?? '') ?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($sector['notes'])): ?>
<div class="portal-card" style="margin-top:18px;">
  <h3 class="lv-label" style="font-size:11px;">Notes</h3>
  <p style="white-space:pre-wrap;color:var(--text-dim);"><?= lv_h($sector['notes']) ?></p>
</div>
<?php endif; ?>

<div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="/admin/sectors.php">← All sectors</a>
    <?php if ($tower): ?><a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$tower['id'] ?>">Open tower</a><?php endif; ?>
    <?php if ($ap):    ?><a class="btn btn-ghost btn-sm" href="/admin/device-view.php?id=<?= (int)$ap['id'] ?>">Open AP</a><?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="/admin/freq-planner.php?sector_id=<?= (int)$sector['id'] ?>">Frequency planner</a>
    <a class="btn btn-primary btn-sm" href="/admin/sector-edit.php?id=<?= (int)$sector['id'] ?>">Edit sector</a>
  </div>
</div>
