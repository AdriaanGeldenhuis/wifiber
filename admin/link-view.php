<?php
/**
 * Live link dashboard — the UISP-style "Link" tab.
 *
 * One page per wireless_links row. Server-rendered SVG charts (no JS
 * chart library) following the same pattern as device-view.php.
 *
 * Visually mirrors the UISP "Link" details screen:
 *   - Top banner with both endpoints, distance pill, throughput dials,
 *     airtime, TX power, vendor/model.
 *   - Map / Link / Fresnel tabs.
 *   - Per-side cards: RF environment heat-bar, signal min/max delta,
 *     RX data-rate ladder (1x..NX with current + expected highlighted),
 *     24h signal/noise/interference chart, CINR gauge.
 *   - More-details panels: device model, version, network mode, sync
 *     timestamps, uptime, memory/CPU bars, wireless config (mode, TDD
 *     framing, security, distance, CINR, noise floor, TX/RX bytes,
 *     connection time, remote IP), and ethernet diagnostics.
 *   - Active health alerts, recent speed tests, queue iperf3 form.
 *
 * Data sources:
 *   wireless_links             current state including the per-chain
 *                              signal levels, MCS index, TDD framing,
 *                              connection time, remote IP.
 *   link_health_samples        24h time-series for the signal/noise chart.
 *   rf_environment_samples     last hour, per-frequency RSSI bars.
 *   ethernet_health            latest sample → cable SNR + length + duplex.
 *   device_health (ap & cpe)   CPU / memory / uptime bars.
 *   link_alerts                active alerts banner above the tabs.
 *   link_speedtests            recent iperf3 results + queue form.
 */
$page_title = 'Wireless link';
$active_key = 'links';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/diagnostics.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'speedtest') {
    require_csrf();
    $link_id = (int)($_POST['link_id'] ?? 0);
    $target  = trim((string)($_POST['target_ip'] ?? ''));
    if ($link_id > 0 && $target !== '') {
        $job = diagnostic_job_enqueue('iperf3', 'link', $link_id, (int)$user['id'], [
            'target_ip'  => $target,
            'duration_s' => 10,
        ]);
        audit_log('diagnostic.queued', [
            'target_type' => 'wireless_link', 'target_id' => $link_id,
            'meta' => ['job_id' => $job, 'kind' => 'iperf3'],
        ]);
        flash('success', "Speed-test queued (job #$job). Refresh in ~30s.");
    } else {
        flash('error', 'target_ip required.');
    }
    header('Location: /admin/link-view.php?id=' . $link_id);
    exit;
}

$id   = (int)($_GET['id'] ?? 0);
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

$tab     = (string)($_GET['tab'] ?? 'link');
$rftab_l = (string)($_GET['rfl']  ?? 'sni'); // sni | iso (per-side toggle, local)
$rftab_r = (string)($_GET['rfr']  ?? 'sni'); // sni | iso (per-side toggle, remote)

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
    $r_m_full = 8.657 * sqrt($dist_km / $freq_ghz);
    $r_m_60   = $r_m_full * 0.6;
    $bulge = ($dist_km * $dist_km) / (8 * 1.33);
    $ap_h_m  = $ap_site['height_m']  ?? null;
    $cpe_h_m = $cpe_site['height_m'] ?? null;
    $needed_height = ($ap_h_m !== null && $cpe_h_m !== null)
        ? ($r_m_60 + $bulge)
        : null;
    $clearance = ($ap_h_m !== null && $cpe_h_m !== null)
        ? min((float)$ap_h_m, (float)$cpe_h_m) - $needed_height
        : null;
    $fresnel = [
        'distance_km'   => $dist_km,
        'frequency_ghz' => $freq_ghz,
        'r_full_m'      => $r_m_full,
        'r_60_m'        => $r_m_60,
        'earth_bulge_m' => $bulge,
        'ap_height_m'   => $ap_h_m,
        'cpe_height_m'  => $cpe_h_m,
        'needed_m'      => $needed_height,
        'clearance_m'   => $clearance,
    ];
}

$samples = wireless_link_recent_samples($id, 288); // 24h at 5min cadence

$alerts = pdo()->prepare(
    "SELECT * FROM link_alerts
      WHERE link_id = ? AND resolved_at IS NULL
      ORDER BY opened_at DESC"
);
$alerts->execute([$id]);
$alerts = $alerts->fetchAll();
$rf_ap   = rf_environment_recent((int)$link['ap_device_id'], 60);
$rf_cpe  = $link['cpe_device_id'] ? rf_environment_recent((int)$link['cpe_device_id'], 60) : [];

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

/* ---------- Chain signal delta — "-48 (-51/-52) Δ1 dBm" ---------- */
$chain_label = function (?int $main, ?int $c0, ?int $c1) {
    if ($main === null && $c0 === null && $c1 === null) {
        return '<strong class="lv-bigstat">—</strong>';
    }
    $delta = ($c0 !== null && $c1 !== null) ? abs($c0 - $c1) : null;
    $chain = ($c0 !== null || $c1 !== null)
        ? '(' . ($c0 ?? '—') . ' / ' . ($c1 ?? '—') . ')'
        : '';
    $delta_label = $delta !== null ? ' Δ' . $delta : '';
    return '<strong class="lv-bigstat">' . ($main !== null ? (int)$main : '—') . '</strong>'
         . ' <span class="lv-suffix">' . $chain . $delta_label . ' dBm</span>';
};

