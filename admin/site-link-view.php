<?php
/**
 * Backbone link dashboard — same UISP-style layout as
 * /admin/link-view.php but for site_links rows (site-to-site PTP /
 * fibre / backhaul lines, drawn on the network map).
 *
 * One page per site_links row. Server-rendered, no JS chart library.
 *
 *   Top banner   from-site icon + name + type + height + GPS, distance
 *                pill in the middle, to-site card on the right; capacity
 *                and frequency dials flanking the centre.
 *   Tabs         Map · Link · Fresnel (if a frequency is set)
 *   Endpoint cards
 *                For each end of the link: site type, address-ish
 *                summary (lat/lng + height), parent tower, sectors and
 *                devices attached at that site with health pills.
 *   Wireless leg If a wireless_links row connects the same two sites'
 *                devices, link straight to the radio dashboard.
 *   Notes        Free-text notes from the row.
 *   More details Type, label, capacity, frequency, distance, line
 *                colour, created/updated timestamps.
 *
 * Data sources:
 *   site_links              the row itself
 *   sites                   from / to endpoint metadata
 *   sectors                 sectors at each tower endpoint
 *   devices                 devices at each endpoint (for status pills)
 *   device_health           latest poll for each endpoint device
 *   wireless_links          if a radio leg connects the same endpoints
 */
$page_title = 'Backbone link';
$active_key = 'links';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';

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

/* Try to parse the frequency string ("5.8 GHz", "5800 MHz", "fibre",
   "6GHz") into a number for the Fresnel calc. Returns GHz or null. */
$freq_ghz = null;
$freq_str = (string)($sl['frequency'] ?? '');
if ($freq_str !== '') {
    if (preg_match('/(\d+(?:\.\d+)?)\s*ghz/i', $freq_str, $m)) {
        $freq_ghz = (float)$m[1];
    } elseif (preg_match('/(\d+(?:\.\d+)?)\s*mhz/i', $freq_str, $m)) {
        $freq_ghz = (float)$m[1] / 1000.0;
    } elseif (preg_match('/^\s*(\d+(?:\.\d+)?)\s*$/', $freq_str, $m) && (float)$m[1] >= 100) {
        // bare number ≥100 = MHz
        $freq_ghz = (float)$m[1] / 1000.0;
    }
}

$is_fibre = ($sl['type'] === 'fiber');
$fresnel  = null;
if (!$is_fibre && $freq_ghz !== null && $dist_km > 0) {
    $r_m_full = 8.657 * sqrt($dist_km / $freq_ghz);
    $r_m_60   = $r_m_full * 0.6;
    $bulge    = ($dist_km * $dist_km) / (8 * 1.33);
    $a_h = $from['height_m'] ?? null;
    $b_h = $to['height_m']   ?? null;
    $needed_height = ($a_h !== null && $b_h !== null) ? ($r_m_60 + $bulge) : null;
    $clearance = ($a_h !== null && $b_h !== null)
        ? min((float)$a_h, (float)$b_h) - $needed_height
        : null;
    $fresnel = [
        'distance_km'   => $dist_km,
        'frequency_ghz' => $freq_ghz,
        'r_full_m'      => $r_m_full,
        'r_60_m'        => $r_m_60,
        'earth_bulge_m' => $bulge,
        'from_height_m' => $a_h,
        'to_height_m'   => $b_h,
        'needed_m'      => $needed_height,
        'clearance_m'   => $clearance,
    ];
}

/* Initial bearing (compass heading) from -> to. */
function bearing_deg(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dl   = deg2rad($lng2 - $lng1);
    $y = sin($dl) * cos($phi2);
    $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dl);
    $b = rad2deg(atan2($y, $x));
    return fmod($b + 360.0, 360.0);
}
$bearing = bearing_deg(
    (float)$from['lat'], (float)$from['lng'],
    (float)$to['lat'],   (float)$to['lng']
);
function compass_label(float $deg): string {
    $names = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    $idx = (int)round($deg / 22.5) % 16;
    return $names[$idx];
}

