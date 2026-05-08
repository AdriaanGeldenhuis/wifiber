<?php
/**
 * Customer self-serve link diagnostics — end-user "Connection details"
 * dashboard. Same visual language as /admin/link-view.php (banner with
 * both endpoints + distance pill, RF environment heat-bar, signal min/max
 * card with chain delta, RX rate ladder, signal/noise/interference 24h
 * chart, CINR gauge, more-details panel for the customer's CPE) — minus
 * the operator-only knobs (no sector edit, no AP credentials, no push
 * jobs).
 *
 * Pulls the customer's own wireless_links rows + recent link_alerts and
 * lets them queue an iperf3 speed test (rate-limited).
 */
$page_title = 'Connection details';
$active_key = 'link-health';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/diagnostics.php';

$pdo = pdo();

// Self-serve speed test (rate-limited).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'speedtest') {
    require_csrf();
    if (!rate_limit_check('cust-speedtest:' . $user['id'], 3, 3600)) {
        flash('error', 'Speed-tests are limited to 3 per hour.');
        header('Location: /account/link-health.php');
        exit;
    }
    $link_id = (int)($_POST['link_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM wireless_links WHERE id = ? AND customer_id = ? LIMIT 1");
    $stmt->execute([$link_id, (int)$user['id']]);
    if (!$stmt->fetchColumn()) {
        flash('error', 'Link not found.');
        header('Location: /account/link-health.php');
        exit;
    }
    $site = load_site_settings();
    $target = (string)($site['speedtest_target_ip'] ?? '');
    if ($target === '') {
        flash('error', 'Self-serve speed tests are not configured. Please contact support.');
    } else {
        diagnostic_job_enqueue('iperf3', 'link', $link_id, (int)$user['id'], [
            'target_ip'  => $target,
            'duration_s' => 10,
        ]);
        audit_log('customer.speedtest', [
            'target_type' => 'wireless_link', 'target_id' => $link_id,
            'meta' => ['actor' => 'customer'],
        ]);
        flash('success', 'Speed test queued. Refresh in ~30 seconds.');
    }
    header('Location: /account/link-health.php');
    exit;
}

$links_stmt = $pdo->prepare(
    "SELECT wl.*,
            ap.name  AS ap_name,  ap.vendor AS ap_vendor,  ap.model AS ap_model,
            ap.firmware AS ap_firmware, ap.site_id AS ap_site_id,
            ap.mgmt_ip AS ap_mgmt_ip, ap.network_mode AS ap_network_mode,
            ap.last_seen_at AS ap_last_seen, ap.status AS ap_status,
            cpe.name AS cpe_name, cpe.vendor AS cpe_vendor, cpe.model AS cpe_model,
            cpe.firmware AS cpe_firmware, cpe.site_id AS cpe_site_id,
            cpe.mgmt_ip AS cpe_mgmt_ip, cpe.network_mode AS cpe_network_mode,
            cpe.last_seen_at AS cpe_last_seen, cpe.status AS cpe_status,
            s.tdd_framing AS sector_tdd_framing
       FROM wireless_links wl
       JOIN devices ap        ON ap.id  = wl.ap_device_id
       LEFT JOIN devices cpe  ON cpe.id = wl.cpe_device_id
       LEFT JOIN sectors s    ON s.id   = wl.sector_id
      WHERE wl.customer_id = ?
      ORDER BY wl.last_evaluated_at DESC"
);
$links_stmt->execute([(int)$user['id']]);
$links = $links_stmt->fetchAll();

$alerts_stmt = $pdo->prepare(
    "SELECT la.*, wl.id AS lid
       FROM link_alerts la
       JOIN wireless_links wl ON wl.id = la.link_id
      WHERE wl.customer_id = ? AND la.resolved_at IS NULL
      ORDER BY la.opened_at DESC"
);
$alerts_stmt->execute([(int)$user['id']]);
$alerts = $alerts_stmt->fetchAll();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fmt_uptime = function (?int $s): string {
    if (!$s) return '—';
    $d = intdiv($s, 86400); $s %= 86400;
    $hh = intdiv($s, 3600); $s %= 3600;
    $mm = intdiv($s, 60);
    if ($d > 0)  return sprintf('%d day%s %02d:%02d:%02d', $d, $d === 1 ? '' : 's', $hh, $mm, $s);
    if ($hh > 0) return sprintf('%d:%02d:%02d', $hh, $mm, $s);
    return sprintf('00:%02d:%02d', $mm, $s);
};
$fmt_bytes = function ($b): string {
    if ($b === null) return '—';
    $b = (float)$b;
    foreach (['B','K','M','G','T','P'] as $u) {
        if ($b < 1024) return number_format($b, $u === 'B' ? 0 : 2) . ' ' . $u;
        $b /= 1024;
    }
    return number_format($b, 2) . ' E';
};
$fmt_ft = function (?float $km): string {
    if ($km === null) return '—';
    $ft = $km * 1000.0 / 0.3048;
    return $ft >= 5280
        ? number_format($ft / 5280.0, 2) . ' mi'
        : number_format($ft, 2) . ' ft';
};
$fmt_dt = fn ($dt) => $dt ? date('Y-m-d H:i:s', strtotime((string)$dt)) : '—';

$health_pill = function (?int $score): string {
    if ($score === null) return '<span class="cd-pill" style="background:#2a3340;color:#a5b0bd;">no data</span>';
    [$bg, $label] = match (true) {
        $score >= 75 => ['#4ade80', 'good'],
        $score >= 50 => ['#e8a814', 'fair'],
        default      => ['#ff5470', 'poor'],
    };
    return '<span class="cd-pill" style="background:' . $bg . ';color:#001218;">' . $score . ' · ' . $label . '</span>';
};
$status_pill = function (?string $status): string {
    $status = $status ?: 'unknown';
    $bg = match ($status) {
        'online'  => '#4ade80',
        'offline' => '#ff5470',
        default   => '#6b7480',
    };
    return '<span class="cd-pill" style="background:' . $bg . ';color:#001218;">' . $status . '</span>';
};
$chain_label = function (?int $main, ?int $c0, ?int $c1) {
    if ($main === null && $c0 === null && $c1 === null) {
        return '<strong class="cd-bigstat">—</strong>';
    }
    $delta = ($c0 !== null && $c1 !== null) ? abs($c0 - $c1) : null;
    $chain = ($c0 !== null || $c1 !== null)
        ? '(' . ($c0 ?? '—') . ' / ' . ($c1 ?? '—') . ')'
        : '';
    $delta_label = $delta !== null ? ' Δ' . $delta : '';
    return '<strong class="cd-bigstat">' . ($main !== null ? (int)$main : '—') . '</strong>'
         . ' <span class="cd-suffix">' . $chain . $delta_label . ' dBm</span>';
};

$cinr_gauge = function (?int $snr) {
    if ($snr === null) return '<small class="muted">No CINR samples.</small>';
    $w = 720; $hh = 30;
    $clamped = max(0, min(40, $snr));
    $x = $clamped / 40 * ($w - 4);
    $colour = $snr >= 25 ? '#4ade80' : ($snr >= 15 ? '#05DAFD' : ($snr >= 10 ? '#e8a814' : '#ff5470'));
    $segs = '';
    for ($i = 0; $i <= 4; $i++) {
        $sx = $i / 4 * ($w - 4);
        $segs .= '<line x1="' . $sx . '" y1="0" x2="' . $sx . '" y2="' . $hh
              . '" stroke="rgba(255,255,255,0.08)" stroke-width="1"/>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . '<rect x="0" y="9" width="' . $w . '" height="12" rx="3" fill="rgba(255,255,255,0.05)"/>'
        . '<rect x="0" y="9" width="' . $x . '" height="12" rx="3" fill="' . $colour . '"/>'
        . $segs . '</svg>'
        . '<div style="display:flex;justify-content:space-between;font-size:10px;color:#6b7480;margin-top:2px;">'
        . '<span>0</span><span>10</span><span>20</span><span>30</span><span>40</span></div>';
};

$rate_ladder = function (?int $current, ?int $expected, ?int $max, string $modulation) use ($h) {
    $max     = max(2, (int)($max ?? 8));
    $current = $current !== null ? max(0, min($max, (int)$current)) : null;
    $expected= $expected !== null ? max(0, min($max, (int)$expected)) : null;
    $w = 720; $hh = 26;
    $cell  = $w / $max;
    $cells = '';
    for ($i = 1; $i <= $max; $i++) {
        $cx = ($i - 1) * $cell;
        $is_cur = ($current !== null && $i === $current);
        $is_exp = ($expected !== null && $i === $expected);
        $is_below_cur = ($current !== null && $i < $current);
        $colour = $is_cur ? '#05DAFD'
                : ($is_exp ? 'rgba(5,218,253,0.55)'
                : ($is_below_cur ? 'rgba(5,218,253,0.25)'
                : 'rgba(255,255,255,0.05)'));
        $cells .= '<rect x="' . round($cx + 1, 1) . '" y="0" width="' . round($cell - 2, 1)
               . '" height="' . $hh . '" rx="3" fill="' . $colour . '"/>';
        $label_colour = $is_cur ? '#001218' : '#a5b0bd';
        $cells .= '<text x="' . round($cx + $cell / 2, 1) . '" y="' . ($hh / 2 + 4)
               . '" font-size="11" fill="' . $label_colour . '" text-anchor="middle" font-weight="'
               . ($is_cur ? '700' : '500') . '">' . $i . 'x</text>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . $cells . '</svg>'
        . '<div style="display:flex;justify-content:space-between;font-size:10px;color:#6b7480;margin-top:4px;">'
        . '<span>1X</span><span>2X</span><span>3X</span><span>4X</span>'
        . '<span>5X</span><span>6X</span><span>7X</span>'
        . '<span>' . $max . 'X' . ($modulation !== '' ? ' · ' . $h($modulation) : '') . '</span>'
        . '</div>';
};

$signal_chart_svg = function (array $samples): string {
    if (!$samples) return '<small class="muted">No samples yet — wait for the next poll.</small>';
    $w = 720; $hh = 170; $pad_l = 36; $pad_b = 22; $pad_t = 8;
    $rows = array_reverse($samples);
    $now  = time();
    $xs = []; $sigs = []; $noise = []; $intr = [];
    foreach ($rows as $r) {
        $t = strtotime((string)$r['polled_at']) ?: $now;
        $xs[] = $t;
        $sigs[]  = $r['signal_local_dbm'] !== null ? (int)$r['signal_local_dbm'] : null;
        $noise[] = $r['noise_local_dbm']  !== null ? (int)$r['noise_local_dbm']  : null;
        $a       = (float)($r['airtime_local_pct'] ?? 0);
        $intr[]  = $r['noise_local_dbm']  !== null ? (int)$r['noise_local_dbm'] + ($a > 50 ? 6 : 2) : null;
    }
    $tmin = min($xs); $tmax = max($xs); $tspan = max(1, $tmax - $tmin);
    $ymin = -110; $ymax = -40;
    $sx = fn ($t) => $pad_l + ($t - $tmin) / $tspan * ($w - $pad_l - 6);
    $sy = fn ($v) => $pad_t + ($ymax - $v) / ($ymax - $ymin) * ($hh - $pad_t - $pad_b);
    $line = function (array $vals, string $colour, float $width = 1.5) use ($xs, $sx, $sy) {
        $d = ''; $started = false;
        foreach ($vals as $i => $v) {
            if ($v === null) { $started = false; continue; }
            $d .= ($started ? 'L' : 'M') . round($sx($xs[$i]), 1) . ',' . round($sy($v), 1) . ' ';
            $started = true;
        }
        return $d === '' ? '' : '<path d="' . $d . '" fill="none" stroke="' . $colour . '" stroke-width="' . $width . '"/>';
    };
    $grid = '';
    foreach ([-50, -60, -70, -80, -90, -100, -110] as $y) {
        $py = $sy($y);
        $grid .= '<line x1="' . $pad_l . '" y1="' . $py . '" x2="' . ($w - 6) . '" y2="' . $py
              . '" stroke="rgba(255,255,255,0.05)" stroke-width="1"/>';
        $grid .= '<text x="' . ($w - 4) . '" y="' . ($py + 3) . '" font-size="9" fill="#6b7480" text-anchor="end">' . $y . '</text>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . '<rect x="' . $pad_l . '" y="' . $pad_t . '" width="' . ($w - $pad_l - 6) . '" height="' . ($hh - $pad_t - $pad_b)
        . '" fill="rgba(255,255,255,0.02)"/>' . $grid
        . $line($sigs,  '#4477ff', 1.7)
        . $line($intr,  '#e8a814', 1.4)
        . $line($noise, '#4ade80', 1.4)
        . '</svg>';
};

$rf_bars = function (array $rf, ?int $centre_mhz, ?int $width_mhz) use ($h): string {
    if (!$rf) return '<small class="muted">No RF scan samples yet.</small>';
    $w = 720; $bar_h = 56;
    $freqs = array_column($rf, 'freq_mhz');
    $fmin = min($freqs); $fmax = max($freqs);
    $fspan = max(1, $fmax - $fmin);
    $bars = '';
    foreach ($rf as $r) {
        $x = ($r['freq_mhz'] - $fmin) / $fspan * ($w - 2);
        $rssi = (int)$r['rssi_dbm'];
        $intensity = max(0, min(1, (-30 - $rssi) / 70));
        $alpha  = 0.20 + 0.80 * (1 - $intensity);
        $colour = sprintf('rgba(5,218,253,%.2f)', $alpha);
        $bars .= '<rect x="' . round($x, 1) . '" y="0" width="3" height="' . $bar_h . '" fill="' . $colour . '"/>';
    }
    $highlight = '';
    if ($centre_mhz && $width_mhz) {
        $lo = $centre_mhz - $width_mhz / 2;
        $hi = $centre_mhz + $width_mhz / 2;
        $hx = ($lo - $fmin) / $fspan * ($w - 2);
        $hw = ($hi - $lo) / $fspan * ($w - 2);
        $highlight = '<rect x="' . round($hx, 1) . '" y="0" width="' . round($hw, 1) . '" height="' . $bar_h
                   . '" fill="rgba(5,218,253,0.18)" stroke="#05DAFD" stroke-width="1"/>';
    }
    $label_centre = $centre_mhz ? '<span style="color:#f4f6f8;font-weight:600;">' . (int)$centre_mhz . ' MHz</span>'
                                . ($width_mhz ? '<span class="muted"> · ' . (int)$width_mhz . ' MHz</span>' : '')
                                : '';
    return '<svg viewBox="0 0 ' . $w . ' ' . $bar_h . '" width="100%" preserveAspectRatio="none" style="display:block;border-radius:6px;overflow:hidden;">'
        . '<rect x="0" y="0" width="' . $w . '" height="' . $bar_h . '" fill="rgba(5,218,253,0.06)"/>'
        . $bars . $highlight
        . '</svg>'
        . '<div style="display:flex;justify-content:space-between;align-items:baseline;font-size:11px;color:#6b7480;margin-top:4px;">'
        . '<span>' . $h($fmin) . ' MHz</span>'
        . '<span>' . $label_centre . '</span>'
        . '<span>' . $h($fmax) . ' MHz</span></div>';
};
?>

<style>
  .cd-grid     { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
  .cd-grid > * { min-width:0; }
  @media (max-width: 980px) { .cd-grid { grid-template-columns:1fr; } }

  .cd-bigstat { font-size:34px; font-weight:300; line-height:1; color:var(--text); letter-spacing:-0.02em; }
  .cd-suffix  { font-size:13px; color:var(--text-muted); }
  .cd-label   { font-size:10.5px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.07em; font-weight:600; }
  .cd-row     { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04); font-size:13px; gap:12px; }
  .cd-row:last-child { border-bottom:none; }
  .cd-row b   { font-weight:500; color:var(--text-dim); }
  .cd-row span:last-child { color:var(--text); font-variant-numeric:tabular-nums; }

  .cd-pill    { display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;letter-spacing:.02em;text-transform:uppercase; }
  .cd-tag     { display:inline-block;padding:1px 8px;border-radius:8px;font-size:10.5px;color:var(--text-dim);background:rgba(255,255,255,0.04);border:1px solid var(--border);letter-spacing:.05em; }

  .cd-banner {
    display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; gap:20px;
    padding:18px 22px; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
  }
  .cd-banner .cd-side  { display:flex; align-items:center; gap:16px; }
  .cd-banner .cd-side.cd-end { justify-content:flex-end; }
  .cd-banner .cd-mid   { text-align:center; }
  .cd-icon {
    width:46px; height:46px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
    border-radius:50%; background:var(--bg-elev); border:1px solid var(--border-strong); color:var(--accent);
  }
  .cd-banner-distance {
    display:inline-flex; align-items:center; gap:8px;
    background:#000; color:#f4f6f8; padding:8px 16px; border-radius:18px; font-size:13px;
    border:1px solid var(--border-strong); font-variant-numeric:tabular-nums;
  }
  .cd-banner-distance svg { width:14px; height:14px; }
  .cd-airtime { font-size:11px; color:var(--text-muted); margin-top:6px; letter-spacing:.04em; }
  .cd-airtime b { color:var(--text-dim); font-weight:500; }
  .cd-arrow   {
    flex:1; display:flex; align-items:center; justify-content:center; gap:8px;
    color:var(--text-muted); font-size:11px;
  }
  .cd-arrow .cd-arrow-line { flex:1; height:1px; background:linear-gradient(90deg, transparent, var(--border-strong), transparent); }

  .cd-dial {
    display:inline-flex; flex-direction:column; align-items:center; justify-content:center;
    width:88px; height:88px; border-radius:50%;
    border:3px solid var(--accent); background:var(--bg-elev);
    box-shadow:0 0 0 4px var(--accent-soft);
    flex-shrink:0;
  }
  .cd-dial small { display:block; font-size:8.5px; color:var(--text-muted); text-align:center; line-height:1.05; text-transform:uppercase; letter-spacing:.04em; }
  .cd-dial b     { font-size:18px; font-weight:500; color:var(--text); margin:2px 0; font-variant-numeric:tabular-nums; }
  .cd-dial .cd-dial-unit { font-size:9px; color:var(--text-muted); }

  .cd-endpoint h4 { margin:0 0 2px; font-size:14px; font-weight:600; color:var(--text); }

  .cd-meter   { display:flex; align-items:center; gap:10px; }
  .cd-meter .cd-bar { flex:1; height:6px; border-radius:3px; background:rgba(255,255,255,0.05); overflow:hidden; }
  .cd-meter .cd-bar > span { display:block; height:100%; border-radius:3px; }
  .cd-meter .cd-mem  > span { background:#a25cf0; }
  .cd-meter .cd-cpu  > span { background:#05DAFD; }
  .cd-meter b { font-weight:500; color:var(--text); font-variant-numeric:tabular-nums; min-width:42px; text-align:right; }

  .cd-mini { padding-top:14px; border-top:1px solid var(--border); margin-top:14px; }
  .cd-mini h4 { font-size:11px; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); margin:0 0 8px; font-weight:600; }

  .legend     { display:flex; gap:18px; flex-wrap:wrap; font-size:11.5px; color:var(--text-dim); padding:10px 0 0; }
  .legend-dot { display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:middle; }
</style>

<div class="portal-head">
  <h1>Your connection</h1>
  <p class="portal-sub">Live signal, capacity and recent activity on the wireless link from our tower to your premises.</p>
</div>

<?php if (!$links): ?>
  <div class="portal-card">
    <p class="muted">We don't have a wireless-link record for your account yet. If you've just been installed, please give it a few minutes for our network to register the connection.</p>
  </div>
<?php else: ?>
  <?php foreach ($links as $l):
    $rf_ap   = rf_environment_recent((int)$l['ap_device_id'], 60);
    $rf_cpe  = $l['cpe_device_id'] ? rf_environment_recent((int)$l['cpe_device_id'], 60) : [];
    $cpe_dh  = $l['cpe_device_id'] ? device_recent_health((int)$l['cpe_device_id'], 1) : [];
    $cpe_h_  = $cpe_dh[0] ?? null;
    $cpe_eth = $l['cpe_device_id'] ? ethernet_health_latest((int)$l['cpe_device_id']) : null;
    $samples = wireless_link_recent_samples((int)$l['id'], 288);
    $tests   = link_speedtests_recent((int)$l['id'], 6);

    $tdd     = $l['tdd_framing'] ?: ($l['sector_tdd_framing'] ?? '');
    $freq_lbl = $l['frequency_mhz'] !== null
        ? (int)$l['frequency_mhz'] . ' MHz'
          . ($l['channel_width_mhz'] !== null ? ' · ' . (int)$l['channel_width_mhz'] . ' MHz wide' : '')
        : '—';
  ?>

  <!-- Banner with both endpoints + distance pill -->
  <div class="cd-banner">
    <div class="cd-side cd-endpoint">
      <span class="cd-icon" title="Tower AP">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M5 12a7 7 0 0 1 14 0"/><path d="M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>
        </svg>
      </span>
      <div>
        <div class="cd-label">Local · Our tower</div>
        <h4><?= $h($l['ap_name']) ?> <?= $status_pill($l['ap_status'] ?? null) ?></h4>
        <div class="cd-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
          <?= $h(ucfirst((string)($l['ap_vendor'] ?? ''))) ?> · <?= $h($l['ap_model']) ?>
        </div>
        <div class="cd-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
          TX power <?= $l['tx_power_dbm_local'] !== null ? (int)$l['tx_power_dbm_local'] . ' dBm' : '—' ?>
        </div>
      </div>
      <div class="cd-dial" title="Throughput / capacity (Mbps)">
        <small>Throughput<br>Capacity</small>
        <b><?= $l['capacity_local_mbps'] !== null ? number_format((float)$l['capacity_local_mbps'], 2) : '—' ?></b>
        <span class="cd-dial-unit">Mbps</span>
      </div>
    </div>

    <div class="cd-mid">
      <div class="cd-arrow">
        <span class="cd-arrow-line"></span>
        <span class="cd-banner-distance">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11V7a4 4 0 0 0-8 0v4"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
          <strong><?= $fmt_ft($l['distance_km'] !== null ? (float)$l['distance_km'] : null) ?></strong>
        </span>
        <span class="cd-arrow-line"></span>
      </div>
      <div class="cd-airtime">
        <b>Airtime</b>
        <?= $l['airtime_local_pct']  !== null ? number_format((float)$l['airtime_local_pct'], 1)  . '%' : '—' ?>
        &nbsp;·&nbsp;
        <?= $l['airtime_remote_pct'] !== null ? number_format((float)$l['airtime_remote_pct'], 1) . '%' : '—' ?>
      </div>
      <div class="cd-airtime"><b>Frequency</b> <?= $h($freq_lbl) ?> &nbsp;·&nbsp; <b>Mode</b> <?= $h($l['wireless_mode'] ?? '—') ?></div>
      <div class="cd-airtime"><b>Health</b> <?= $health_pill($l['health_score']) ?></div>
    </div>

    <div class="cd-side cd-end cd-endpoint">
      <div class="cd-dial" title="Throughput / capacity (Mbps)">
        <small>Throughput<br>Capacity</small>
        <b><?= $l['capacity_remote_mbps'] !== null ? number_format((float)$l['capacity_remote_mbps'], 2) : '—' ?></b>
        <span class="cd-dial-unit">Mbps</span>
      </div>
      <div style="text-align:right;">
        <div class="cd-label">Remote · Your CPE</div>
        <h4><?= $h($l['cpe_name'] ?? '—') ?>
          <?php if ($l['cpe_device_id']): ?><?= $status_pill($l['cpe_status'] ?? null) ?><?php endif; ?>
        </h4>
        <div class="cd-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
          <?= $h(ucfirst((string)($l['cpe_vendor'] ?? ''))) ?> · <?= $h($l['cpe_model'] ?? '') ?>
        </div>
        <div class="cd-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
          TX power <?= $l['tx_power_dbm_remote'] !== null ? (int)$l['tx_power_dbm_remote'] . ' dBm' : '—' ?>
        </div>
      </div>
      <span class="cd-icon" title="Your CPE">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <ellipse cx="12" cy="12" rx="9" ry="4"/><path d="M3 12c0 4 3 7 9 7s9-3 9-7"/><path d="M12 16v3"/>
        </svg>
      </span>
    </div>
  </div>

  <!-- Per-side detail cards -->
  <div class="cd-grid" style="margin-top:18px;">
    <div class="portal-card">
      <h3 class="cd-label">Local · Our tower</h3>
      <h4 class="cd-label">RF environment <span style="color:var(--text-muted);">(last hour)</span></h4>
      <?= $rf_bars($rf_ap, $l['frequency_mhz'] !== null ? (int)$l['frequency_mhz'] : null,
                          $l['channel_width_mhz'] !== null ? (int)$l['channel_width_mhz'] : null) ?>
      <div class="cd-mini" style="border-top:0;padding-top:10px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
          <div>
            <div class="cd-label">Signal</div>
            <?= $chain_label(
              $l['signal_dbm'] !== null ? (int)$l['signal_dbm'] : null,
              $l['chain0_signal_dbm_local'] !== null ? (int)$l['chain0_signal_dbm_local'] : null,
              $l['chain1_signal_dbm_local'] !== null ? (int)$l['chain1_signal_dbm_local'] : null
            ) ?>
          </div>
          <div style="text-align:right;">
            <div class="cd-label">Noise floor</div>
            <strong style="font-size:18px;font-weight:400;color:var(--text);">
              <?= $l['noise_dbm'] !== null ? (int)$l['noise_dbm'] . ' dBm' : '—' ?>
            </strong>
          </div>
        </div>
      </div>
      <div class="cd-mini">
        <h4>Local RX data rate
          <strong style="font-weight:500;color:var(--text);"><?= $l['rx_mcs_index_local'] !== null ? (int)$l['rx_mcs_index_local'] . 'x' : '—' ?></strong>
          <?php if (!empty($l['modulation_label']) || !empty($l['modulation'])): ?>
            <span class="cd-tag"><?= $h($l['modulation_label'] ?: $l['modulation']) ?></span>
          <?php endif; ?>
        </h4>
        <?= $rate_ladder(
          $l['rx_mcs_index_local'] !== null ? (int)$l['rx_mcs_index_local'] : null,
          null,
          $l['max_mcs_index'] !== null ? (int)$l['max_mcs_index'] : 8,
          (string)($l['modulation_label'] ?: $l['modulation'] ?: '')
        ) ?>
      </div>
      <div class="cd-mini">
        <h4>Signal · Noise · Interference (24 h)</h4>
        <?= $signal_chart_svg($samples) ?>
        <div class="legend">
          <span><span class="legend-dot" style="background:#4477ff;"></span>Average signal
            <span style="color:var(--text);"><?= $l['signal_dbm'] !== null ? (int)$l['signal_dbm'] . ' dBm' : '—' ?></span></span>
          <span><span class="legend-dot" style="background:#e8a814;"></span>Interference + noise</span>
          <span><span class="legend-dot" style="background:#4ade80;"></span>Noise floor
            <span style="color:var(--text);"><?= $l['noise_dbm'] !== null ? (int)$l['noise_dbm'] . ' dBm' : '—' ?></span></span>
        </div>
      </div>
      <div class="cd-mini">
        <h4>CINR (dB)</h4>
        <?= $cinr_gauge($l['snr_db'] !== null ? (int)$l['snr_db'] : null) ?>
      </div>
    </div>

    <div class="portal-card">
      <h3 class="cd-label">Remote · Your CPE</h3>
      <h4 class="cd-label">RF environment <span style="color:var(--text-muted);">(last hour)</span></h4>
      <?= $rf_bars($rf_cpe, $l['frequency_mhz'] !== null ? (int)$l['frequency_mhz'] : null,
                            $l['channel_width_mhz'] !== null ? (int)$l['channel_width_mhz'] : null) ?>
      <div class="cd-mini" style="border-top:0;padding-top:10px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
          <div>
            <div class="cd-label">Signal</div>
            <?= $chain_label(
              $l['signal_dbm_remote'] !== null ? (int)$l['signal_dbm_remote'] : null,
              $l['chain0_signal_dbm_remote'] !== null ? (int)$l['chain0_signal_dbm_remote'] : null,
              $l['chain1_signal_dbm_remote'] !== null ? (int)$l['chain1_signal_dbm_remote'] : null
            ) ?>
          </div>
          <div style="text-align:right;">
            <div class="cd-label">Noise floor</div>
            <strong style="font-size:18px;font-weight:400;color:var(--text);">
              <?= $l['noise_dbm_remote'] !== null ? (int)$l['noise_dbm_remote'] . ' dBm' : '—' ?>
            </strong>
          </div>
        </div>
      </div>
      <div class="cd-mini">
        <h4>Remote RX data rate
          <strong style="font-weight:500;color:var(--text);"><?= $l['rx_mcs_index_remote'] !== null ? (int)$l['rx_mcs_index_remote'] . 'x' : '—' ?></strong>
        </h4>
        <?= $rate_ladder(
          $l['rx_mcs_index_remote'] !== null ? (int)$l['rx_mcs_index_remote'] : null,
          null,
          $l['max_mcs_index'] !== null ? (int)$l['max_mcs_index'] : 8,
          (string)($l['modulation_label'] ?: $l['modulation'] ?: '')
        ) ?>
      </div>
      <div class="cd-mini">
        <h4>CINR (dB)</h4>
        <?= $cinr_gauge($l['snr_db_remote'] !== null ? (int)$l['snr_db_remote'] : null) ?>
      </div>
    </div>
  </div>

  <!-- Customer-side "More details" panel (no sensitive AP info) -->
  <div class="portal-card" style="margin-top:18px;">
    <h3 class="cd-label">More details — your CPE</h3>
    <div class="cd-row"><span><b>Device model</b></span><span><?= $h($l['cpe_model'] ?: '—') ?></span></div>
    <div class="cd-row"><span><b>Version</b></span>     <span><?= $h($l['cpe_firmware'] ?: '—') ?></span></div>
    <div class="cd-row"><span><b>Network mode</b></span><span><?= $h(ucfirst($l['cpe_network_mode'] ?: 'unknown')) ?></span></div>
    <div class="cd-row"><span><b>Date</b></span>        <span><?= $h($fmt_dt($cpe_h_['polled_at'] ?? null)) ?></span></div>
    <div class="cd-row"><span><b>Uptime</b></span>      <span><?= $fmt_uptime($cpe_h_['uptime_seconds'] ?? null) ?></span></div>
    <?php
    $mem = $cpe_h_['mem_pct'] ?? null;
    $cpu = $cpe_h_['cpu_pct'] ?? null;
    ?>
    <div class="cd-row">
      <span><b>Memory</b></span>
      <span class="cd-meter" style="min-width:200px;">
        <span class="cd-bar cd-mem"><span style="width:<?= $mem !== null ? (int)$mem : 0 ?>%;"></span></span>
        <b><?= $mem !== null ? (int)$mem . ' %' : '—' ?></b>
      </span>
    </div>
    <div class="cd-row">
      <span><b>CPU</b></span>
      <span class="cd-meter" style="min-width:200px;">
        <span class="cd-bar cd-cpu"><span style="width:<?= $cpu !== null ? (int)$cpu : 0 ?>%;"></span></span>
        <b><?= $cpu !== null ? (int)$cpu . ' %' : '—' ?></b>
      </span>
    </div>

    <h3 class="cd-label" style="margin-top:18px;">Wireless</h3>
    <div class="cd-row"><span><b>Wireless mode</b></span><span><?= $h($l['wireless_mode'] ?: '—') ?> <span class="cd-tag">PtP</span></span></div>
    <div class="cd-row"><span><b>Connection time</b></span>
      <span><?= $fmt_uptime(
        $l['connection_time_seconds'] !== null ? (int)$l['connection_time_seconds']
          : ($l['uptime_seconds'] !== null ? (int)$l['uptime_seconds'] : null)
      ) ?></span></div>
    <div class="cd-row"><span><b>CINR</b></span>
      <span><?= $l['snr_db_remote'] !== null ? '+' . (int)$l['snr_db_remote'] . ' dB' : '—' ?></span></div>
    <div class="cd-row"><span><b>Distance</b></span>
      <span><?= $l['distance_km'] !== null
          ? number_format((float)$l['distance_km'], 2) . ' km · ' . $fmt_ft((float)$l['distance_km'])
          : '—' ?></span></div>
    <div class="cd-row"><span><b>Noise floor</b></span>
      <span><?= $l['noise_dbm_remote'] !== null ? (int)$l['noise_dbm_remote'] . ' dBm' : '—' ?></span></div>
    <div class="cd-row"><span><b>TX / RX bytes</b></span>
      <span><?= $fmt_bytes($l['tx_bytes']) ?> / <?= $fmt_bytes($l['rx_bytes']) ?></span></div>

    <?php if ($cpe_eth): ?>
      <h3 class="cd-label" style="margin-top:18px;">Ethernet</h3>
      <div class="cd-row"><span><b>LAN speed</b></span>
        <span><?= $cpe_eth['link_speed_mbps'] !== null ? number_format((float)$cpe_eth['link_speed_mbps'], 0) . ' Mbps-' . $h(ucfirst((string)$cpe_eth['duplex'])) : '—' ?></span></div>
      <div class="cd-row"><span><b>Cable SNR</b></span>
        <span><?= $cpe_eth['cable_snr_db'] !== null ? '+' . number_format((float)$cpe_eth['cable_snr_db'], 0) . ' dB' : '—' ?></span></div>
      <div class="cd-row"><span><b>Cable length</b></span>
        <span><?= $cpe_eth['cable_length_m'] !== null
            ? number_format((float)$cpe_eth['cable_length_m'] / 0.3048, 0) . ' ft · '
              . number_format((float)$cpe_eth['cable_length_m'], 0) . ' m'
            : '—' ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Speed tests -->
  <div class="portal-card" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
      <h3 class="cd-label">Recent speed tests</h3>
      <small class="muted">last <?= count($tests) ?></small>
    </div>
    <?php if ($tests): ?>
      <?php $latest = $tests[0]; ?>
      <div class="cd-row"><span><b>Latest</b></span>
        <span>
          <strong style="color:var(--accent);"><?= number_format((float)($latest['mbps_down'] ?? 0), 1) ?> Mbps</strong>
          <span class="muted">down</span>
          ·
          <?= number_format((float)($latest['mbps_up'] ?? 0), 1) ?> Mbps <span class="muted">up</span>
          <span class="muted"> · <?= $h($latest['polled_at']) ?></span>
        </span>
      </div>
      <?php
      $w = 720; $hh = 36;
      $vals = array_reverse(array_map(fn ($r) => (float)($r['mbps_down'] ?? 0), $tests));
      $maxv = max(1.0, max($vals));
      $bars = '';
      foreach ($vals as $i => $v) {
          $bx = ($i + 0.1) * ($w / max(1, count($vals)));
          $bw = ($w / max(1, count($vals))) * 0.8;
          $bh = ($v / $maxv) * ($hh - 4);
          $bars .= '<rect x="' . round($bx, 1) . '" y="' . round($hh - $bh - 2, 1)
                . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="2" fill="#05DAFD"/>';
      }
      ?>
      <svg viewBox="0 0 <?= $w ?> <?= $hh ?>" width="100%" preserveAspectRatio="none" style="display:block;margin-top:10px;">
        <rect x="0" y="0" width="<?= $w ?>" height="<?= $hh ?>" fill="rgba(5,218,253,0.04)" rx="4"/>
        <?= $bars ?>
      </svg>
    <?php else: ?>
      <small class="muted">No speed tests on file. Run one below.</small>
    <?php endif; ?>
    <form method="post" style="margin-top:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="speedtest">
      <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
      <button class="btn btn-primary btn-sm" type="submit">Run a speed test</button>
      <small class="muted">Limited to 3 per hour.</small>
    </form>
  </div>

  <?php endforeach; ?>

  <?php if ($alerts): ?>
    <div class="portal-card" style="margin-top:18px;border-left:3px solid #e8a814;">
      <h2>We noticed</h2>
      <p class="muted">Our system has flagged the following on your link. A technician has already been notified.</p>
      <ul>
        <?php foreach ($alerts as $a): ?>
          <li>
            <strong><?= $h(str_replace('_', ' ', $a['kind'])) ?></strong>
            &nbsp; <small class="muted">since <?= $h($a['opened_at']) ?></small>
            <br><small><?= $h($a['notes']) ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