/* ---------- Signal severity → colour pill (red/amber/cyan) ---------- */
$signal_colour = function (?int $sig) {
    if ($sig === null) return '#6b7480';
    if ($sig >= -55) return '#4ade80';
    if ($sig >= -67) return '#05DAFD';
    if ($sig >= -75) return '#e8a814';
    return '#ff5470';
};
$snr_colour = function (?int $snr) {
    if ($snr === null) return '#6b7480';
    if ($snr >= 25) return '#4ade80';
    if ($snr >= 15) return '#05DAFD';
    if ($snr >= 10) return '#e8a814';
    return '#ff5470';
};

/* ---------- SVG: signal / noise / interference (24h) ---------- */
$signal_chart_svg = function (array $samples, string $side) use ($h): string {
    if (!$samples) return '<small class="muted">No samples yet — wait for the next poll.</small>';
    $w = 720; $hh = 170; $pad_l = 36; $pad_b = 22; $pad_t = 8;
    $rows = array_reverse($samples);
    $now  = time();
    $xs = []; $sigs = []; $noise = []; $intr = [];
    foreach ($rows as $r) {
        $t = strtotime((string)$r['polled_at']) ?: $now;
        $xs[] = $t;
        $sk = $side === 'remote' ? 'signal_remote_dbm' : 'signal_local_dbm';
        $nk = $side === 'remote' ? 'noise_remote_dbm'  : 'noise_local_dbm';
        $ak = $side === 'remote' ? 'airtime_remote_pct' : 'airtime_local_pct';
        $sigs[]  = $r[$sk] !== null ? (int)$r[$sk] : null;
        $noise[] = $r[$nk] !== null ? (int)$r[$nk] : null;
        $a = (float)($r[$ak] ?? 0);
        $intr[]  = $r[$nk] !== null ? (int)$r[$nk] + ($a > 50 ? 6 : 2) : null;
    }
    $tmin = min($xs); $tmax = max($xs); $tspan = max(1, $tmax - $tmin);
    $ymin = -110; $ymax = -40;
    $sx = fn ($t) => $pad_l + ($t - $tmin) / $tspan * ($w - $pad_l - 6);
    $sy = fn ($v) => $pad_t + ($ymax - $v) / ($ymax - $ymin) * ($hh - $pad_t - $pad_b);
    $line = function (array $vals, string $colour, float $width = 1.5) use ($xs, $sx, $sy) {
        $d = '';
        $started = false;
        foreach ($vals as $i => $v) {
            if ($v === null) { $started = false; continue; }
            $d .= ($started ? 'L' : 'M') . round($sx($xs[$i]), 1) . ',' . round($sy($v), 1) . ' ';
            $started = true;
        }
        return $d === '' ? '' : '<path d="' . $d . '" fill="none" stroke="' . $colour . '" stroke-width="' . $width . '"/>';
    };
    /* Filled noise-floor band so it reads at a glance like UISP. */
    $band = '';
    foreach ($noise as $i => $v) {
        if ($v === null) continue;
        $band .= ($i === 0 ? 'M' : 'L') . round($sx($xs[$i]), 1) . ',' . round($sy($v), 1) . ' ';
    }
    if ($band !== '') {
        $first = $sx($xs[0]); $last = $sx($xs[count($xs) - 1]);
        $bottom = $sy(-110);
        $band = '<path d="' . $band . 'L' . round($last, 1) . ',' . round($bottom, 1)
              . 'L' . round($first, 1) . ',' . round($bottom, 1) . 'Z" fill="rgba(74,222,128,0.10)"/>';
    }
    $grid = '';
    foreach ([-50, -60, -70, -80, -90, -100, -110] as $y) {
        $py = $sy($y);
        $grid .= '<line x1="' . $pad_l . '" y1="' . $py . '" x2="' . ($w - 6) . '" y2="' . $py
              . '" stroke="rgba(255,255,255,0.05)" stroke-width="1"/>';
        $grid .= '<text x="' . ($w - 4) . '" y="' . ($py + 3) . '" font-size="9" fill="#6b7480" text-anchor="end">' . $y . '</text>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . '<rect x="' . $pad_l . '" y="' . $pad_t . '" width="' . ($w - $pad_l - 6) . '" height="' . ($hh - $pad_t - $pad_b)
        . '" fill="rgba(255,255,255,0.02)"/>'
        . $grid
        . $band
        . $line($sigs,  '#4477ff', 1.7)
        . $line($intr,  '#e8a814', 1.4)
        . $line($noise, '#4ade80', 1.4)
        . '</svg>';
};

/* ---------- SVG: capacity / throughput sparkline ---------- */
$tput_chart_svg = function (array $samples, string $side): string {
    if (!$samples) return '<small class="muted">No throughput history yet.</small>';
    $w = 720; $hh = 170; $pad_l = 36; $pad_b = 22; $pad_t = 8;
    $rows = array_reverse($samples);
    $xs = []; $tput = []; $cap = [];
    foreach ($rows as $r) {
        $t = strtotime((string)$r['polled_at']) ?: time();
        $xs[] = $t;
        $tk = $side === 'remote' ? 'throughput_remote_mbps' : 'throughput_local_mbps';
        $ck = $side === 'remote' ? 'capacity_remote_mbps'   : 'capacity_local_mbps';
        $tput[] = $r[$tk] !== null ? (float)$r[$tk] : null;
        $cap[]  = $r[$ck] !== null ? (float)$r[$ck] : null;
    }
    $tmin = min($xs); $tmax = max($xs); $tspan = max(1, $tmax - $tmin);
    $ymax = max(1.0, max(array_map(fn ($v) => $v ?? 0, $cap)) * 1.1);
    $sx = fn ($t) => $pad_l + ($t - $tmin) / $tspan * ($w - $pad_l - 6);
    $sy = fn ($v) => $pad_t + (1 - $v / $ymax) * ($hh - $pad_t - $pad_b);
    $line = function (array $vals, string $colour, float $width, bool $dash = false) use ($xs, $sx, $sy) {
        $d = ''; $started = false;
        foreach ($vals as $i => $v) {
            if ($v === null) { $started = false; continue; }
            $d .= ($started ? 'L' : 'M') . round($sx($xs[$i]), 1) . ',' . round($sy($v), 1) . ' ';
            $started = true;
        }
        return $d === '' ? '' : '<path d="' . $d . '" fill="none" stroke="' . $colour
            . '" stroke-width="' . $width . '"' . ($dash ? ' stroke-dasharray="4 3"' : '') . '/>';
    };
    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $v = $ymax * $i / 4;
        $py = $sy($v);
        $grid .= '<line x1="' . $pad_l . '" y1="' . $py . '" x2="' . ($w - 6) . '" y2="' . $py
              . '" stroke="rgba(255,255,255,0.05)" stroke-width="1"/>';
        $grid .= '<text x="' . ($w - 4) . '" y="' . ($py + 3) . '" font-size="9" fill="#6b7480" text-anchor="end">'
              . number_format($v, 0) . '</text>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
        . '<rect x="' . $pad_l . '" y="' . $pad_t . '" width="' . ($w - $pad_l - 6) . '" height="' . ($hh - $pad_t - $pad_b)
        . '" fill="rgba(5,218,253,0.04)"/>'
        . $grid
        . $line($cap,  '#05DAFD', 1.4, true)
        . $line($tput, '#4477ff', 1.7, false)
        . '</svg>';
};

/* ---------- SVG: RF environment heat-bar ---------- */
$rf_bars = function (array $rf, ?int $centre_mhz, ?int $width_mhz) use ($h): string {
    if (!$rf) return '<small class="muted">No RF scan samples yet.</small>';
    $w = 720; $bar_h = 56;
    $freqs = array_column($rf, 'freq_mhz');
    $fmin = min($freqs); $fmax = max($freqs);
    $fspan = max(1, $fmax - $fmin);
    $bars = '';
    foreach ($rf as $r) {
        $x = ($r['freq_mhz'] - $fmin) / $fspan * ($w - 2);
        $rssi = (int)$r['rssi_dbm']; // -100..-30
        $intensity = max(0, min(1, (-30 - $rssi) / 70));
        // Cyan accent intensity: low signal = pale, hot signal = saturated.
        $alpha  = 0.20 + 0.80 * (1 - $intensity);
        $colour = sprintf('rgba(5,218,253,%.2f)', $alpha);
        $bars .= '<rect x="' . round($x, 1) . '" y="0" width="3" height="' . $bar_h . '" fill="' . $colour . '"/>';
    }
    /* Highlight the operating channel block on top. */
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

/* ---------- CINR gauge (0-40 dB scale, segmented like the screenshot) ---------- */
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
        . $segs
        . '</svg>'
        . '<div style="display:flex;justify-content:space-between;font-size:10px;color:#6b7480;margin-top:2px;">'
        . '<span>0</span><span>10</span><span>20</span><span>30</span><span>40</span></div>';
};

/* ---------- RX data-rate ladder (1x..NX, like UISP "256QAM MIMO") ---------- */
$rate_ladder = function (?int $current, ?int $expected, ?int $max, string $modulation) {
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
                : ($i === 1 ? 'rgba(255,84,112,0.40)'
                : ($i === 2 ? 'rgba(232,168,20,0.35)'
                : 'rgba(255,255,255,0.05)'))));
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
        . '<span>1X</span><span>2X</span><span>3X</span><span>4X</span><span>5X</span>'
        . '<span>6X</span><span>7X</span><span>' . $max . 'X' . ($modulation !== '' ? ' · ' . htmlspecialchars($modulation) : '') . '</span>'
        . '</div>';
};

