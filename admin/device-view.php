<?php
/**
 * Device dashboard — UISP-style "Device" details. Mirrors the layout of
 * /admin/link-view.php / /admin/sector-view.php / /admin/site-link-view.php
 * so the operator's eye doesn't have to retrain between pages.
 *
 *   Banner       Device icon + name + status pill + vendor/model + role,
 *                uptime dial, RTT dial, site / customer / mgmt-IP card.
 *   Tabs         Map · Device · Health · Edit
 *   Cards        RF environment heat-bar (last 60 min, accent on the
 *                operating channel if known); 24h RTT sparkline with
 *                offline cycles in red; 24h CPU and memory sparklines
 *                + current bar meters; Ethernet diagnostics card
 *                (LAN speed/duplex, cable SNR, cable length, TDR pairs);
 *                More-details panel (model, firmware + EOL warning,
 *                serial, MAC, mgmt IP/port, antenna gain, network mode,
 *                last seen, created).
 *   Wireless leg If this is an AP or CPE, list every wireless_links
 *                row that uses this device as endpoint, with signal /
 *                SNR / health / customer per row.
 *   Customer     If the device is bound to a customer, link to their
 *                client dashboard.
 *   Recent       Last 100 health samples for quick eyeballing.
 *
 * Data sources:
 *   devices                 the row itself.
 *   sites                   parent site metadata.
 *   users                   bound customer (if any).
 *   device_health           24h health samples (CPU / memory / RTT / status).
 *   ethernet_health         latest LAN speed / cable SNR / cable length.
 *   rf_environment_samples  last 60 min, per-frequency RSSI bars.
 *   wireless_links          links where this device is AP or CPE.
 *   firmware_eol            EOL / EOS lookup by vendor + firmware match.
 */
$page_title = 'Device';
$active_key = 'devices';
$auto_refresh_seconds = 60; // soft-reload every 60s so sparklines stay current
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/poll_status.php';
require_once __DIR__ . '/_link-charts.php';

$id = (int)($_GET['id'] ?? 0);
$d  = $id ? device_find($id) : null;
if (!$d) {
    flash('error', 'Device not found.');
    header('Location: /admin/devices.php');
    exit;
}

$site = $d['site_id'] ? site_find((int)$d['site_id']) : null;

