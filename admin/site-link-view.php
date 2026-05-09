<?php
/**
 * Backbone link dashboard — UISP-style "Link" details for a site_links
 * row (site-to-site PTP / fibre / backhaul). Mirrors the layout used by
 * /admin/link-view.php (which renders per-radio AP↔CPE records) so the
 * operator sees the same banner, tabs, RF cards, signal chart, MCS
 * ladder, CINR gauge, and per-side "More details" / Wireless /
 * Ethernet sections — but the endpoints are sites instead of devices,
 * and the live radio data comes from the wireless_links row that
 * connects the two sites' devices (when one exists).
 *
 *   - If a wireless_links record connects a device at the from-site
 *     to one at the to-site, we treat that as the live "radio leg":
 *     RF environment, per-chain signal levels, MCS index, 24h
 *     signal/noise chart, CINR, TX/RX bytes, ethernet diagnostics
 *     all come from it. The full UISP-style dashboard is rendered.
 *   - If no wireless_links record exists (e.g. pure fibre, or radios
 *     not yet polled), the layout still renders with the same
 *     skeleton but radio cards show "—" / "no samples yet"
 *     placeholders so the operator can still see the surrounding
 *     metadata (capacity, frequency, distance, bearing, sites,
 *     sectors, devices, ethernet for any monitored device, notes).
 *
 * Data sources:
 *   site_links              the row itself (capacity / freq / type / label).
 *   sites                   from / to endpoint metadata (lat, lng,
 *                           height, type, coverage radius).
 *   sectors                 sectors at each tower endpoint.
 *   devices                 devices at each endpoint with status pills.
 *   device_health           latest poll for each endpoint device
 *                           (CPU / memory / uptime / firmware).
 *   ethernet_health         latest LAN speed / cable SNR / cable length.
 *   wireless_links          radio leg between two endpoints' devices
 *                           (full row via wireless_link_find()).
 *   link_health_samples     24h signal/noise/throughput history.
 *   rf_environment_samples  last hour's per-frequency RSSI bars.
 */
$page_title = 'Backbone link';
$active_key = 'links';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/_link-charts.php';

$id = (int)($_GET['id'] ?? 0);
$sl = $id ? site_link_find($id) : null;
if (!$sl) {
    echo '<div class="portal-card"><h2>Backbone link not found</h2>'
       . '<p>Pick one from <a href="/admin/links.php#backbone">/admin/links.php</a>.</p></div>';
    return;
}

$from = site_find((int)$sl['from_site_id']);
$to   = site_find((int)$sl['to_site_id']);
if (!$from || !$to) {
    echo '<div class="portal-card"><h2>Backbone link is broken</h2>'
       . '<p>One of the endpoint sites no longer exists. '
       . '<a href="/admin/links.php?edit=' . (int)$sl['id'] . '#backbone-form">Edit</a> or delete this link.</p></div>';
    return;
}

$tab = (string)($_GET['tab'] ?? 'link');

$dist_km = haversine_km(
    (float)$from['lat'], (float)$from['lng'],
    (float)$to['lat'],   (float)$to['lng']
);

/* Parse the human-entered frequency string ("5.8 GHz", "5800 MHz",
   "fibre", "6GHZ") into a number for Fresnel + dial display. */
$freq_ghz = null;
$freq_str = (string)($sl['frequency'] ?? '');
if ($freq_str !== '') {
    if (preg_match('/(\d+(?:\.\d+)?)\s*ghz/i', $freq_str, $m)) {
        $freq_ghz = (float)$m[1];
    } elseif (preg_match('/(\d+(?:\.\d+)?)\s*mhz/i', $freq_str, $m)) {
        $freq_ghz = (float)$m[1] / 1000.0;
    } elseif (preg_match('/^\s*(\d+(?:\.\d+)?)\s*$/', $freq_str, $m) && (float)$m[1] >= 100) {
        $freq_ghz = (float)$m[1] / 1000.0;
    }
}

$is_fibre = ($sl['type'] === 'fiber');

/* Endpoint devices / sectors. */
$from_devices = devices_all(['site_id' => (int)$from['id']]);
$to_devices   = devices_all(['site_id' => (int)$to['id']]);
$from_sectors = sectors_for_tower((int)$from['id']);
$to_sectors   = sectors_for_tower((int)$to['id']);

/* Pick the "primary" radio at each endpoint — the one that participates
   in the wireless leg, or the first AP / backhaul / CPE we find. */
function pick_primary_device(array $devices): ?array {
    foreach (['ap', 'backhaul', 'cpe', 'router'] as $role_pref) {
        foreach ($devices as $d) if (($d['role'] ?? '') === $role_pref) return $d;
    }
    return $devices[0] ?? null;
}
$from_dev = pick_primary_device($from_devices);
$to_dev   = pick_primary_device($to_devices);

/* Look for a wireless_links row whose AP & CPE devices live at our two
   endpoint sites. If we find one, fetch the full record (with all the
   joined device + sector fields) so we can render the radio dashboard. */