$from_devices = devices_all(['site_id' => (int)$from['id']]);
$to_devices   = devices_all(['site_id' => (int)$to['id']]);
$from_sectors = sectors_for_tower((int)$from['id']);
$to_sectors   = sectors_for_tower((int)$to['id']);

/* Latest health for each endpoint device, keyed by device id. */
$device_ids = array_unique(array_merge(
    array_column($from_devices, 'id'),
    array_column($to_devices, 'id')
));
$health_by_dev = [];
foreach ($device_ids as $did) {
    $rows = device_recent_health((int)$did, 1);
    if ($rows) $health_by_dev[(int)$did] = $rows[0];
}

/* Look for a wireless link whose AP & CPE devices live at our two
   endpoint sites — if we find one, surface it as a "Wireless leg" card
   that drops the operator into the radio-level dashboard. */
$radio_leg = null;
if ($from_devices && $to_devices) {
    $from_ids = array_map('intval', array_column($from_devices, 'id'));
    $to_ids   = array_map('intval', array_column($to_devices, 'id'));
    $in_a = implode(',', array_map('intval', $from_ids));
    $in_b = implode(',', array_map('intval', $to_ids));
    if ($in_a !== '' && $in_b !== '') {
        $stmt = pdo()->query(
            "SELECT wl.id, wl.signal_dbm, wl.snr_db, wl.tx_rate_mbps, wl.rx_rate_mbps,
                    wl.health_score, wl.frequency_mhz, wl.channel_width_mhz,
                    ap.name AS ap_name, cpe.name AS cpe_name
               FROM wireless_links wl
               JOIN devices ap        ON ap.id  = wl.ap_device_id
               LEFT JOIN devices cpe  ON cpe.id = wl.cpe_device_id
              WHERE (ap.id IN ($in_a) AND cpe.id IN ($in_b))
                 OR (ap.id IN ($in_b) AND cpe.id IN ($in_a))
              ORDER BY wl.last_evaluated_at DESC LIMIT 1"
        );
        $radio_leg = $stmt ? ($stmt->fetch() ?: null) : null;
    }
}

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fmt_ft = function (?float $km): string {
    if ($km === null) return '—';
    $ft = $km * 1000.0 / 0.3048;
    return $ft >= 5280
        ? number_format($ft / 5280.0, 2) . ' mi'
        : number_format($ft, 2) . ' ft';
};
$fmt_dt = fn ($dt) => $dt ? date('Y-m-d H:i:s', strtotime((string)$dt)) : '—';

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