/* ---------- Health pill colour ---------- */
$health_pill = function (?int $score): string {
    if ($score === null) return '<span class="lv-pill" style="background:#2a3340;">no data</span>';
    [$bg, $label] = match (true) {
        $score >= 75 => ['#4ade80', 'good'],
        $score >= 50 => ['#e8a814', 'fair'],
        default      => ['#ff5470', 'poor'],
    };
    return '<span class="lv-pill" style="background:' . $bg . ';color:#001218;">' . $score . ' · ' . $label . '</span>';
};

$status_pill = function (?string $status): string {
    $status = $status ?: 'unknown';
    $bg = match ($status) {
        'online'  => '#4ade80',
        'offline' => '#ff5470',
        default   => '#6b7480',
    };
    return '<span class="lv-pill" style="background:' . $bg . ';color:#001218;">' . $status . '</span>';
};

$frequency_label = $link['frequency_mhz'] !== null
    ? (int)$link['frequency_mhz'] . ' MHz'
      . ($link['channel_width_mhz'] !== null ? ' · ' . (int)$link['channel_width_mhz'] . ' MHz wide' : '')
    : '—';

$tdd = $link['tdd_framing'] ?: ($link['sector_tdd_framing'] ?? '');
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
  .lv-dial small  { display:block; font-size:8.5px; color:var(--text-muted); text-align:center; line-height:1.05; text-transform:uppercase; letter-spacing:.04em; }
  .lv-dial b      { font-size:18px; font-weight:500; color:var(--text); margin:2px 0; font-variant-numeric:tabular-nums; }
  .lv-dial .lv-dial-unit { font-size:9px; color:var(--text-muted); }

  .lv-endpoint h4 { margin:0 0 2px; font-size:14px; font-weight:600; color:var(--text); }
  .lv-endpoint .lv-label { display:block; }

  .legend      { display:flex; gap:18px; flex-wrap:wrap; font-size:11.5px; color:var(--text-dim); padding:10px 0 0; }
  .legend-dot  { display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:middle; }

  .lv-meter    { display:flex; align-items:center; gap:10px; }
  .lv-meter .lv-bar { flex:1; height:6px; border-radius:3px; background:rgba(255,255,255,0.05); overflow:hidden; }
  .lv-meter .lv-bar > span { display:block; height:100%; background:var(--accent); border-radius:3px; }
  .lv-meter .lv-mem  > span { background:#a25cf0; }
  .lv-meter .lv-cpu  > span { background:#05DAFD; }
  .lv-meter b  { font-weight:500; color:var(--text); font-variant-numeric:tabular-nums; min-width:42px; text-align:right; }

  .lv-section-tabs { display:flex; gap:4px; margin:14px 0 8px; }
  .lv-section-tabs a {
    padding:5px 11px; border-radius:6px; font-size:10.5px; letter-spacing:.05em; text-transform:uppercase; font-weight:600;
    color:var(--text-muted); background:var(--bg-elev); border:1px solid var(--border);
  }
  .lv-section-tabs a.active { color:var(--accent); border-color:var(--accent); background:var(--accent-soft); }

  .lv-actions  { display:flex; gap:8px; justify-content:center; margin:14px 0 4px; }
  .lv-mini-section { padding-top:14px; border-top:1px solid var(--border); margin-top:14px; }
  .lv-mini-section h4 { font-size:11px; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); margin:0 0 8px; font-weight:600; }

  .lv-grid-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
  .lv-grid-hdr h3 { margin:0; }
</style>

<div class="lv-banner">
  <div class="lv-side lv-endpoint">
    <span class="lv-icon" title="Local AP">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 12a7 7 0 0 1 14 0"/><path d="M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>
      </svg>
    </span>
    <div>
      <div class="lv-label">Local · AP</div>
      <h4><?= $h($link['ap_name']) ?> <?= $status_pill($link['ap_status'] ?? null) ?></h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?= $h(ucfirst((string)($link['ap_vendor'] ?? ''))) ?> · <?= $h($link['ap_model']) ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        TX power <?= $link['tx_power_dbm_local'] !== null ? (int)$link['tx_power_dbm_local'] . ' dBm' : '—' ?>
        &nbsp;·&nbsp; <?= $h($link['ap_mgmt_ip'] ?? '—') ?>
      </div>
    </div>
    <div class="lv-dial" title="Throughput / capacity (Mbps)">
      <small>Throughput<br>Capacity</small>
      <b><?= $link['capacity_local_mbps'] !== null ? number_format((float)$link['capacity_local_mbps'], 2) : '—' ?></b>
      <span class="lv-dial-unit">Mbps</span>
    </div>
  </div>

  <div class="lv-mid">
    <div class="lv-arrow">
      <span class="lv-arrow-line"></span>
      <span class="lv-banner-distance">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11V7a4 4 0 0 0-8 0v4"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
        <strong><?= $fmt_ft($link['distance_km'] !== null ? (float)$link['distance_km'] : null) ?></strong>
      </span>
      <span class="lv-arrow-line"></span>
    </div>
    <div class="lv-airtime">
      <b>Airtime</b>
      <?= $link['airtime_local_pct']  !== null ? number_format((float)$link['airtime_local_pct'], 1)  . '%' : '—' ?>
      &nbsp;·&nbsp;
      <?= $link['airtime_remote_pct'] !== null ? number_format((float)$link['airtime_remote_pct'], 1) . '%' : '—' ?>
    </div>
    <div class="lv-airtime"><b>Frequency</b> <?= $h($frequency_label) ?> &nbsp;·&nbsp; <b>Mode</b> <?= $h($link['wireless_mode'] ?? '—') ?></div>
    <div class="lv-airtime"><b>Health</b> <?= $health_pill($link['health_score']) ?></div>
  </div>

  <div class="lv-side lv-end lv-endpoint">
    <div class="lv-dial" title="Throughput / capacity (Mbps)">
      <small>Throughput<br>Capacity</small>
      <b><?= $link['capacity_remote_mbps'] !== null ? number_format((float)$link['capacity_remote_mbps'], 2) : '—' ?></b>
      <span class="lv-dial-unit">Mbps</span>
    </div>
    <div style="text-align:right;">
      <div class="lv-label">Remote · CPE</div>
      <h4><?= $h($link['cpe_name'] ?? '—') ?>
        <?php if ($link['cpe_device_id']): ?><?= $status_pill($link['cpe_status'] ?? null) ?><?php endif; ?>
      </h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?= $h(ucfirst((string)($link['cpe_vendor'] ?? ''))) ?> · <?= $h($link['cpe_model'] ?? '') ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        TX power <?= $link['tx_power_dbm_remote'] !== null ? (int)$link['tx_power_dbm_remote'] . ' dBm' : '—' ?>
        &nbsp;·&nbsp; <?= $h($link['remote_ip'] ?: ($link['cpe_mgmt_ip'] ?? '—')) ?>
      </div>
    </div>
    <span class="lv-icon" title="Remote CPE">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <ellipse cx="12" cy="12" rx="9" ry="4"/><path d="M3 12c0 4 3 7 9 7s9-3 9-7"/><path d="M12 16v3"/>
      </svg>
    </span>
  </div>
</div>

<div class="lv-tabs">
  <a class="lv-tab" href="/admin/map.php?focus=link&amp;id=<?= (int)$link['id'] ?>">Map</a>
  <a class="lv-tab <?= $tab === 'link'    ? 'active' : '' ?>" href="?id=<?= (int)$link['id'] ?>&amp;tab=link">Link</a>
  <a class="lv-tab <?= $tab === 'fresnel' ? 'active' : '' ?>" href="?id=<?= (int)$link['id'] ?>&amp;tab=fresnel">Fresnel</a>
  <a class="lv-tab" href="/admin/link-history.php?id=<?= (int)$link['id'] ?>&amp;days=7">History 7d</a>
  <a class="lv-tab" href="/admin/link-history.php?id=<?= (int)$link['id'] ?>&amp;days=30">30d</a>
</div>

<?php if ($alerts): ?>
<div class="portal-card" style="border-left:3px solid var(--danger);">
  <h3 class="lv-label" style="color:var(--danger);">Active health alerts</h3>
  <?php foreach ($alerts as $a): ?>
    <div class="lv-row">
      <span><b><?= $h(str_replace('_', ' ', $a['kind'])) ?></b>
        <span class="lv-pill" style="background:<?= $a['severity'] === 'crit' ? 'var(--danger)' : '#e8a814' ?>;">
          <?= $h($a['severity']) ?>
        </span>
      </span>
      <span class="muted small"><?= $h($a['notes']) ?> · since <?= $h($a['opened_at']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'fresnel'): ?>
<div class="portal-card">
  <h2>Fresnel zone &amp; line-of-sight</h2>
  <?php if (!$fresnel): ?>
    <small class="muted">Need both endpoint sites with lat/lng + a measured distance to compute. Open the AP and CPE devices and confirm they are attached to a site with coordinates.</small>
  <?php else: ?>
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
        <span style="color:<?= $fresnel['clearance_m'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600;">
          <?= ($fresnel['clearance_m'] >= 0 ? '+' : '') . number_format($fresnel['clearance_m'], 2) ?> m
          <?php if ($fresnel['clearance_m'] < 0): ?>
            <span class="lv-pill" style="background:var(--danger);margin-left:6px;">obstructed</span>
          <?php endif; ?>
        </span></div>
    <?php else: ?>
      <small class="muted">Add a height_m on both endpoint sites to compute clearance.</small>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php else: /* main "Link" tab */ ?>

<div class="lv-grid">
  <?php
  /* Per-side panel — rendered twice (local / remote) with the same layout. */
  $render_side = function (
      string $side,
      string $title,
      array  $rf,
      ?int   $signal,
      ?int   $noise,
      ?int   $snr,
      ?int   $chain0,
      ?int   $chain1,
      ?int   $rate_idx,
      ?int   $expected_idx,
      ?int   $max_idx,
      string $modulation,
      ?float $rx_rate_mbps,
      ?float $expected_rate_mbps,
      string $section_tab
  ) use ($link, $samples, $signal_chart_svg, $tput_chart_svg, $rf_bars, $cinr_gauge, $rate_ladder, $chain_label, $signal_colour, $snr_colour, $h) {
      ?>
      <div class="portal-card">
        <div class="lv-grid-hdr">
          <h3 class="lv-label" style="font-size:11px;"><?= $h($title) ?></h3>
          <span class="lv-tag"><?= $signal !== null ? (int)$signal . ' dBm' : '—' ?></span>
        </div>

        <h4 class="lv-label">RF environment <span style="color:var(--text-muted);">(last hour)</span></h4>
        <?= $rf_bars($rf, $link['frequency_mhz'] !== null ? (int)$link['frequency_mhz'] : null,
                          $link['channel_width_mhz'] !== null ? (int)$link['channel_width_mhz'] : null) ?>

        <div class="lv-mini-section" style="border-top:0;padding-top:10px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;">
            <div>
              <div class="lv-label">Signal</div>
              <?= $chain_label($signal, $chain0, $chain1) ?>
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
              <?= $h(ucfirst($side)) ?> RX data rate
              <strong style="font-weight:500;color:var(--text);"><?= $rate_idx !== null ? $rate_idx . 'x' : '—' ?></strong>
              <?php if ($modulation !== ''): ?><span class="lv-tag"><?= $h($modulation) ?></span><?php endif; ?>
            </h4>
            <span class="lv-label">
              Expected rate
              <strong style="color:var(--accent);"><?= $expected_idx !== null ? $expected_idx . 'X' : '—' ?></strong>
            </span>
          </div>
          <?= $rate_ladder($rate_idx, $expected_idx, $max_idx, $modulation) ?>
        </div>

        <div class="lv-section-tabs">
          <a href="?id=<?= (int)$link['id'] ?>&amp;tab=link&amp;<?= $side === 'remote' ? 'rfr' : 'rfl' ?>=iso<?= $side === 'remote' ? '&amp;rfl=' . urlencode($_GET['rfl'] ?? 'sni') : '&amp;rfr=' . urlencode($_GET['rfr'] ?? 'sni') ?>"
             class="<?= $section_tab === 'iso' ? 'active' : '' ?>">Isolated capacity / throughput</a>
          <a href="?id=<?= (int)$link['id'] ?>&amp;tab=link&amp;<?= $side === 'remote' ? 'rfr' : 'rfl' ?>=sni<?= $side === 'remote' ? '&amp;rfl=' . urlencode($_GET['rfl'] ?? 'sni') : '&amp;rfr=' . urlencode($_GET['rfr'] ?? 'sni') ?>"
             class="<?= $section_tab === 'sni' ? 'active' : '' ?>">Signal, noise &amp; interference</a>
        </div>

        <?php if ($section_tab === 'iso'): ?>
          <?= $tput_chart_svg($samples, $side) ?>
          <div class="legend">
            <span><span class="legend-dot" style="background:#4477ff;"></span>Throughput
              <span style="color:var(--text);"><?= $rx_rate_mbps !== null ? number_format((float)$rx_rate_mbps, 1) . ' Mbps' : '—' ?></span></span>
            <span><span class="legend-dot" style="background:#05DAFD;border:1px dashed #05DAFD;"></span>Capacity
              <span style="color:var(--text);">
                <?php
                  $cap = $side === 'remote' ? $link['capacity_remote_mbps'] : $link['capacity_local_mbps'];
                  echo $cap !== null ? number_format((float)$cap, 1) . ' Mbps' : '—';
                ?>
              </span></span>
          </div>
        <?php else: ?>
          <?= $signal_chart_svg($samples, $side) ?>
          <div class="legend">
            <span><span class="legend-dot" style="background:#4477ff;"></span>Average signal
              <span style="color:var(--text);"><?= $signal !== null ? (int)$signal . ' dBm' : '—' ?></span></span>
            <span><span class="legend-dot" style="background:#e8a814;"></span>Interference + noise
              <span style="color:var(--text);"><?= $noise !== null ? (int)$noise + 4 . ' dBm' : '—' ?></span></span>
            <span><span class="legend-dot" style="background:#4ade80;"></span>Noise floor
              <span style="color:var(--text);"><?= $noise !== null ? (int)$noise . ' dBm' : '—' ?></span></span>
          </div>
        <?php endif; ?>

        <div class="lv-mini-section">
          <h4>CINR (dB)</h4>
          <?= $cinr_gauge($snr) ?>
        </div>
      </div>
      <?php
  };

  $render_side(
      'local', 'Local device', $rf_ap,
      $link['signal_dbm']  !== null ? (int)$link['signal_dbm']  : null,
      $link['noise_dbm']   !== null ? (int)$link['noise_dbm']   : null,
      $link['snr_db']      !== null ? (int)$link['snr_db']      : null,
      $link['chain0_signal_dbm_local'] !== null ? (int)$link['chain0_signal_dbm_local'] : null,
      $link['chain1_signal_dbm_local'] !== null ? (int)$link['chain1_signal_dbm_local'] : null,
      $link['rx_mcs_index_local']      !== null ? (int)$link['rx_mcs_index_local']      : null,
      $link['expected_rate_mbps']      !== null && $link['rx_rate_mbps'] !== null && $link['expected_rate_mbps'] > 0
        ? (int)$link['max_mcs_index'] : null,
      $link['max_mcs_index']           !== null ? (int)$link['max_mcs_index'] : 8,
      (string)($link['modulation_label'] ?: $link['modulation'] ?: ''),
      $link['rx_rate_mbps']      !== null ? (float)$link['rx_rate_mbps']      : null,
      $link['expected_rate_mbps']!== null ? (float)$link['expected_rate_mbps']: null,
      $rftab_l
  );

  $render_side(
      'remote', 'Remote device', $rf_cpe,
      $link['signal_dbm_remote'] !== null ? (int)$link['signal_dbm_remote'] : null,
      $link['noise_dbm_remote']  !== null ? (int)$link['noise_dbm_remote']  : null,
      $link['snr_db_remote']     !== null ? (int)$link['snr_db_remote']     : null,
      $link['chain0_signal_dbm_remote'] !== null ? (int)$link['chain0_signal_dbm_remote'] : null,
      $link['chain1_signal_dbm_remote'] !== null ? (int)$link['chain1_signal_dbm_remote'] : null,
      $link['rx_mcs_index_remote']      !== null ? (int)$link['rx_mcs_index_remote']      : null,
      $link['expected_rate_mbps']       !== null && $link['tx_rate_mbps']  !== null && $link['expected_rate_mbps'] > 0
        ? (int)$link['max_mcs_index'] : null,
      $link['max_mcs_index']            !== null ? (int)$link['max_mcs_index'] : 8,
      (string)($link['modulation_label'] ?: $link['modulation'] ?: ''),
      $link['tx_rate_mbps']      !== null ? (float)$link['tx_rate_mbps'] : null,
      $link['expected_rate_mbps']!== null ? (float)$link['expected_rate_mbps'] : null,
      $rftab_r
  );
  ?>
</div>

<?php
/* ---------- More-details cards (local / remote, mirrored) ---------- */
$detail_card = function (
    string $title,
    string $action_label,
    string $action_href,
    ?array $dh,
    ?array $eth,
    string $device_model,
    string $firmware,
    ?string $network_mode,
    ?string $polled_at,
    ?string $last_seen,
    string $wireless_mode,
    string $security,
    ?int   $distance_km_x100,
    ?int   $noise_dbm,
    ?int   $snr_db,
    string $tdd_framing,
    string $ssid,
    ?int   $tx_bytes,
    ?int   $rx_bytes,
    ?int   $connection_seconds,
    string $remote_ip,
    string $mac,
    bool   $is_remote
) use ($h, $fmt_uptime, $fmt_bytes, $fmt_dt) {
    ?>
    <div class="portal-card">
      <div class="lv-grid-hdr">
        <h3 class="lv-label" style="font-size:11px;"><?= $h($title) ?></h3>
        <a class="btn btn-ghost btn-sm" href="<?= $h($action_href) ?>"><?= $h($action_label) ?> ↗</a>
      </div>

      <div class="lv-row"><span><b>Device model</b></span><span><?= $h($device_model) ?: '—' ?></span></div>
      <div class="lv-row"><span><b>Version</b></span>     <span><?= $h($firmware) ?: '—' ?></span></div>
      <div class="lv-row"><span><b>Network mode</b></span><span><?= $h(ucfirst($network_mode ?: 'unknown')) ?></span></div>
      <div class="lv-row"><span><b>Date <?= $is_remote ? '' : '(synced)' ?></b></span><span><?= $h($fmt_dt($polled_at)) ?></span></div>
      <div class="lv-row"><span><b>UNMS connected</b></span><span><?= $h($fmt_dt($last_seen ?: $polled_at)) ?></span></div>
      <div class="lv-row"><span><b>Uptime</b></span>      <span><?= $fmt_uptime($dh['uptime_seconds'] ?? null) ?></span></div>

      <?php
      $mem = $dh['mem_pct'] ?? null;
      $cpu = $dh['cpu_pct'] ?? null;
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
      <div class="lv-row"><span><b>Wireless mode</b></span><span><?= $h($wireless_mode ?: '—') ?><?= $is_remote ? '' : ' <span class="lv-tag">PtP</span>' ?></span></div>
      <?php if (!$is_remote): ?>
        <div class="lv-row"><span><b>SSID</b></span><span><?= $h($ssid ?: '—') ?></span></div>
        <div class="lv-row"><span><b>Security</b></span><span><?= $h(strtoupper($security ?: 'open')) ?></span></div>
        <div class="lv-row"><span><b>TDD framing</b></span><span><?= $h($tdd_framing ?: '—') ?></span></div>
      <?php else: ?>
        <div class="lv-row"><span><b>Connection time</b></span><span><?= $fmt_uptime($connection_seconds) ?></span></div>
        <div class="lv-row"><span><b>Remote IP</b></span><span><?= $h($remote_ip ?: '—') ?></span></div>
      <?php endif; ?>
      <div class="lv-row"><span><b>CINR</b></span>
        <span><?= $snr_db !== null ? '+' . (int)$snr_db . ' dB' : '—' ?></span></div>
      <div class="lv-row"><span><b>Distance</b></span>
        <span>
          <?php if ($distance_km_x100 === null): ?>—<?php else:
            $dkm = $distance_km_x100 / 100.0; ?>
            <?= number_format($dkm, 2) ?> km · <?= number_format($dkm * 1000 / 0.3048, 0) ?> ft
          <?php endif; ?>
        </span></div>
      <div class="lv-row"><span><b>Noise floor</b></span>
        <span><?= $noise_dbm !== null ? (int)$noise_dbm . ' dBm' : '—' ?></span></div>
      <div class="lv-row"><span><b>TX / RX bytes</b></span>
        <span><?= $fmt_bytes($tx_bytes) ?> / <?= $fmt_bytes($rx_bytes) ?></span></div>
      <?php if ($mac !== ''): ?>
        <div class="lv-row"><span><b><?= $is_remote ? 'Station MAC' : 'AP MAC' ?></b></span><span><code><?= $h($mac) ?></code></span></div>
      <?php endif; ?>

      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Ethernet</h3>
      <?php if ($eth): ?>
        <div class="lv-row"><span><b>LAN0 / LAN1 speed</b></span>
          <span><?= $eth['link_speed_mbps'] !== null ? number_format((float)$eth['link_speed_mbps'], 0) . ' Mbps-' . $h(ucfirst((string)$eth['duplex'])) : '—' ?> / —</span></div>
        <div class="lv-row"><span><b>Cable SNR</b></span>
          <span><?= $eth['cable_snr_db'] !== null ? '+' . number_format((float)$eth['cable_snr_db'], 0) . ' dB' : '—' ?> / —</span></div>
        <div class="lv-row"><span><b>Cable length</b></span>
          <span>
            <?= $eth['cable_length_m'] !== null
                ? number_format((float)$eth['cable_length_m'] / 0.3048, 0) . ' ft'
                . ' · ' . number_format((float)$eth['cable_length_m'], 0) . ' m'
                : '—' ?> / —
          </span></div>
        <?php
        /* TDR pair status — only meaningful when populated. */
        $pairs = array_filter([
            'A' => $eth['pair_a_status'] ?? null,
            'B' => $eth['pair_b_status'] ?? null,
            'C' => $eth['pair_c_status'] ?? null,
            'D' => $eth['pair_d_status'] ?? null,
        ], fn ($v) => $v !== null && $v !== 'unknown' && $v !== '');
        if ($pairs): ?>
          <div class="lv-row"><span><b>TDR pairs</b></span>
            <span><?php foreach ($pairs as $k => $v): ?>
              <span class="lv-tag" title="Pair <?= $h($k) ?>"><?= $h($k) ?>: <?= $h($v) ?></span>
            <?php endforeach; ?></span></div>
        <?php endif; ?>
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
      'More details', '/admin/device-view.php?id=' . (int)$link['ap_device_id'],
      $ap_h, $ap_eth,
      (string)$link['ap_model'], (string)$link['ap_firmware'],
      $link['ap_network_mode'] ?? null,
      $ap_h['polled_at'] ?? null, $link['ap_last_seen'] ?? null,
      (string)($link['wireless_mode'] ?? ''),
      (string)($link['security'] ?? ''),
      $link['distance_km'] !== null ? (int)round($link['distance_km'] * 100) : null,
      $link['noise_dbm'] !== null ? (int)$link['noise_dbm'] : null,
      $link['snr_db']    !== null ? (int)$link['snr_db']    : null,
      (string)$tdd,
      (string)($link['ssid'] ?? ''),
      $link['tx_bytes'] !== null ? (int)$link['tx_bytes'] : null,
      $link['rx_bytes'] !== null ? (int)$link['rx_bytes'] : null,
      null, '',
      (string)($link['ap_mac'] ?? ''),
      false
  ); ?>

  <?php $detail_card(
      'More details — remote',
      'Reconnect',  '/admin/device-view.php?id=' . (int)($link['cpe_device_id'] ?? 0),
      $cpe_h, $cpe_eth,
      (string)($link['cpe_model'] ?? ''), (string)($link['cpe_firmware'] ?? ''),
      $link['cpe_network_mode'] ?? null,
      $cpe_h['polled_at'] ?? null, $link['cpe_last_seen'] ?? null,
      (string)($link['wireless_mode'] ?? ''),
      (string)($link['security'] ?? ''),
      $link['distance_km'] !== null ? (int)round($link['distance_km'] * 100) : null,
      $link['noise_dbm_remote'] !== null ? (int)$link['noise_dbm_remote'] : null,
      $link['snr_db_remote']    !== null ? (int)$link['snr_db_remote']    : null,
      (string)$tdd,
      (string)($link['ssid'] ?? ''),
      $link['tx_bytes'] !== null ? (int)$link['tx_bytes'] : null,
      $link['rx_bytes'] !== null ? (int)$link['rx_bytes'] : null,
      $link['connection_time_seconds'] !== null ? (int)$link['connection_time_seconds']
        : ($link['uptime_seconds'] !== null ? (int)$link['uptime_seconds'] : null),
      (string)($link['remote_ip'] ?: ($link['cpe_mgmt_ip'] ?? '')),
      (string)($link['station_mac'] ?? ''),
      true
  ); ?>
</div>

<?php
$speedtests = link_speedtests_recent($id, 12);
?>
<div class="portal-card" style="margin-top:18px;">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">Speed tests</h3>
    <small class="muted">last <?= count($speedtests) ?> · iperf3</small>
  </div>
  <?php if ($speedtests): ?>
    <?php $latest = $speedtests[0]; ?>
    <div class="lv-row"><span><b>Latest</b></span>
      <span>
        <strong style="color:var(--accent);"><?= number_format((float)($latest['mbps_down'] ?? 0), 1) ?> Mbps</strong>
        <span class="muted">down</span>
        ·
        <?= number_format((float)($latest['mbps_up'] ?? 0), 1) ?> Mbps <span class="muted">up</span>
        ·
        <?= isset($latest['rtt_ms']) && $latest['rtt_ms'] !== null ? number_format((float)$latest['rtt_ms'], 1) . ' ms' : '—' ?>
        <span class="muted"> · <?= $h($latest['polled_at']) ?></span>
      </span>
    </div>
    <?php
    /* Mini sparkline of the last N down speeds. */
    $w = 720; $hh = 36;
    $vals = array_reverse(array_map(fn ($r) => (float)($r['mbps_down'] ?? 0), $speedtests));
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
  <form method="post" style="margin-top:12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="speedtest">
    <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
    <div class="field" style="margin:0;">
      <label>iperf3 target IP</label>
      <input type="text" name="target_ip" placeholder="e.g. PoP iperf server" style="width:220px;" required>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Run 10 s test</button>
    <small class="muted">Requires iperf3 on the CPE + reachable target host.</small>
  </form>
</div>

<div class="portal-card" style="margin-top:18px;">
  <div class="lv-grid-hdr">
    <h3 class="lv-label" style="font-size:11px;">Identifiers</h3>
    <small class="muted">link #<?= (int)$link['id'] ?></small>
  </div>
  <div class="lv-row"><span><b>SSID</b></span><span><?= $h($link['ssid'] ?: '—') ?></span></div>
  <div class="lv-row"><span><b>AP MAC</b></span><span><code><?= $h($link['ap_mac'] ?: '—') ?></code></span></div>
  <div class="lv-row"><span><b>Station MAC</b></span><span><code><?= $h($link['station_mac'] ?: '—') ?></code></span></div>
  <div class="lv-row"><span><b>Sector</b></span>
    <span>
      <?php if ($link['sector_id']): ?>
        <a href="/admin/sector-edit.php?id=<?= (int)$link['sector_id'] ?>"><?= $h($link['sector_name'] ?? '#' . (int)$link['sector_id']) ?></a>
      <?php else: ?>—<?php endif; ?>
    </span></div>
  <?php if (!empty($link['customer_id'])): ?>
    <div class="lv-row"><span><b>Customer</b></span>
      <span><a href="/admin/client-edit.php?id=<?= (int)$link['customer_id'] ?>">
        <?= $h(trim((string)($link['customer_name'] ?? '') . ' ' . (string)($link['customer_surname'] ?? ''))) ?: '#' . (int)$link['customer_id'] ?>
      </a></span></div>
  <?php endif; ?>
  <div class="lv-row"><span><b>MTU</b></span><span><?= $link['mtu_bytes'] !== null ? (int)$link['mtu_bytes'] . ' bytes' : '—' ?></span></div>
  <div class="lv-row"><span><b>Last evaluated</b></span><span><?= $h($fmt_dt($link['last_evaluated_at'] ?? null)) ?></span></div>
</div>

<div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="/admin/links.php">← All links</a>
    <a class="btn btn-ghost btn-sm" href="/admin/device-view.php?id=<?= (int)$link['ap_device_id'] ?>">Open AP</a>
    <?php if ($link['cpe_device_id']): ?>
      <a class="btn btn-ghost btn-sm" href="/admin/device-view.php?id=<?= (int)$link['cpe_device_id'] ?>">Open CPE</a>
    <?php endif; ?>
    <?php if ($link['sector_id']): ?>
      <a class="btn btn-ghost btn-sm" href="/admin/sector-edit.php?id=<?= (int)$link['sector_id'] ?>">Open sector</a>
    <?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="/admin/freq-planner.php<?= $link['sector_id'] ? '?sector_id=' . (int)$link['sector_id'] : '' ?>">Frequency planner</a>
  </div>
  <small class="muted">last evaluated: <?= $h($fmt_dt($link['last_evaluated_at'] ?? null)) ?></small>
</div>

<?php endif; /* tab */ ?>