$radio_link    = null;
$radio_link_id = null;
if ($from_devices && $to_devices) {
    $from_ids = array_map('intval', array_column($from_devices, 'id'));
    $to_ids   = array_map('intval', array_column($to_devices,   'id'));
    $ph_a = implode(',', array_fill(0, count($from_ids), '?'));
    $ph_b = implode(',', array_fill(0, count($to_ids),   '?'));
    if ($ph_a !== '' && $ph_b !== '') {
        $stmt = pdo()->prepare(
            "SELECT id FROM wireless_links
              WHERE (ap_device_id IN ($ph_a) AND cpe_device_id IN ($ph_b))
                 OR (ap_device_id IN ($ph_b) AND cpe_device_id IN ($ph_a))
              ORDER BY last_evaluated_at DESC LIMIT 1"
        );
        $stmt->execute(array_merge($from_ids, $to_ids, $to_ids, $from_ids));
        $radio_link_id = $stmt->fetchColumn();
        if ($radio_link_id) {
            $radio_link = wireless_link_find((int)$radio_link_id);
        }
    }
}

/* Health for the picked radios (so the More-details cards have CPU /
   memory / uptime even when no wireless leg exists). */
$from_health = $from_dev ? (device_recent_health((int)$from_dev['id'], 1)[0] ?? null) : null;
$to_health   = $to_dev   ? (device_recent_health((int)$to_dev['id'],   1)[0] ?? null) : null;
$from_eth    = $from_dev ? ethernet_health_latest((int)$from_dev['id']) : null;
$to_eth      = $to_dev   ? ethernet_health_latest((int)$to_dev['id'])   : null;

/* If we have a wireless leg, prefer its richer per-side data:
   per-chain signals, MCS index, latest sample timestamp, freq/width
   from the radios themselves (more accurate than the human-typed
   site_links.frequency string). */
$samples = [];
$rf_from = []; $rf_to = [];
$radio_freq_mhz  = null; $radio_width_mhz = null;
if ($radio_link) {
    $samples = wireless_link_recent_samples((int)$radio_link['id'], 288);
    $rf_from = $radio_link['ap_device_id']  ? rf_environment_recent((int)$radio_link['ap_device_id'],  60) : [];
    $rf_to   = $radio_link['cpe_device_id'] ? rf_environment_recent((int)$radio_link['cpe_device_id'], 60) : [];
    $radio_freq_mhz  = $radio_link['frequency_mhz']     !== null ? (int)$radio_link['frequency_mhz']     : null;
    $radio_width_mhz = $radio_link['channel_width_mhz'] !== null ? (int)$radio_link['channel_width_mhz'] : null;
} else {
    if ($from_dev) $rf_from = rf_environment_recent((int)$from_dev['id'], 60);
    if ($to_dev)   $rf_to   = rf_environment_recent((int)$to_dev['id'],   60);
}
$rf_centre_mhz = $radio_freq_mhz  ?? ($freq_ghz !== null ? (int)round($freq_ghz * 1000) : null);
$rf_width_mhz  = $radio_width_mhz ?? null;

/* Initial bearing (compass heading) from -> to, for the More-details panel. */
function bearing_deg(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dl   = deg2rad($lng2 - $lng1);
    $y = sin($dl) * cos($phi2);
    $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dl);
    return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
}
function compass_label(float $deg): string {
    $names = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $names[(int)round($deg / 22.5) % 16];
}
$bearing = bearing_deg(
    (float)$from['lat'], (float)$from['lng'],
    (float)$to['lat'],   (float)$to['lng']
);

/* Fresnel calc when the link has a usable frequency. */
$fresnel = null;
if (!$is_fibre && $freq_ghz !== null && $dist_km > 0) {
    $r_m_full = 8.657 * sqrt($dist_km / $freq_ghz);
    $r_m_60   = $r_m_full * 0.6;
    $bulge    = ($dist_km * $dist_km) / (8 * 1.33);
    $a_h = $from['height_m'] ?? null;
    $b_h = $to['height_m']   ?? null;
    $needed_height = ($a_h !== null && $b_h !== null) ? ($r_m_60 + $bulge) : null;
    $clearance = ($a_h !== null && $b_h !== null)
        ? min((float)$a_h, (float)$b_h) - $needed_height : null;
    $fresnel = compact('dist_km','freq_ghz','r_m_full','r_m_60','bulge','a_h','b_h','needed_height','clearance');
}

/* Active alerts on the radio leg (we surface them above the cards). */
$alerts = [];
if ($radio_link) {
    $stmt = pdo()->prepare(
        "SELECT * FROM link_alerts WHERE link_id = ? AND resolved_at IS NULL ORDER BY opened_at DESC"
    );
    $stmt->execute([(int)$radio_link['id']]);
    $alerts = $stmt->fetchAll();
}

$type_labels = [
    'ptp'      => 'Point-to-point',
    'ptmp'     => 'Point-to-multipoint',
    'fiber'    => 'Fibre',
    'backhaul' => 'Backhaul',
];
$type_colour = [
    'ptp'      => '#05DAFD',
    'ptmp'     => '#4ade80',
    'fiber'    => '#a25cf0',
    'backhaul' => '#e8a814',
];
$type_label  = $type_labels[$sl['type']]  ?? $sl['type'];
$type_clr    = $type_colour[$sl['type']]  ?? '#05DAFD';

$tdd = $radio_link['tdd_framing'] ?? ($radio_link['sector_tdd_framing'] ?? '');

/* Per-side data unpacking — pull from radio leg when it exists,
   otherwise leave null so the placeholders render. */