$status_pill = function (?string $status): string {
    $status = $status ?: 'unknown';
    $bg = match ($status) {
        'online'  => '#4ade80',
        'offline' => '#ff5470',
        default   => '#6b7480',
    };
    return '<span class="lv-pill" style="background:' . $bg . ';color:#001218;">' . $status . '</span>';
};
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
    border:1px solid var(--border-strong);
    font-variant-numeric:tabular-nums;
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
    <span class="lv-icon" title="From site">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2v20"/><path d="M5 7l7-5 7 5"/><path d="M5 17l7 5 7-5"/>
      </svg>
    </span>
    <div>
      <div class="lv-label">From · <?= $h(ucfirst((string)$from['type'])) ?></div>
      <h4><?= $h($from['name']) ?></h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?= $h(number_format((float)$from['lat'], 5)) ?>, <?= $h(number_format((float)$from['lng'], 5)) ?>
        <?php if ($from['height_m'] !== null): ?> · <?= number_format((float)$from['height_m'], 1) ?> m<?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        <?= count($from_devices) ?> device<?= count($from_devices) === 1 ? '' : 's' ?>
        <?php if ($from_sectors): ?> · <?= count($from_sectors) ?> sector<?= count($from_sectors) === 1 ? '' : 's' ?><?php endif; ?>
      </div>
    </div>
    <div class="lv-dial" title="Capacity (Mbps)">
      <small>Capacity</small>
      <b><?= $sl['capacity_mbps'] !== null ? number_format((float)$sl['capacity_mbps'], 0) : '—' ?></b>
      <span class="lv-dial-unit">Mbps</span>
    </div>
  </div>

  <div class="lv-mid">
    <div class="lv-arrow">
      <span class="lv-arrow-line"></span>
      <span class="lv-banner-distance">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11V7a4 4 0 0 0-8 0v4"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
        <strong><?= $fmt_ft($dist_km) ?></strong>
      </span>
      <span class="lv-arrow-line"></span>
    </div>
    <div class="lv-airtime">
      <b>Type</b>
      <span class="lv-pill" style="background:<?= $type_clr ?>;color:#001218;"><?= $h($type_label) ?></span>
      <?php if ($freq_str !== ''): ?>&nbsp;·&nbsp; <b>Frequency</b> <?= $h($freq_str) ?><?php endif; ?>
    </div>
    <div class="lv-airtime"><b>Distance</b> <?= number_format($dist_km, 2) ?> km · <?= $fmt_ft($dist_km) ?></div>
    <div class="lv-airtime"><b>Bearing</b> <?= number_format($bearing, 0) ?>° <?= $h(compass_label($bearing)) ?></div>
    <?php if ($sl['label'] !== ''): ?>
      <div class="lv-airtime"><b>Label</b> <?= $h($sl['label']) ?></div>
    <?php endif; ?>
  </div>

  <div class="lv-side lv-end lv-endpoint">
    <div class="lv-dial" title="Frequency">
      <small>Frequency</small>
      <b><?= $freq_ghz !== null ? number_format($freq_ghz, 2) : '—' ?></b>
      <span class="lv-dial-unit"><?= $freq_ghz !== null ? 'GHz' : ($freq_str ?: '—') ?></span>
    </div>
    <div style="text-align:right;">
      <div class="lv-label">To · <?= $h(ucfirst((string)$to['type'])) ?></div>
      <h4><?= $h($to['name']) ?></h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?= $h(number_format((float)$to['lat'], 5)) ?>, <?= $h(number_format((float)$to['lng'], 5)) ?>
        <?php if ($to['height_m'] !== null): ?> · <?= number_format((float)$to['height_m'], 1) ?> m<?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        <?= count($to_devices) ?> device<?= count($to_devices) === 1 ? '' : 's' ?>
        <?php if ($to_sectors): ?> · <?= count($to_sectors) ?> sector<?= count($to_sectors) === 1 ? '' : 's' ?><?php endif; ?>
      </div>
    </div>
    <span class="lv-icon" title="To site">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22V2"/><path d="M19 17l-7 5-7-5"/><path d="M19 7l-7-5-7 5"/>
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
</div>

<?php if ($tab === 'fresnel' && $fresnel): ?>
<div class="portal-card">
  <h2>Fresnel zone &amp; line-of-sight</h2>
  <p class="muted">Recommended: ≥60 % of the first Fresnel zone clear at the midpoint, plus an allowance for 4/3-Earth bulge.</p>
  <div class="lv-row"><span><b>Distance</b></span>
    <span><?= number_format($fresnel['distance_km'], 3) ?> km · <?= $fmt_ft($fresnel['distance_km']) ?></span></div>
  <div class="lv-row"><span><b>Frequency</b></span>
    <span><?= number_format($fresnel['frequency_ghz'], 3) ?> GHz</span></div>
  <div class="lv-row"><span><b>1st Fresnel radius (midpoint)</b></span>
    <span><?= number_format($fresnel['r_full_m'], 2) ?> m</span></div>
  <div class="lv-row"><span><b>60 % clearance recommended</b></span>
    <span><?= number_format($fresnel['r_60_m'], 2) ?> m</span></div>
  <div class="lv-row"><span><b>Earth-bulge allowance (4/3 R)</b></span>
    <span><?= number_format($fresnel['earth_bulge_m'], 2) ?> m</span></div>
  <div class="lv-row"><span><b>From / To height</b></span>
    <span>
      <?= $fresnel['from_height_m'] !== null ? number_format($fresnel['from_height_m'], 1) . ' m' : '—' ?>
      / <?= $fresnel['to_height_m']   !== null ? number_format($fresnel['to_height_m'],   1) . ' m' : '—' ?>
    </span></div>
  <?php if ($fresnel['needed_m'] !== null): ?>
    <div class="lv-row"><span><b>Min height needed</b></span>
      <span><?= number_format($fresnel['needed_m'], 2) ?> m</span></div>
    <div class="lv-row"><span><b>Clearance margin</b></span>
      <span style="color:<?= $fresnel['clearance_m'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600;">
        <?= ($fresnel['clearance_m'] >= 0 ? '+' : '') . number_format($fresnel['clearance_m'], 2) ?> m
        <?php if ($fresnel['clearance_m'] < 0): ?>
          <span class="lv-pill" style="background:var(--danger);margin-left:6px;">obstructed</span>
        <?php endif; ?>
      </span></div>
  <?php else: ?>
    <small class="muted">Add a height_m on both endpoint sites to compute clearance.</small>
  <?php endif; ?>