$customer = null;
if (!empty($d['customer_id'])) {
    $stmt = pdo()->prepare("SELECT id, account_no, name, surname, status FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$d['customer_id']]);
    $customer = $stmt->fetch() ?: null;
}

/* Last 24 h of samples (oldest-first for the sparklines). */
$stmt = pdo()->prepare(
    "SELECT polled_at, status, rtt_ms, cpu_pct, mem_pct, uptime_seconds
       FROM device_health
      WHERE device_id = ? AND polled_at >= (NOW() - INTERVAL 24 HOUR)
      ORDER BY polled_at ASC"
);
$stmt->execute([$id]);
$samples_24h = $stmt->fetchAll();

$total_24h  = count($samples_24h);
$online_24h = 0;
$rtts = []; $cpus = []; $mems = [];
foreach ($samples_24h as $s) {
    if ($s['status'] === 'online') $online_24h++;
    if ($s['rtt_ms']  !== null)  $rtts[] = (float)$s['rtt_ms'];
    if ($s['cpu_pct'] !== null)  $cpus[] = (float)$s['cpu_pct'];
    if ($s['mem_pct'] !== null)  $mems[] = (float)$s['mem_pct'];
}
$uptime_pct = $total_24h > 0 ? round(($online_24h / $total_24h) * 100, 1) : null;
$avg_rtt    = $rtts ? round(array_sum($rtts) / count($rtts), 2) : null;
$max_rtt    = $rtts ? max($rtts) : null;
$min_rtt    = $rtts ? min($rtts) : null;

/* Latest health for the banner / current-state bars. */
$latest_health = device_recent_health($id, 1)[0] ?? null;
$cur_cpu = $latest_health['cpu_pct'] ?? null;
$cur_mem = $latest_health['mem_pct'] ?? null;
$cur_uptime_s = $latest_health['uptime_seconds'] ?? null;

$eth = ethernet_health_latest($id);
$rf  = rf_environment_recent($id, 60);

/* Wireless links where this device is AP or CPE. */
$wl_stmt = pdo()->prepare(
    "SELECT wl.*, ap.name AS ap_name, ap.model AS ap_model,
            cpe.name AS cpe_name, cpe.model AS cpe_model,
            u.name AS customer_name, u.surname AS customer_surname
       FROM wireless_links wl
       JOIN devices ap        ON ap.id  = wl.ap_device_id
       LEFT JOIN devices cpe  ON cpe.id = wl.cpe_device_id
       LEFT JOIN users u      ON u.id   = wl.customer_id
      WHERE wl.ap_device_id = ? OR wl.cpe_device_id = ?
      ORDER BY wl.last_evaluated_at DESC"
);
$wl_stmt->execute([$id, $id]);
$wireless_links = $wl_stmt->fetchAll();

/* Firmware EOL warning. firmware_eol uses LIKE patterns on
   vendor + model_match + version_match. We match by vendor + firmware
   field and surface the row's severity / EOL date. */
$eol_warning = null;
if (!empty($d['firmware'])) {
    $stmt = pdo()->prepare(
        "SELECT * FROM firmware_eol
          WHERE vendor = ?
            AND ? LIKE model_match
            AND ? LIKE version_match
          ORDER BY (severity = 'critical') DESC, eol_date ASC LIMIT 1"
    );
    $stmt->execute([(string)$d['vendor'], (string)($d['model'] ?: '%'), (string)$d['firmware']]);
    $eol_warning = $stmt->fetch() ?: null;
}

/* Recent table — newest first. */
$recent_table = device_recent_health($id, 100);

/* Sector this device drives, if any (when role=ap). */
$sector = null;
if (($d['role'] ?? '') === 'ap') {
    $stmt = pdo()->prepare("SELECT * FROM sectors WHERE ap_device_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $sector = $stmt->fetch() ?: null;
}

$tab = (string)($_GET['tab'] ?? 'device');

$last_seen_age = '—';
if (!empty($d['last_seen_at'])) {
    $age = max(0, time() - strtotime((string)$d['last_seen_at']));
    if      ($age < 60)    $last_seen_age = $age . 's ago';
    elseif  ($age < 3600)  $last_seen_age = floor($age / 60)   . 'm ago';
    elseif  ($age < 86400) $last_seen_age = floor($age / 3600) . 'h ago';
    else                   $last_seen_age = floor($age / 86400) . 'd ago';
}

$device_freshness = poll_classify(poll_device_latest_at($id));
$is_pollable = !empty($d['mgmt_ip']) && in_array($d['vendor'], ['ubiquiti','mikrotik','cambium','mimosa'], true);

$role_icon_path = match($d['role'] ?? 'other') {
    'ap'       => '<path d="M5 12a7 7 0 0 1 14 0"/><path d="M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>',
    'cpe'      => '<ellipse cx="12" cy="12" rx="9" ry="4"/><path d="M3 12c0 4 3 7 9 7s9-3 9-7"/><path d="M12 16v3"/>',
    'router'   => '<rect x="3" y="9" width="18" height="10" rx="2"/><line x1="6" y1="13" x2="6" y2="13"/><line x1="10" y1="13" x2="10" y2="13"/><line x1="14" y1="13" x2="14" y2="13"/>',
    'switch'   => '<rect x="3" y="9" width="18" height="10" rx="2"/><line x1="7" y1="6" x2="7" y2="9"/><line x1="12" y1="6" x2="12" y2="9"/><line x1="17" y1="6" x2="17" y2="9"/>',
    'backhaul' => '<path d="M4 12h16"/><circle cx="6" cy="12" r="2"/><circle cx="18" cy="12" r="2"/>',
    default    => '<rect x="4" y="4" width="16" height="16" rx="2"/>',
};

/* SVG sparkline for CPU/memory/RTT. */
$mini_sparkline = function (array $points, string $colour, ?float $cap = null, int $w = 320, int $hh = 50): string {
    if (!$points) return '<small class="muted">No samples yet.</small>';
    $vals = $points;
    $max  = $cap ?? max(1.0, max($vals));
    $n    = count($vals);
    $sx = fn ($i) => $n > 1 ? ($i / ($n - 1)) * ($w - 2) + 1 : $w / 2;
    $sy = fn ($v) => 2 + (1 - min($v, $max) / $max) * ($hh - 4);
    $d = '';
    foreach ($vals as $i => $v) {
        $d .= ($i === 0 ? 'M' : 'L') . round($sx($i), 1) . ',' . round($sy((float)$v), 1) . ' ';
    }
    /* Filled area under the line for visual weight. */
    $area_d = $d . 'L' . round($sx($n - 1), 1) . ',' . round($sy(0), 1)
            . ' L' . round($sx(0), 1) . ',' . round($sy(0), 1) . ' Z';
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;border-radius:6px;background:rgba(255,255,255,0.02);">'
         . '<path d="' . $area_d . '" fill="' . $colour . '" fill-opacity="0.10"/>'
         . '<path d="' . $d . '" fill="none" stroke="' . $colour . '" stroke-width="1.6"/>'
         . '</svg>';
};

/* RTT sparkline (24h) — keeps the existing semantics (red ticks on
   offline cycles, capped scale). */
$rtt_sparkline = function (array $samples) {
    if (!$samples) return '<small class="muted">No samples yet.</small>';
    $w = 720; $hh = 110; $pad_top = 8; $pad_bot = 14;
    $plot_h = $hh - $pad_top - $pad_bot;
    $now_ts   = time();
    $start_ts = $now_ts - 86400;
    $rtts = [];
    foreach ($samples as $s) if ($s['rtt_ms'] !== null) $rtts[] = (float)$s['rtt_ms'];
    $cap = $rtts ? min(max(50, max($rtts)), 250) : 50;

    $points = []; $offlines = [];
    foreach ($samples as $s) {
        $t = strtotime((string)$s['polled_at']);
        if ($t < $start_ts) continue;
        $x = (int)round((($t - $start_ts) / 86400) * $w);
        if ($s['status'] === 'online' && $s['rtt_ms'] !== null) {
            $r = max(0, min((float)$s['rtt_ms'], $cap));
            $y = $pad_top + (int)round($plot_h - ($r / $cap) * $plot_h);
            $points[] = "$x,$y";
        } else {
            $offlines[] = $x;
        }
    }
    $path = $points ? '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#4ade80" stroke-width="1.5"/>' : '';
    $bars = '';
    foreach ($offlines as $x) {
        $bars .= '<rect x="' . ($x - 1) . '" y="' . ($hh - $pad_bot - 4) . '" width="2" height="6" fill="#ff5470"/>';
    }
    $mid_y = $pad_top + (int)round($plot_h / 2);
    $grid  = '<line x1="0" x2="' . $w . '" y1="' . $mid_y . '" y2="' . $mid_y . '" stroke="rgba(255,255,255,0.05)" stroke-dasharray="2,3"/>';
    $axis  = '<line x1="0" x2="' . $w . '" y1="' . ($hh - $pad_bot) . '" y2="' . ($hh - $pad_bot) . '" stroke="rgba(255,255,255,0.10)"/>';
    $cap_label = '<text x="4" y="' . ($pad_top + 10) . '" fill="#6b7480" font-size="10">' . (int)$cap . ' ms</text>';
    $now_label = '<text x="' . ($w - 30) . '" y="' . ($hh - 2) . '" fill="#6b7480" font-size="10">now</text>';
    $start_label = '<text x="0" y="' . ($hh - 2) . '" fill="#6b7480" font-size="10">−24 h</text>';
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;background:rgba(255,255,255,0.02);border-radius:6px;">'
         . $grid . $axis . $bars . $path . $cap_label . $now_label . $start_label . '</svg>';
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
  .lv-meter b  { font-weight:500; color:var(--text); font-variant-numeric:tabular-nums; min-width:62px; text-align:right; }

  .data-table.compact th, .data-table.compact td { padding:8px 10px; font-size:12.5px; }
  .data-table tr.row-poor td { background:rgba(212,68,68,0.06); }
  .data-table tr.row-fair td { background:rgba(232,168,20,0.06); }

  .eol-banner { padding:10px 14px;background:rgba(255,84,112,0.10);border:1px solid rgba(255,84,112,0.35);border-radius:8px;color:var(--text);font-size:13px;margin-top:14px; }
</style>

<div class="lv-banner">
  <div class="lv-side lv-endpoint">
    <span class="lv-icon" title="<?= lv_h(ucfirst($d['role'])) ?>">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <?= $role_icon_path ?>
      </svg>
    </span>
    <div>
      <div class="lv-label"><?= lv_h(ucfirst($d['role'])) ?></div>
      <h4><?= lv_h($d['name']) ?> <?= lv_status_pill($d['status'] ?? null) ?></h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?= lv_h(ucfirst((string)($d['vendor'] ?? ''))) ?> · <?= lv_h($d['model'] ?? '—') ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        Firmware <?= lv_h($d['firmware'] ?: '—') ?>
      </div>
      <div style="margin-top:6px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <?= poll_badge_html($device_freshness, 'Newest device_health sample') ?>
        <?php if ($is_pollable): ?>
          <button type="button" class="btn btn-ghost btn-sm" data-poll-device-now="<?= (int)$d['id'] ?>" data-poll-device-name="<?= lv_h($d['name']) ?>" title="Run the vendor adapter against this device right now">Poll now</button>
        <?php endif; ?>
        <?php if ($is_pollable && in_array($d['vendor'] ?? '', ['ubiquiti','mikrotik','cambium','mimosa'], true)): ?>
          <button type="button" class="btn btn-danger btn-sm" data-reboot-device="<?= (int)$d['id'] ?>" data-reboot-name="<?= lv_h($d['name']) ?>" title="Issue a remote reboot — requires 2FA code">Reboot ↻</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="lv-dial" title="Uptime (24h online %)">
      <small>Uptime<br>24 h</small>
      <b><?= $uptime_pct !== null ? number_format($uptime_pct, 1) : '—' ?></b>
      <span class="lv-dial-unit">%</span>
    </div>
  </div>

  <div class="lv-mid">
    <div class="lv-arrow">
      <span class="lv-arrow-line"></span>
      <span class="lv-banner-distance">
        <strong><?= lv_h($last_seen_age) ?></strong>
        <span class="muted">last seen</span>
      </span>
      <span class="lv-arrow-line"></span>
    </div>
    <div class="lv-airtime">
      <b>Avg RTT</b> <?= $avg_rtt !== null ? number_format($avg_rtt, 1) . ' ms' : '—' ?>
      &nbsp;·&nbsp; <b>Min</b> <?= $min_rtt !== null ? number_format($min_rtt, 1) : '—' ?>
      &nbsp;·&nbsp; <b>Max</b> <?= $max_rtt !== null ? number_format($max_rtt, 1) : '—' ?>
    </div>
    <div class="lv-airtime"><b>Samples</b> <?= $online_24h ?> online / <?= $total_24h ?> total</div>
    <?php if ($cur_uptime_s): ?>
      <div class="lv-airtime"><b>Uptime</b> <?= lv_fmt_uptime((int)$cur_uptime_s) ?></div>
    <?php endif; ?>
  </div>

  <div class="lv-side lv-end lv-endpoint">
    <div class="lv-dial" title="Average RTT (24h)">
      <small>Avg RTT<br>24 h</small>
      <b><?= $avg_rtt !== null ? number_format($avg_rtt, 1) : '—' ?></b>
      <span class="lv-dial-unit">ms</span>
    </div>
    <div style="text-align:right;">
      <div class="lv-label">Site / customer</div>
      <h4><?= lv_h($site['name'] ?? '— unassigned —') ?></h4>
      <?php if ($customer): ?>
        <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
          <a href="/admin/client-view.php?id=<?= (int)$customer['id'] ?>">
            <?= lv_h(trim((string)$customer['name'] . ' ' . (string)$customer['surname'])) ?>
            <?php if (!empty($customer['account_no'])): ?> · <?= lv_h($customer['account_no']) ?><?php endif; ?>
          </a>
        </div>
      <?php endif; ?>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        <?= !empty($d['mgmt_ip']) ? '<code>' . lv_h($d['mgmt_ip'] . ($d['mgmt_port'] ? ':' . $d['mgmt_port'] : '')) . '</code>' : '—' ?>
      </div>
    </div>
    <span class="lv-icon" title="Site">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2C7 2 4 5 4 9c0 6 8 13 8 13s8-7 8-13c0-4-3-7-8-7z"/><circle cx="12" cy="9" r="2"/>
      </svg>
    </span>
  </div>
</div>

<div class="lv-tabs">
  <a class="lv-tab" href="/admin/map.php?focus=device&amp;id=<?= (int)$d['id'] ?>">Map</a>
  <a class="lv-tab <?= $tab === 'device' ? 'active' : '' ?>" href="?id=<?= (int)$d['id'] ?>&amp;tab=device">Device</a>
  <a class="lv-tab <?= $tab === 'health' ? 'active' : '' ?>" href="?id=<?= (int)$d['id'] ?>&amp;tab=health">Health</a>
  <a class="lv-tab" href="/admin/devices.php?edit=<?= (int)$d['id'] ?>">Edit</a>
  <?php if ($sector): ?>
    <a class="lv-tab" href="/admin/sector-view.php?id=<?= (int)$sector['id'] ?>">Sector ↗</a>
  <?php endif; ?>
</div>

<?php if ($eol_warning): ?>
<div class="portal-card eol-banner">
  <strong>Firmware EOL</strong> — <?= lv_h($eol_warning['notes']) ?:
    'This firmware version reached its end-of-life on ' . lv_h((string)$eol_warning['eol_date']) . '. Plan an upgrade.' ?>
  <?php if (!empty($eol_warning['eol_date'])): ?>
    <small class="muted"> · EOL <?= lv_h($eol_warning['eol_date']) ?>
      <?php if (!empty($eol_warning['eos_date'])): ?> · EOS <?= lv_h($eol_warning['eos_date']) ?><?php endif; ?>
    </small>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'health'): ?>
<div class="portal-card">
  <h3 class="lv-label" style="font-size:11px;">Recent samples <span style="color:var(--text-muted);">(<?= count($recent_table) ?>)</span></h3>
  <?php if (!$recent_table): ?>
    <small class="muted">No health samples on record yet. Once <code>bin/poll-devices.php</code> runs a couple of times, this fills in.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table compact">
      <thead><tr><th>Polled</th><th>Status</th><th>RTT (ms)</th><th>CPU</th><th>Memory</th><th>Uptime</th></tr></thead>
      <tbody>
        <?php foreach ($recent_table as $r): ?>
          <tr>
            <td><small><?= lv_h($r['polled_at']) ?></small></td>
            <td><?= lv_status_pill($r['status']) ?></td>
            <td><small><?= $r['rtt_ms']  !== null ? number_format((float)$r['rtt_ms'], 2) : '—' ?></small></td>
            <td><small><?= $r['cpu_pct'] !== null ? (int)$r['cpu_pct'] . ' %' : '—' ?></small></td>
            <td><small><?= $r['mem_pct'] !== null ? (int)$r['mem_pct'] . ' %' : '—' ?></small></td>
            <td><small><?= $r['uptime_seconds'] !== null ? lv_fmt_uptime((int)$r['uptime_seconds']) : '—' ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php else: /* device tab (default) */ ?>

<div class="lv-grid">
  <!-- Left: Health charts -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Device health · last 24 h</h3>
      <span class="lv-tag"><?= $total_24h ?> samples</span>
    </div>

    <h4 class="lv-label">RTT (ms)</h4>
    <?= $rtt_sparkline($samples_24h) ?>
    <small class="muted">Green line: ICMP RTT. Red ticks mark cycles where the device was unreachable.</small>

    <div class="lv-mini-section">
      <h4>CPU (%)</h4>
      <?= $mini_sparkline($cpus, '#05DAFD', 100) ?>
      <div class="lv-row" style="border-top:0;padding-top:8px;">
        <span><b>Current</b></span>
        <span class="lv-meter" style="min-width:200px;">
          <span class="lv-bar lv-cpu"><span style="width:<?= $cur_cpu !== null ? (int)$cur_cpu : 0 ?>%;"></span></span>
          <b><?= $cur_cpu !== null ? (int)$cur_cpu . ' %' : '—' ?></b>
        </span>
      </div>
    </div>

    <div class="lv-mini-section">
      <h4>Memory (%)</h4>
      <?= $mini_sparkline($mems, '#a25cf0', 100) ?>
      <div class="lv-row" style="border-top:0;padding-top:8px;">
        <span><b>Current</b></span>
        <span class="lv-meter" style="min-width:200px;">
          <span class="lv-bar lv-mem"><span style="width:<?= $cur_mem !== null ? (int)$cur_mem : 0 ?>%;"></span></span>
          <b><?= $cur_mem !== null ? (int)$cur_mem . ' %' : '—' ?></b>
        </span>
      </div>
    </div>

    <?php if ($rf): ?>
      <div class="lv-mini-section">
        <h4>RF environment <span style="color:var(--text-muted);">(last hour)</span></h4>
        <?= lv_rf_bars($rf, null, null) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right: More details + Ethernet -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">More details</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/devices.php?edit=<?= (int)$d['id'] ?>">Edit ↗</a>
    </div>
    <div class="lv-row"><span><b>Device model</b></span><span><?= lv_h($d['model'] ?: '—') ?></span></div>
    <div class="lv-row"><span><b>Vendor</b></span>      <span><?= lv_h(ucfirst((string)$d['vendor'])) ?></span></div>
    <div class="lv-row"><span><b>Role</b></span>        <span><?= lv_h(ucfirst((string)$d['role'])) ?></span></div>
    <div class="lv-row"><span><b>Firmware</b></span>    <span><?= lv_h($d['firmware'] ?: '—') ?></span></div>
    <div class="lv-row"><span><b>Network mode</b></span><span><?= lv_h(ucfirst((string)($d['network_mode'] ?? 'unknown'))) ?></span></div>
    <div class="lv-row"><span><b>Serial</b></span>      <span><code><?= lv_h($d['serial'] ?: '—') ?></code></span></div>
    <div class="lv-row"><span><b>MAC</b></span>         <span><code><?= lv_h($d['mac'] ?: '—') ?></code></span></div>
    <div class="lv-row"><span><b>Mgmt IP</b></span>     <span><code><?= lv_h($d['mgmt_ip'] ?: '—') ?><?= $d['mgmt_port'] ? ':' . (int)$d['mgmt_port'] : '' ?></code></span></div>
    <?php if (isset($d['antenna_gain_dbi']) && $d['antenna_gain_dbi'] !== null): ?>
      <div class="lv-row"><span><b>Antenna gain</b></span>
        <span><?= number_format((float)$d['antenna_gain_dbi'], 1) ?> dBi
          <?php if (!empty($d['antenna_pattern'])): ?> · <?= lv_h($d['antenna_pattern']) ?><?php endif; ?>
        </span></div>
    <?php endif; ?>
    <div class="lv-row"><span><b>Site</b></span>
      <span><?php if ($site): ?><a href="/admin/site-view.php?id=<?= (int)$site['id'] ?>"><?= lv_h($site['name']) ?></a><?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Customer</b></span>
      <span><?php if ($customer): ?>
        <a href="/admin/client-view.php?id=<?= (int)$customer['id'] ?>"><?= lv_h(trim((string)$customer['name'] . ' ' . (string)$customer['surname'])) ?>
          <?php if (!empty($customer['account_no'])): ?> · <?= lv_h($customer['account_no']) ?><?php endif; ?>
        </a>
      <?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Last seen</b></span>   <span><?= lv_h($d['last_seen_at'] ?: 'never') ?></span></div>
    <div class="lv-row"><span><b>Created</b></span>     <span><?= lv_h(lv_fmt_dt($d['created_at'] ?? null)) ?></span></div>

    <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Ethernet</h3>
    <?php if ($eth): ?>
      <div class="lv-row"><span><b>LAN port</b></span>     <span><?= lv_h($eth['lan_port'] ?? '—') ?></span></div>
      <div class="lv-row"><span><b>LAN speed</b></span>
        <span><?= $eth['link_speed_mbps'] !== null
            ? number_format((float)$eth['link_speed_mbps'], 0) . ' Mbps-' . lv_h(ucfirst((string)$eth['duplex']))
            : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable SNR</b></span>
        <span><?= $eth['cable_snr_db'] !== null
            ? '+' . number_format((float)$eth['cable_snr_db'], 0) . ' dB' : '—' ?></span></div>
      <div class="lv-row"><span><b>Cable length</b></span>
        <span><?= $eth['cable_length_m'] !== null
            ? number_format((float)$eth['cable_length_m'] / 0.3048, 0) . ' ft · '
              . number_format((float)$eth['cable_length_m'], 0) . ' m'
            : '—' ?></span></div>
      <?php
      $pairs = array_filter([
          'A' => $eth['pair_a_status'] ?? null,
          'B' => $eth['pair_b_status'] ?? null,
          'C' => $eth['pair_c_status'] ?? null,
          'D' => $eth['pair_d_status'] ?? null,
      ], fn ($v) => $v !== null && $v !== 'unknown' && $v !== '');
      if ($pairs): ?>
        <div class="lv-row"><span><b>TDR pairs</b></span>
          <span><?php foreach ($pairs as $k => $v): ?>
            <span class="lv-tag">Pair <?= lv_h($k) ?>: <?= lv_h($v) ?></span>
          <?php endforeach; ?></span></div>
      <?php endif; ?>
    <?php else: ?>
      <small class="muted">No cable diagnostics yet.</small>
    <?php endif; ?>

    <?php if ($d['notes']): ?>
      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Notes</h3>
      <p style="white-space:pre-wrap;color:var(--text-dim);font-size:13px;"><?= lv_h($d['notes']) ?></p>
    <?php endif; ?>
  </div>
</div>

<!-- Wireless links using this device -->
<?php if ($wireless_links): ?>
<div class="portal-card" style="margin-top:18px;">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">Wireless links · this device <span style="color:var(--text-muted);">(<?= count($wireless_links) ?>)</span></h3>
    <a class="btn btn-ghost btn-sm" href="/admin/links.php?ap_device_id=<?= (int)$d['id'] ?>">All links ↗</a>
  </div>
  <div class="table-scroll">
  <table class="data-table compact">
    <thead>
      <tr><th>Health</th><th>AP</th><th>CPE</th><th>Customer</th><th>Signal</th><th>SNR</th><th>TX / RX</th><th>Last sample</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($wireless_links as $l):
        $row_class = '';
        if ($l['health_score'] !== null) {
          if      ($l['health_score'] < 50) $row_class = 'row-poor';
          elseif  ($l['health_score'] < 75) $row_class = 'row-fair';
        }
        $cust = trim((string)($l['customer_name'] ?? '') . ' ' . (string)($l['customer_surname'] ?? ''));
      ?>
        <tr class="<?= $row_class ?>">
          <td><?= lv_health_pill($l['health_score'] !== null ? (int)$l['health_score'] : null) ?></td>
          <td><strong><?= lv_h($l['ap_name']) ?></strong>
            <?php if ($l['ap_model']): ?><br><small class="muted"><?= lv_h($l['ap_model']) ?></small><?php endif; ?></td>
          <td>
            <?php if ($l['cpe_name']): ?>
              <strong><?= lv_h($l['cpe_name']) ?></strong>
              <?php if ($l['cpe_model']): ?><br><small class="muted"><?= lv_h($l['cpe_model']) ?></small><?php endif; ?>
            <?php else: ?><small class="muted">—</small><?php endif; ?>
          </td>
          <td><?= lv_h($cust) ?: '<small class="muted">—</small>' ?></td>
          <td><?= $l['signal_dbm'] !== null ? (int)$l['signal_dbm'] . ' <small class="muted">dBm</small>' : '<small class="muted">—</small>' ?></td>
          <td><?= $l['snr_db']     !== null ? (int)$l['snr_db']     . ' <small class="muted">dB</small>'  : '<small class="muted">—</small>' ?></td>
          <td>
            <?php if ($l['tx_rate_mbps'] !== null): ?>
              <?= number_format((float)$l['tx_rate_mbps'], 0) ?> /
              <?= number_format((float)($l['rx_rate_mbps'] ?? 0), 0) ?>
              <small class="muted">Mbps</small>
            <?php else: ?><small class="muted">—</small><?php endif; ?>
          </td>
          <td><small class="muted"><?= lv_h($l['last_evaluated_at'] ?? 'never') ?></small></td>
          <td><a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=<?= (int)$l['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php endif; /* tab */ ?>

<div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="/admin/devices.php">← All devices</a>
    <?php if ($site): ?><a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$site['id'] ?>">Open site</a><?php endif; ?>
    <?php if ($customer): ?><a class="btn btn-ghost btn-sm" href="/admin/client-view.php?id=<?= (int)$customer['id'] ?>">Open customer</a><?php endif; ?>
    <?php if ($sector): ?><a class="btn btn-ghost btn-sm" href="/admin/sector-view.php?id=<?= (int)$sector['id'] ?>">Open sector</a><?php endif; ?>
    <a class="btn btn-primary btn-sm" href="/admin/devices.php?edit=<?= (int)$d['id'] ?>">Edit device</a>
  </div>
  <small class="muted">last seen <?= lv_h($last_seen_age) ?></small>
</div>