function rl_int(?array $rl, string $k): ?int {
    if (!$rl) return null;
    return $rl[$k] !== null ? (int)$rl[$k] : null;
}
function rl_float(?array $rl, string $k): ?float {
    if (!$rl) return null;
    return $rl[$k] !== null ? (float)$rl[$k] : null;
}

$from_signal   = rl_int($radio_link, 'signal_dbm');
$from_noise    = rl_int($radio_link, 'noise_dbm');
$from_snr      = rl_int($radio_link, 'snr_db');
$from_chain0   = rl_int($radio_link, 'chain0_signal_dbm_local');
$from_chain1   = rl_int($radio_link, 'chain1_signal_dbm_local');
$from_mcs      = rl_int($radio_link, 'rx_mcs_index_local');
$from_rxrate   = rl_float($radio_link, 'rx_rate_mbps');
$from_txrate   = rl_float($radio_link, 'tx_rate_mbps');

$to_signal     = rl_int($radio_link, 'signal_dbm_remote');
$to_noise      = rl_int($radio_link, 'noise_dbm_remote');
$to_snr        = rl_int($radio_link, 'snr_db_remote');
$to_chain0     = rl_int($radio_link, 'chain0_signal_dbm_remote');
$to_chain1     = rl_int($radio_link, 'chain1_signal_dbm_remote');
$to_mcs        = rl_int($radio_link, 'rx_mcs_index_remote');

$max_mcs       = rl_int($radio_link, 'max_mcs_index') ?? 8;
$modulation    = (string)(($radio_link['modulation_label'] ?? '') ?: ($radio_link['modulation'] ?? ''));
$cap_local     = rl_float($radio_link, 'capacity_local_mbps');
$cap_remote    = rl_float($radio_link, 'capacity_remote_mbps');
$airtime_local = rl_float($radio_link, 'airtime_local_pct');
$airtime_remote= rl_float($radio_link, 'airtime_remote_pct');

/* Capacity dial shows the radio leg's per-side capacity if present,
   otherwise the operator-entered backbone capacity (Mbps). */