</div>
<?php else: /* Link tab (default) */ ?>

<?php if ($radio_leg): ?>
<div class="portal-card" style="border-left:3px solid var(--accent);">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">Wireless leg detected</h3>
    <a class="btn btn-primary btn-sm" href="/admin/link-view.php?id=<?= (int)$radio_leg['id'] ?>">Open radio dashboard ↗</a>
  </div>
  <p class="muted" style="margin:0 0 8px;">A wireless link record connects devices at <?= $h($from['name']) ?> and <?= $h($to['name']) ?>. Open it for the full UISP-style RF dashboard (signal, SNR, MCS, RF environment, CINR, 24h history).</p>
  <div class="lv-row"><span><b><?= $h($radio_leg['ap_name']) ?> ↔ <?= $h($radio_leg['cpe_name'] ?? '—') ?></b></span>
    <span>
      <?php if ($radio_leg['signal_dbm']    !== null): ?><?= (int)$radio_leg['signal_dbm']  ?> dBm<?php endif; ?>
      <?php if ($radio_leg['snr_db']        !== null): ?> · <?= (int)$radio_leg['snr_db']  ?> dB SNR<?php endif; ?>
      <?php if ($radio_leg['frequency_mhz'] !== null): ?> · <?= (int)$radio_leg['frequency_mhz'] ?> MHz<?php endif; ?>
    </span>
  </div>
</div>
<?php endif; ?>

