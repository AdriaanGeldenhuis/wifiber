<?php
/**
 * Shared chart + formatting helpers used by:
 *   admin/link-view.php      (wireless AP↔CPE radio dashboard)
 *   admin/site-link-view.php (site-to-site backbone link dashboard)
 *
 * All renderers return server-side SVG strings (no JS chart library)
 * and all formatters return plain strings ready to drop into HTML.
 *
 * Conventions:
 *   - Colours match the portal CSS variables (cyan accent, danger,
 *     success, warn) so the dashboard looks consistent on dark mode.
 *   - Every helper degrades gracefully when its input is null / empty
 *     and returns "—" so a half-populated row still renders.
 */

declare(strict_types=1);

if (!function_exists('lv_h')) {
    function lv_h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES);
    }
}

if (!function_exists('lv_fmt_uptime')) {
    function lv_fmt_uptime(?int $s): string {
        if (!$s) return '—';
        $d  = intdiv($s, 86400); $s %= 86400;
        $hh = intdiv($s, 3600);  $s %= 3600;
        $mm = intdiv($s, 60);
        if ($d > 0)  return sprintf('%d day%s %02d:%02d:%02d', $d, $d === 1 ? '' : 's', $hh, $mm, $s);
        if ($hh > 0) return sprintf('%d:%02d:%02d', $hh, $mm, $s);
        return sprintf('00:%02d:%02d', $mm, $s);
    }
}

if (!function_exists('lv_fmt_bytes')) {
    function lv_fmt_bytes($b): string {
        if ($b === null) return '—';
        $b = (float)$b;
        foreach (['B','K','M','G','T','P'] as $u) {
            if ($b < 1024) return number_format($b, $u === 'B' ? 0 : 2) . ' ' . $u;
            $b /= 1024;
        }
        return number_format($b, 2) . ' E';
    }
}

if (!function_exists('lv_fmt_ft')) {
    function lv_fmt_ft(?float $km): string {
        if ($km === null) return '—';
        $ft = $km * 1000.0 / 0.3048;
        return $ft >= 5280
            ? number_format($ft / 5280.0, 2) . ' mi'
            : number_format($ft, 2) . ' ft';
    }
}

if (!function_exists('lv_fmt_dt')) {
    function lv_fmt_dt($dt): string {
        if (!$dt) return '—';
        $t = strtotime((string)$dt);
        return $t ? date('Y-m-d H:i:s', $t) : '—';
    }
}

/* "-48 (-51 / -52) Δ1 dBm" main-signal + per-chain breakdown card. */
if (!function_exists('lv_chain_label')) {
    function lv_chain_label(?int $main, ?int $c0, ?int $c1): string {
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
    }
}

/* Health-score pill (0-100 → cyan/amber/red). */
if (!function_exists('lv_health_pill')) {
    function lv_health_pill(?int $score): string {
        if ($score === null) return '<span class="lv-pill" style="background:#2a3340;color:#a5b0bd;">no data</span>';
        [$bg, $label] = match (true) {
            $score >= 75 => ['#4ade80', 'good'],
            $score >= 50 => ['#e8a814', 'fair'],
            default      => ['#ff5470', 'poor'],
        };
        return '<span class="lv-pill" style="background:' . $bg . ';color:#001218;">' . $score . ' · ' . $label . '</span>';
    }
}

/* Online / offline / unknown pill. */
if (!function_exists('lv_status_pill')) {
    function lv_status_pill(?string $status): string {
        $status = $status ?: 'unknown';
        $bg = match ($status) {
            'online'  => '#4ade80',
            'offline' => '#ff5470',
            default   => '#6b7480',
        };
        return '<span class="lv-pill" style="background:' . $bg . ';color:#001218;">' . $status . '</span>';
    }
}

/* RF environment heat-bar — server-side SVG, accent-cyan intensity per
   per-frequency RSSI. Highlights the operating channel block. */
if (!function_exists('lv_rf_bars')) {
    function lv_rf_bars(array $rf, ?int $centre_mhz, ?int $width_mhz): string {
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
        $label_centre = $centre_mhz
            ? '<span style="color:#f4f6f8;font-weight:600;">' . (int)$centre_mhz . ' MHz</span>'
              . ($width_mhz ? '<span class="muted"> · ' . (int)$width_mhz . ' MHz</span>' : '')
            : '';
        return '<svg viewBox="0 0 ' . $w . ' ' . $bar_h . '" width="100%" preserveAspectRatio="none" style="display:block;border-radius:6px;overflow:hidden;">'
            . '<rect x="0" y="0" width="' . $w . '" height="' . $bar_h . '" fill="rgba(5,218,253,0.06)"/>'
            . $bars . $highlight
            . '</svg>'
            . '<div style="display:flex;justify-content:space-between;align-items:baseline;font-size:11px;color:#6b7480;margin-top:4px;">'
            . '<span>' . lv_h((string)$fmin) . ' MHz</span>'
            . '<span>' . $label_centre . '</span>'
            . '<span>' . lv_h((string)$fmax) . ' MHz</span></div>';
    }
}

/* Segmented 0-40 dB CINR gauge. */
if (!function_exists('lv_cinr_gauge')) {
    function lv_cinr_gauge(?int $snr): string {
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
    }
}

/* RX data-rate ladder (1x..NX) with current + expected highlighted. */
if (!function_exists('lv_rate_ladder')) {
    function lv_rate_ladder(?int $current, ?int $expected, ?int $max, string $modulation = ''): string {
        $max     = max(2, (int)($max ?? 8));
        $current = $current  !== null ? max(0, min($max, (int)$current))  : null;
        $expected= $expected !== null ? max(0, min($max, (int)$expected)) : null;
        $w = 720; $hh = 26;
        $cell  = $w / $max;
        $cells = '';
        for ($i = 1; $i <= $max; $i++) {
            $cx = ($i - 1) * $cell;
            $is_cur = ($current  !== null && $i === $current);
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
        $tick_labels = '';
        for ($i = 1; $i < $max; $i++) {
            $tick_labels .= '<span>' . $i . 'X</span>';
        }
        $tick_labels .= '<span>' . $max . 'X' . ($modulation !== '' ? ' · ' . lv_h($modulation) : '') . '</span>';
        return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" preserveAspectRatio="none" style="display:block;">'
            . $cells . '</svg>'
            . '<div style="display:flex;justify-content:space-between;font-size:10px;color:#6b7480;margin-top:4px;">'
            . $tick_labels . '</div>';
    }
}

/* 24h Signal / Noise / Interference chart (-110..-40 dBm Y axis). */
if (!function_exists('lv_signal_chart_svg')) {
    function lv_signal_chart_svg(array $samples, string $side): string {
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
            $ak = $side === 'remote' ? 'airtime_remote_pct': 'airtime_local_pct';
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
        // Filled noise-floor band so it reads at a glance.
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
            . $grid . $band
            . $line($sigs,  '#4477ff', 1.7)
            . $line($intr,  '#e8a814', 1.4)
            . $line($noise, '#4ade80', 1.4)
            . '</svg>';
    }
}

/* Capacity vs throughput sparkline (Mbps). */
if (!function_exists('lv_tput_chart_svg')) {
    function lv_tput_chart_svg(array $samples, string $side): string {
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
    }
}