$banner_cap_local  = $cap_local  !== null ? $cap_local  : ($sl['capacity_mbps'] !== null ? (float)$sl['capacity_mbps'] : null);
$banner_cap_remote = $cap_remote !== null ? $cap_remote : ($sl['capacity_mbps'] !== null ? (float)$sl['capacity_mbps'] : null);
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
  .lv-banner-distance svg { width:14px; height:14px; }
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
    box-shadow:0 0 0 4px var(--accent-soft);
    flex-shrink:0;
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
  .lv-meter .lv-bar { flex:1; height:6px; border-radius:3px; background:rgba(255,255,255,0.05); overflow:hidden; }
  .lv-meter .lv-bar > span { display:block; height:100%; border-radius:3px; }
  .lv-meter .lv-mem  > span { background:#a25cf0; }
  .lv-meter .lv-cpu  > span { background:#05DAFD; }
  .lv-meter b  { font-weight:500; color:var(--text); font-variant-numeric:tabular-nums; min-width:42px; text-align:right; }

  .legend     { display:flex; gap:18px; flex-wrap:wrap; font-size:11.5px; color:var(--text-dim); padding:10px 0 0; }
  .legend-dot { display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:middle; }

  .device-list { display:flex; flex-direction:column; gap:6px; }
  .device-list a {
    display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 10px;
    background:var(--bg-elev); border:1px solid var(--border); border-radius:8px;
    font-size:13px; color:var(--text);
  }
  .device-list a:hover { border-color:var(--accent); color:var(--accent); }
  .device-list small { color:var(--text-muted); }
</style>

<div class="lv-banner">
  <div class="lv-side lv-endpoint">
    <span class="lv-icon" title="Local · <?= lv_h($from['name']) ?>">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 12a7 7 0 0 1 14 0"/><path d="M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>
      </svg>
    </span>
    <div>
      <div class="lv-label">Local · <?= lv_h(ucfirst((string)$from['type'])) ?></div>
      <h4><?= lv_h($from['name']) ?>
        <?php if ($from_dev): ?><?= lv_status_pill($from_dev['status'] ?? null) ?><?php endif; ?>
      </h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?php if ($from_dev): ?>
          <?= lv_h(ucfirst((string)($from_dev['vendor'] ?? ''))) ?> · <?= lv_h($from_dev['model'] ?? '') ?>
        <?php else: ?>
          <?= count($from_devices) ?> device<?= count($from_devices) === 1 ? '' : 's' ?>
        <?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        TX power <?= rl_int($radio_link, 'tx_power_dbm_local') !== null ? rl_int($radio_link, 'tx_power_dbm_local') . ' dBm' : '—' ?>
      </div>
    </div>
    <div class="lv-dial" title="Throughput / capacity (Mbps)">
      <small>Throughput<br>Capacity</small>
      <b><?= $banner_cap_local !== null ? number_format($banner_cap_local, 2) : '—' ?></b>
      <span class="lv-dial-unit">Mbps</span>
    </div>
  </div>

  <div class="lv-mid">
    <div class="lv-arrow">
      <span class="lv-arrow-line"></span>
      <span class="lv-banner-distance">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11V7a4 4 0 0 0-8 0v4"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
        <strong><?= lv_fmt_ft($dist_km) ?></strong>
      </span>
      <span class="lv-arrow-line"></span>
    </div>
    <div class="lv-airtime">
      <b>Type</b>
      <span class="lv-pill" style="background:<?= $type_clr ?>;color:#001218;"><?= lv_h($type_label) ?></span>
      <?php if ($freq_str !== ''): ?>&nbsp;·&nbsp; <b>Frequency</b> <?= lv_h($freq_str) ?><?php endif; ?>
    </div>
    <div class="lv-airtime">
      <b>Airtime</b>
      <?= $airtime_local  !== null ? number_format($airtime_local, 1)  . '%' : '—' ?>
      &nbsp;·&nbsp;
      <?= $airtime_remote !== null ? number_format($airtime_remote, 1) . '%' : '—' ?>
    </div>
    <div class="lv-airtime"><b>Distance</b> <?= number_format($dist_km, 2) ?> km · <?= lv_fmt_ft($dist_km) ?></div>
    <div class="lv-airtime"><b>Bearing</b> <?= number_format($bearing, 0) ?>° <?= lv_h(compass_label($bearing)) ?></div>
    <?php if ($sl['label'] !== ''): ?>
      <div class="lv-airtime"><b>Label</b> <?= lv_h($sl['label']) ?></div>
    <?php endif; ?>
    <?php if ($radio_link): ?>
      <div class="lv-airtime"><b>Health</b> <?= lv_health_pill($radio_link['health_score'] !== null ? (int)$radio_link['health_score'] : null) ?></div>
    <?php endif; ?>
  </div>

  <div class="lv-side lv-end lv-endpoint">
    <div class="lv-dial" title="Throughput / capacity (Mbps)">
      <small>Throughput<br>Capacity</small>
      <b><?= $banner_cap_remote !== null ? number_format($banner_cap_remote, 2) : '—' ?></b>
      <span class="lv-dial-unit">Mbps</span>
    </div>
    <div style="text-align:right;">
      <div class="lv-label">Remote · <?= lv_h(ucfirst((string)$to['type'])) ?></div>
      <h4><?= lv_h($to['name']) ?>
        <?php if ($to_dev): ?><?= lv_status_pill($to_dev['status'] ?? null) ?><?php endif; ?>
      </h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?php if ($to_dev): ?>
          <?= lv_h(ucfirst((string)($to_dev['vendor'] ?? ''))) ?> · <?= lv_h($to_dev['model'] ?? '') ?>
        <?php else: ?>
          <?= count($to_devices) ?> device<?= count($to_devices) === 1 ? '' : 's' ?>
        <?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        TX power <?= rl_int($radio_link, 'tx_power_dbm_remote') !== null ? rl_int($radio_link, 'tx_power_dbm_remote') . ' dBm' : '—' ?>
      </div>
    </div>
    <span class="lv-icon" title="Remote · <?= lv_h($to['name']) ?>">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <ellipse cx="12" cy="12" rx="9" ry="4"/><path d="M3 12c0 4 3 7 9 7s9-3 9-7"/><path d="M12 16v3"/>
      </svg>
    </span>
  </div>
</div>

<div class="lv-tabs">
  <a class="lv-tab" href="/admin/map.php?focus=site_link&amp;id=<?= (int)$sl['id'] ?>">Map</a>
  <a class="lv-tab <?= $tab === 'link'    ? 'active' : '' ?>" href="?id=<?= (int)$sl['id'] ?>&amp;tab=link">Link</a>
  <?php if (!$is_fibre && $freq_ghz !== null): ?>
    <a class="lv-tab <?= $tab === 'fresnel' ? 'active' : '' ?>" href="?id=<?= (int)$sl['id'] ?>&amp;tab=fresnel">Fresnel</a>
  <?php endif; ?>
  <a class="lv-tab" href="/admin/links.php?edit=<?= (int)$sl['id'] ?>#backbone-form">Edit</a>
  <?php if ($radio_link): ?>
    <a class="lv-tab" href="/admin/link-view.php?id=<?= (int)$radio_link['id'] ?>">Radio leg ↗</a>
  <?php endif; ?>
</div>

<?php if ($alerts): ?>
<div class="portal-card" style="border-left:3px solid var(--danger);">
  <h3 class="lv-label" style="color:var(--danger);">Active health alerts</h3>
  <?php foreach ($alerts as $a): ?>
    <div class="lv-row">
      <span><b><?= lv_h(str_replace('_', ' ', $a['kind'])) ?></b>
        <span class="lv-pill" style="background:<?= $a['severity'] === 'crit' ? 'var(--danger)' : '#e8a814' ?>;">
          <?= lv_h($a['severity']) ?>
        </span>
      </span>
      <span class="muted small"><?= lv_h($a['notes']) ?> · since <?= lv_h($a['opened_at']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'fresnel' && $fresnel): ?>
<div class="portal-card">
  <h2>Fresnel zone &amp; line-of-sight</h2>
  <p class="muted">Recommended: ≥60 % of the first Fresnel zone clear at the midpoint, plus an allowance for 4/3-Earth bulge.</p>
  <div class="lv-row"><span><b>Distance</b></span>
    <span><?= number_format($fresnel['dist_km'], 3) ?> km · <?= lv_fmt_ft($fresnel['dist_km']) ?></span></div>
  <div class="lv-row"><span><b>Frequency</b></span>
    <span><?= number_format($fresnel['freq_ghz'], 3) ?> GHz</span></div>
  <div class="lv-row"><span><b>1st Fresnel radius (midpoint)</b></span>
    <span><?= number_format($fresnel['r_m_full'], 2) ?> m</span></div>
  <div class="lv-row"><span><b>60 % clearance recommended</b></span>
    <span><?= number_format($fresnel['r_m_60'], 2) ?> m</span></div>
  <div class="lv-row"><span><b>Earth-bulge allowance (4/3 R)</b></span>
    <span><?= number_format($fresnel['bulge'], 2) ?> m</span></div>
  <div class="lv-row"><span><b>From / To height</b></span>
    <span>
      <?= $fresnel['a_h'] !== null ? number_format((float)$fresnel['a_h'], 1) . ' m' : '—' ?>
      / <?= $fresnel['b_h'] !== null ? number_format((float)$fresnel['b_h'], 1) . ' m' : '—' ?>
    </span></div>
  <?php if ($fresnel['needed_height'] !== null): ?>
    <div class="lv-row"><span><b>Min height needed</b></span>
      <span><?= number_format($fresnel['needed_height'], 2) ?> m</span></div>
    <div class="lv-row"><span><b>Clearance margin</b></span>
      <span style="color:<?= $fresnel['clearance'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600;">
        <?= ($fresnel['clearance'] >= 0 ? '+' : '') . number_format($fresnel['clearance'], 2) ?> m
        <?php if ($fresnel['clearance'] < 0): ?>
          <span class="lv-pill" style="background:var(--danger);margin-left:6px;">obstructed</span>
        <?php endif; ?>
      </span></div>
  <?php else: ?>
    <small class="muted">Add a height_m on both endpoint sites to compute clearance.</small>
  <?php endif; ?>
</div>

<?php else: /* Link tab */ ?>

<?php if (!$radio_link): ?>
<div class="portal-card" style="border-left:3px solid var(--accent);">
  <p class="muted" style="margin:0;">
    <?php if ($is_fibre): ?>
      <strong>Fibre link.</strong> Live RF and per-side signal stats don't apply to a fibre run — the panels below show the metadata you've configured plus device health for any monitored gear at either endpoint.
    <?php elseif (!$from_devices || !$to_devices): ?>
      <strong>No radios attached yet.</strong> Add devices at <a href="/admin/sites.php"><?= lv_h($from['name']) ?></a> and <a href="/admin/sites.php"><?= lv_h($to['name']) ?></a> in <a href="/admin/devices.php">/admin/devices.php</a>, then run <code>php bin/poll-wireless.php</code>. The radio dashboard will fill in automatically.
    <?php else: ?>
      <strong>No wireless leg detected yet.</strong> Devices exist at both sites but the polling worker hasn't seen a station-MAC pairing between them. Add credentials in <a href="/admin/devices.php">/admin/devices.php</a> and run <code>php bin/poll-wireless.php</code> to populate live RF / signal data.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

<div class="lv-grid">
  <?php
  $render_side = function (
      string $title,
      ?array $device,
      ?array $health,
      array  $rf,
      ?int   $signal,
      ?int   $noise,
      ?int   $snr,
      ?int   $chain0,
      ?int   $chain1,
      ?int   $rate_idx,
      string $modulation,
      ?float $rx_rate_mbps,
      ?float $capacity_mbps,
      string $side,
      ?int   $rf_centre,
      ?int   $rf_width,
      int    $max_mcs_idx,
      array  $samples
  ) {
      ?>
      <div class="portal-card">
        <div class="lv-grid-hdr">
          <h3 class="lv-label" style="font-size:11px;"><?= lv_h($title) ?></h3>
          <span class="lv-tag"><?= $signal !== null ? (int)$signal . ' dBm' : ($device ? lv_h($device['model'] ?? '—') : 'no radio') ?></span>
        </div>

        <h4 class="lv-label">RF environment <span style="color:var(--text-muted);">(last hour)</span></h4>
        <?= lv_rf_bars($rf, $rf_centre, $rf_width) ?>

        <div class="lv-mini-section" style="border-top:0;padding-top:10px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
            <div>
              <div class="lv-label">Signal</div>
              <?= lv_chain_label($signal, $chain0, $chain1) ?>
            </div>
            <div style="text-align:right;">
              <div class="lv-label">Noise floor</div>
              <strong style="font-size:18px;font-weight:400;color:var(--text);">
                <?= $noise !== null ? (int)$noise . ' dBm' : '—' ?>
              </strong>
            </div>
          </div>
        </div>

        <div class="lv-mini-section">
          <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
            <h4 style="margin:0;font-size:11px;text-transform:uppercase;color:var(--text-muted);letter-spacing:.07em;font-weight:600;">
              <?= ucfirst($side) ?> RX data rate
              <strong style="font-weight:500;color:var(--text);"><?= $rate_idx !== null ? $rate_idx . 'x' : '—' ?></strong>
              <?php if ($modulation !== ''): ?><span class="lv-tag"><?= lv_h($modulation) ?></span><?php endif; ?>
            </h4>
            <span class="lv-label">
              Expected rate
              <strong style="color:var(--accent);"><?= $max_mcs_idx ?>X</strong>
            </span>
          </div>
          <?= lv_rate_ladder($rate_idx, null, $max_mcs_idx, $modulation) ?>
        </div>

        <div class="lv-mini-section">
          <h4>Signal · Noise · Interference (24 h)</h4>
          <?= lv_signal_chart_svg($samples, $side) ?>
          <div class="legend">
            <span><span class="legend-dot" style="background:#4477ff;"></span>Average signal
              <span style="color:var(--text);"><?= $signal !== null ? (int)$signal . ' dBm' : '—' ?></span></span>
            <span><span class="legend-dot" style="background:#e8a814;"></span>Interference + noise
              <span style="color:var(--text);"><?= $noise !== null ? ((int)$noise + 4) . ' dBm' : '—' ?></span></span>
            <span><span class="legend-dot" style="background:#4ade80;"></span>Noise floor
              <span style="color:var(--text);"><?= $noise !== null ? (int)$noise . ' dBm' : '—' ?></span></span>
          </div>
        </div>

        <div class="lv-mini-section">
          <h4>CINR (dB)</h4>
          <?= lv_cinr_gauge($snr) ?>
        </div>
      </div>
      <?php
  };

  $render_side(
      'Local device · ' . $from['name'],
      $from_dev, $from_health, $rf_from,
      $from_signal, $from_noise, $from_snr,
      $from_chain0, $from_chain1, $from_mcs,
      $modulation,
      $from_rxrate, $cap_local, 'local',
      $rf_centre_mhz, $rf_width_mhz, $max_mcs, $samples
  );
  $render_side(
      'Remote device · ' . $to['name'],
      $to_dev, $to_health, $rf_to,
      $to_signal, $to_noise, $to_snr,
      $to_chain0, $to_chain1, $to_mcs,
      $modulation,
      $from_txrate, $cap_remote, 'remote',
      $rf_centre_mhz, $rf_width_mhz, $max_mcs, $samples
  );
  ?>
</div>

<?php
/* ---------- More-details / Wireless / Ethernet panels (per side) ---------- */
$detail_card = function (
    string $title,
    ?array $device,
    ?array $health,
    ?array $eth,
    ?array $radio_link,
    string $side,                           // 'local' | 'remote'
    string $tdd,
    ?float $dist_km,
    ?int   $noise,
    ?int   $snr
) use ($from, $to, $sl) {
    $is_remote = $side === 'remote';
    ?>
    <div class="portal-card">
      <div class="lv-grid-hdr">
        <h3 class="lv-label" style="font-size:11px;"><?= lv_h($title) ?></h3>
        <?php if ($device): ?>
          <a class="btn btn-ghost btn-sm" href="/admin/device-view.php?id=<?= (int)$device['id'] ?>">More details ↗</a>
        <?php endif; ?>
      </div>

      <div class="lv-row"><span><b>Site</b></span>
        <span><a href="/admin/site-view.php?id=<?= (int)($is_remote ? $to['id'] : $from['id']) ?>">
          <?= lv_h($is_remote ? $to['name'] : $from['name']) ?>
        </a></span>
      </div>
      <div class="lv-row"><span><b>Device model</b></span>
        <span><?= lv_h($device['model'] ?? '—') ?></span></div>
      <div class="lv-row"><span><b>Version</b></span>
        <span><?= lv_h($device['firmware'] ?? '—') ?></span></div>
      <div class="lv-row"><span><b>Network mode</b></span>
        <span><?= lv_h(ucfirst((string)($device['network_mode'] ?? 'unknown'))) ?></span></div>
      <div class="lv-row"><span><b>Date <?= $is_remote ? '' : '(synced)' ?></b></span>
        <span><?= lv_h(lv_fmt_dt($health['polled_at'] ?? null)) ?></span></div>
      <div class="lv-row"><span><b>UNMS connected</b></span>
        <span><?= lv_h(lv_fmt_dt($device['last_seen_at'] ?? ($health['polled_at'] ?? null))) ?></span></div>
      <div class="lv-row"><span><b>Uptime</b></span>
        <span><?= lv_fmt_uptime($health['uptime_seconds'] ?? null) ?></span></div>

      <?php
      $mem = $health['mem_pct'] ?? null;
      $cpu = $health['cpu_pct'] ?? null;
      ?>
      <div class="lv-row">
        <span><b>Memory</b></span>
        <span class="lv-meter" style="min-width:180px;">
          <span class="lv-bar lv-mem"><span style="width:<?= $mem !== null ? (int)$mem : 0 ?>%;"></span></span>
          <b><?= $mem !== null ? (int)$mem . ' %' : '—' ?></b>
        </span>
      </div>
      <div class="lv-row">
        <span><b>CPU</b></span>
        <span class="lv-meter" style="min-width:180px;">
          <span class="lv-bar lv-cpu"><span style="width:<?= $cpu !== null ? (int)$cpu : 0 ?>%;"></span></span>
          <b><?= $cpu !== null ? (int)$cpu . ' %' : '—' ?></b>
        </span>
      </div>

      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Wireless</h3>
      <div class="lv-row"><span><b>Wireless mode</b></span>
        <span><?= lv_h($radio_link['wireless_mode'] ?? '—') ?>
          <span class="lv-tag"><?= $is_remote ? 'Station PtP' : 'AP PtP' ?></span></span></div>
      <?php if (!$is_remote): ?>
        <div class="lv-row"><span><b>SSID</b></span>
          <span><?= lv_h($radio_link['ssid'] ?? '—') ?></span></div>
        <div class="lv-row"><span><b>Security</b></span>
          <span><?= lv_h(strtoupper((string)($radio_link['security'] ?? 'open'))) ?></span></div>
        <div class="lv-row"><span><b>TDD framing</b></span>
          <span><?= lv_h($tdd ?: '—') ?></span></div>
      <?php else: ?>
        <div class="lv-row"><span><b>Connection time</b></span>
          <span><?= lv_fmt_uptime(
            $radio_link && $radio_link['connection_time_seconds'] !== null ? (int)$radio_link['connection_time_seconds']
              : ($radio_link && $radio_link['uptime_seconds']     !== null ? (int)$radio_link['uptime_seconds'] : null)
          ) ?></span></div>
        <div class="lv-row"><span><b>Remote IP</b></span>
          <span><?= lv_h(($radio_link['remote_ip'] ?? '') ?: ($device['mgmt_ip'] ?? '—')) ?></span></div>
      <?php endif; ?>
      <div class="lv-row"><span><b>CINR</b></span>
        <span><?= $snr !== null ? '+' . (int)$snr . ' dB' : '—' ?></span></div>
      <div class="lv-row"><span><b>Distance</b></span>
        <span><?= $dist_km !== null
          ? number_format($dist_km, 2) . ' km · ' . lv_fmt_ft($dist_km)
          : '—' ?></span></div>
      <div class="lv-row"><span><b>Noise floor</b></span>
        <span><?= $noise !== null ? (int)$noise . ' dBm' : '—' ?></span></div>
      <div class="lv-row"><span><b>TX / RX bytes</b></span>
        <span><?= lv_fmt_bytes($radio_link['tx_bytes'] ?? null) ?> / <?= lv_fmt_bytes($radio_link['rx_bytes'] ?? null) ?></span></div>

      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Ethernet</h3>
      <?php if ($eth): ?>
        <div class="lv-row"><span><b>LAN0 / LAN1 speed</b></span>
          <span><?= $eth['link_speed_mbps'] !== null
              ? number_format((float)$eth['link_speed_mbps'], 0) . ' Mbps-' . lv_h(ucfirst((string)$eth['duplex']))
              : '—' ?> / —</span></div>
        <div class="lv-row"><span><b>Cable SNR</b></span>
          <span><?= $eth['cable_snr_db'] !== null
              ? '+' . number_format((float)$eth['cable_snr_db'], 0) . ' dB' : '—' ?> / —</span></div>
        <div class="lv-row"><span><b>Cable length</b></span>
          <span><?= $eth['cable_length_m'] !== null
              ? number_format((float)$eth['cable_length_m'] / 0.3048, 0) . ' ft · '
                . number_format((float)$eth['cable_length_m'], 0) . ' m'
              : '—' ?> / —</span></div>
      <?php else: ?>
        <small class="muted">No cable diagnostics yet.</small>
      <?php endif; ?>
    </div>
    <?php
};
?>

<div class="lv-grid" style="margin-top:18px;">
  <?php $detail_card(
      'More details — local',
      $from_dev, $from_health, $from_eth, $radio_link,
      'local', (string)$tdd, $dist_km, $from_noise, $from_snr
  ); ?>
  <?php $detail_card(
      'More details — remote',
      $to_dev, $to_health, $to_eth, $radio_link,
      'remote', (string)$tdd, $dist_km, $to_noise, $to_snr
  ); ?>
</div>

<!-- Site context: sectors + devices at each end -->
<div class="lv-grid" style="margin-top:18px;">
  <?php
  $endpoint_card = function (array $site, array $devices, array $sectors) {
      ?>
      <div class="portal-card">
        <div class="lv-grid-hdr">
          <h3 class="lv-label" style="font-size:11px;"><?= lv_h(ucfirst((string)$site['type'])) ?> · <?= lv_h($site['name']) ?></h3>
          <a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$site['id'] ?>">Open site ↗</a>
        </div>
        <div class="lv-row"><span><b>Type</b></span>     <span><?= lv_h(ucfirst((string)$site['type'])) ?></span></div>
        <div class="lv-row"><span><b>Latitude</b></span> <span><?= number_format((float)$site['lat'], 6) ?></span></div>
        <div class="lv-row"><span><b>Longitude</b></span><span><?= number_format((float)$site['lng'], 6) ?></span></div>
        <?php if ($site['height_m'] !== null): ?>
          <div class="lv-row"><span><b>Height</b></span>
            <span><?= number_format((float)$site['height_m'], 1) ?> m / <?= number_format((float)$site['height_m'] / 0.3048, 0) ?> ft</span></div>
        <?php endif; ?>
        <?php if (!empty($site['coverage_radius_m'])): ?>
          <div class="lv-row"><span><b>Coverage radius</b></span>
            <span><?= number_format((float)$site['coverage_radius_m'] / 1000.0, 2) ?> km</span></div>
        <?php endif; ?>
        <div class="lv-row"><span><b>Active</b></span>   <span><?= !empty($site['is_active']) ? 'Yes' : 'No' ?></span></div>

        <?php if ($sectors): ?>
          <div class="lv-mini-section">
            <h4>Sectors (<?= count($sectors) ?>)</h4>
            <div class="device-list">
              <?php foreach ($sectors as $s): ?>
                <a href="/admin/sector-edit.php?id=<?= (int)$s['id'] ?>">
                  <span><?= lv_h($s['name']) ?>
                    <?php if (!empty($s['frequency_mhz'])): ?>
                      <span class="lv-tag"><?= (int)$s['frequency_mhz'] ?> MHz</span>
                    <?php endif; ?>
                  </span>
                  <small><?= !empty($s['azimuth_deg']) ? (int)$s['azimuth_deg'] . '°' : '—' ?> · <?= lv_h($s['band'] ?? '') ?></small>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($devices): ?>
          <div class="lv-mini-section">
            <h4>Devices (<?= count($devices) ?>)</h4>
            <div class="device-list">
              <?php foreach ($devices as $d): ?>
                <a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>">
                  <span><?= lv_h($d['name']) ?>
                    <small> · <?= lv_h(ucfirst((string)$d['role'])) ?>
                      <?php if (!empty($d['model'])): ?> · <?= lv_h($d['model']) ?><?php endif; ?>
                    </small>
                  </span>
                  <span><?= lv_status_pill($d['status'] ?? null) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <small class="muted">No devices registered at this site.</small>
        <?php endif; ?>
      </div>
      <?php
  };
  $endpoint_card($from, $from_devices, $from_sectors);
  $endpoint_card($to,   $to_devices,   $to_sectors);
  ?>
</div>

<!-- Backbone link metadata -->
<div class="portal-card" style="margin-top:18px;">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">Backbone link metadata</h3>
    <a class="btn btn-ghost btn-sm" href="/admin/links.php?edit=<?= (int)$sl['id'] ?>#backbone-form">Edit</a>
  </div>
  <div class="lv-row"><span><b>Type</b></span>
    <span><span class="lv-pill" style="background:<?= $type_clr ?>;color:#001218;"><?= lv_h($type_label) ?></span></span></div>
  <div class="lv-row"><span><b>Label</b></span>
    <span><?= lv_h($sl['label']) ?: '—' ?></span></div>
  <div class="lv-row"><span><b>Capacity</b></span>
    <span><?= $sl['capacity_mbps'] !== null ? number_format((float)$sl['capacity_mbps'], 0) . ' Mbps' : '—' ?></span></div>
  <div class="lv-row"><span><b>Frequency</b></span>
    <span><?= lv_h($freq_str ?: '—') ?>
      <?php if ($freq_ghz !== null): ?> <span class="muted">(<?= number_format($freq_ghz, 3) ?> GHz)</span><?php endif; ?></span></div>
  <div class="lv-row"><span><b>Distance</b></span>
    <span><?= number_format($dist_km, 3) ?> km · <?= lv_fmt_ft($dist_km) ?></span></div>
  <div class="lv-row"><span><b>Bearing</b></span>
    <span><?= number_format($bearing, 1) ?>° <?= lv_h(compass_label($bearing)) ?></span></div>
  <div class="lv-row"><span><b>Map line colour</b></span>
    <span>
      <?php if (!empty($sl['color'])): ?>
        <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= lv_h($sl['color']) ?>;border:1px solid var(--border-strong);vertical-align:middle;"></span>
        <code style="margin-left:6px;"><?= lv_h($sl['color']) ?></code>
      <?php else: ?>—<?php endif; ?>
    </span></div>
  <?php if ($radio_link): ?>
    <div class="lv-row"><span><b>Radio leg</b></span>
      <span><a href="/admin/link-view.php?id=<?= (int)$radio_link['id'] ?>">
        Open radio dashboard ↗
      </a></span></div>
    <div class="lv-row"><span><b>Last sample</b></span>
      <span><?= lv_h(lv_fmt_dt($radio_link['last_evaluated_at'] ?? null)) ?></span></div>
  <?php endif; ?>
  <div class="lv-row"><span><b>Created</b></span>
    <span><?= lv_h(lv_fmt_dt($sl['created_at'] ?? null)) ?></span></div>
</div>

<?php if (!empty($sl['notes'])): ?>
<div class="portal-card" style="margin-top:18px;">
  <h3 class="lv-label" style="font-size:11px;">Notes</h3>
  <p style="white-space:pre-wrap;color:var(--text-dim);"><?= lv_h($sl['notes']) ?></p>
</div>
<?php endif; ?>

<?php endif; /* tab */ ?>

<div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="/admin/links.php#backbone">← All backbone links</a>
    <?php if (in_array($sl['type'] ?? 'ptp', ['ptp','ptmp','backhaul'], true)): ?>
      <a class="btn btn-primary btn-sm" href="/admin/site-link-align.php?id=<?= (int)$sl['id'] ?>" title="Two-ended live signal meter for aiming this PTP link">Align link ↗</a>
    <?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$from['id'] ?>">Open <?= lv_h($from['name']) ?></a>
    <a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$to['id']   ?>">Open <?= lv_h($to['name']) ?></a>
    <a class="btn btn-ghost btn-sm" href="/admin/map.php?focus=site_link&amp;id=<?= (int)$sl['id'] ?>">Show on map</a>
    <?php if ($radio_link): ?>
      <a class="btn btn-primary btn-sm" href="/admin/link-view.php?id=<?= (int)$radio_link['id'] ?>">Radio dashboard ↗</a>
    <?php endif; ?>
  </div>
  <small class="muted">created <?= lv_h(lv_fmt_dt($sl['created_at'] ?? null)) ?></small>
</div>