<div class="lv-grid">
  <?php
  $endpoint_card = function (array $site, array $devices, array $sectors) use ($h, $status_pill, $health_by_dev) {
      ?>
      <div class="portal-card">
        <div class="lv-grid-hdr">
          <h3 class="lv-label" style="font-size:11px;"><?= $h(ucfirst((string)$site['type'])) ?> · <?= $h($site['name']) ?></h3>
          <a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$site['id'] ?>">Open site ↗</a>
        </div>
        <div class="lv-row"><span><b>Type</b></span>          <span><?= $h(ucfirst((string)$site['type'])) ?></span></div>
        <div class="lv-row"><span><b>Latitude</b></span>      <span><?= number_format((float)$site['lat'], 6) ?></span></div>
        <div class="lv-row"><span><b>Longitude</b></span>     <span><?= number_format((float)$site['lng'], 6) ?></span></div>
        <?php if ($site['height_m'] !== null): ?>
          <div class="lv-row"><span><b>Height</b></span>
            <span><?= number_format((float)$site['height_m'], 1) ?> m / <?= number_format((float)$site['height_m'] / 0.3048, 0) ?> ft</span></div>
        <?php endif; ?>
        <?php if (!empty($site['coverage_radius_m'])): ?>
          <div class="lv-row"><span><b>Coverage radius</b></span>
            <span><?= number_format((float)$site['coverage_radius_m'] / 1000.0, 2) ?> km</span></div>
        <?php endif; ?>
        <div class="lv-row"><span><b>Active</b></span>
          <span><?= !empty($site['is_active']) ? 'Yes' : 'No' ?></span></div>

        <?php if ($sectors): ?>
          <div class="lv-mini-section">
            <h4>Sectors (<?= count($sectors) ?>)</h4>
            <div class="device-list">
              <?php foreach ($sectors as $s): ?>
                <a href="/admin/sector-edit.php?id=<?= (int)$s['id'] ?>">
                  <span><?= $h($s['name']) ?>
                    <?php if (!empty($s['frequency_mhz'])): ?>
                      <span class="lv-tag"><?= (int)$s['frequency_mhz'] ?> MHz</span>
                    <?php endif; ?>
                  </span>
                  <small><?= !empty($s['azimuth_deg']) ? (int)$s['azimuth_deg'] . '°' : '—' ?> · <?= $h($s['band'] ?? '') ?></small>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($devices): ?>
          <div class="lv-mini-section">
            <h4>Devices (<?= count($devices) ?>)</h4>
            <div class="device-list">
              <?php foreach ($devices as $d):
                $dh = $health_by_dev[(int)$d['id']] ?? null;
              ?>
                <a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>">
                  <span><?= $h($d['name']) ?>
                    <small> · <?= $h(ucfirst((string)$d['role'])) ?>
                      <?php if (!empty($d['model'])): ?> · <?= $h($d['model']) ?><?php endif; ?>
                    </small>
                  </span>
                  <span><?= $status_pill($d['status'] ?? null) ?></span>
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

<div class="portal-card" style="margin-top:18px;">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">More details — backbone link</h3>
    <a class="btn btn-ghost btn-sm" href="/admin/links.php?edit=<?= (int)$sl['id'] ?>#backbone-form">Edit</a>
  </div>
  <div class="lv-row"><span><b>Type</b></span>
    <span><span class="lv-pill" style="background:<?= $type_clr ?>;color:#001218;"><?= $h($type_label) ?></span></span></div>
  <div class="lv-row"><span><b>Label</b></span>      <span><?= $h($sl['label']) ?: '—' ?></span></div>
  <div class="lv-row"><span><b>Capacity</b></span>
    <span><?= $sl['capacity_mbps'] !== null ? number_format((float)$sl['capacity_mbps'], 0) . ' Mbps' : '—' ?></span></div>
  <div class="lv-row"><span><b>Frequency</b></span>
    <span><?= $h($freq_str ?: '—') ?><?php if ($freq_ghz !== null): ?> <span class="muted">(<?= number_format($freq_ghz, 3) ?> GHz)</span><?php endif; ?></span></div>
  <div class="lv-row"><span><b>Distance</b></span>
    <span><?= number_format($dist_km, 3) ?> km · <?= $fmt_ft($dist_km) ?></span></div>
  <div class="lv-row"><span><b>Bearing</b></span>
    <span><?= number_format($bearing, 1) ?>° <?= $h(compass_label($bearing)) ?></span></div>
  <div class="lv-row"><span><b>Map line colour</b></span>
    <span>
      <?php if (!empty($sl['color'])): ?>
        <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= $h($sl['color']) ?>;border:1px solid var(--border-strong);vertical-align:middle;"></span>
        <code style="margin-left:6px;"><?= $h($sl['color']) ?></code>
      <?php else: ?>—<?php endif; ?>
    </span></div>
  <div class="lv-row"><span><b>Created</b></span>     <span><?= $h($fmt_dt($sl['created_at'] ?? null)) ?></span></div>
</div>

<?php if (!empty($sl['notes'])): ?>
<div class="portal-card" style="margin-top:18px;">
  <h3 class="lv-label" style="font-size:11px;">Notes</h3>
  <p style="white-space:pre-wrap;color:var(--text-dim);"><?= $h($sl['notes']) ?></p>
</div>
<?php endif; ?>

<?php endif; /* tab */ ?>

<div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="/admin/links.php#backbone">← All backbone links</a>
    <a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$from['id'] ?>">Open <?= $h($from['name']) ?></a>
    <a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$to['id']   ?>">Open <?= $h($to['name']) ?></a>
    <a class="btn btn-ghost btn-sm" href="/admin/map.php?focus=site_link&amp;id=<?= (int)$sl['id'] ?>">Show on map</a>
  </div>
  <small class="muted">created <?= $h($fmt_dt($sl['created_at'] ?? null)) ?></small>
</div>
