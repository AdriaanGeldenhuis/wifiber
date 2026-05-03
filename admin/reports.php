<?php
/**
 * Admin reports — read-only aggregates over what we've collected.
 *
 * Sections:
 *   Customer growth     12 months of new clients per month, bar chart
 *   Revenue             12 months invoiced + paid total, bar chart
 *   Outage history      30 days of outage opens per day, bar chart
 *   Outage stats        MTTR average, longest active, total customer-minutes
 *   Sector capacity     sectors near or at their max_clients
 *
 * Inline server-rendered SVG bars — no charting library, no JS.
 */
$page_title = 'Reports';
$active_key = 'reports';
require __DIR__ . '/_layout.php';

$pdo = pdo();

/* ---------- helpers ---------- */
$bar_chart_svg = function (array $rows, string $value_key, string $label_key, string $color = '#0c8'): string {
    if (!$rows) return '<p class="muted" style="margin:0;">No data yet.</p>';
    $w = 760;
    $h = 140;
    $pad_top = 8;
    $pad_bot = 22;
    $plot_h  = $h - $pad_top - $pad_bot;
    $max     = 0;
    foreach ($rows as $r) $max = max($max, (float)$r[$value_key]);
    if ($max <= 0) $max = 1;
    $bar_w   = max(4, (int)floor($w / max(1, count($rows))) - 2);
    $svg     = '<svg viewBox="0 0 ' . $w . ' ' . $h
             . '" style="width:100%;height:auto;background:rgba(255,255,255,0.02);border-radius:6px;">';
    $svg    .= '<line x1="0" x2="' . $w . '" y1="' . ($h - $pad_bot) . '" y2="' . ($h - $pad_bot) . '" stroke="rgba(255,255,255,0.15)"/>';
    $i = 0;
    foreach ($rows as $r) {
        $val = (float)$r[$value_key];
        $bh  = (int)round(($val / $max) * $plot_h);
        $x   = (int)round($i * ($w / count($rows))) + 1;
        $y   = $h - $pad_bot - $bh;
        $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bar_w . '" height="' . $bh
              . '" fill="' . $color . '" opacity="0.85"><title>'
              . htmlspecialchars($r[$label_key] . ': ' . $val) . '</title></rect>';
        // X label every Nth bar so it doesn't overlap.
        $stride = max(1, (int)floor(count($rows) / 12));
        if ($i % $stride === 0) {
            $svg .= '<text x="' . ($x + $bar_w / 2) . '" y="' . ($h - 4)
                  . '" text-anchor="middle" fill="rgba(255,255,255,0.5)" font-size="10">'
                  . htmlspecialchars((string)$r[$label_key]) . '</text>';
        }
        $i++;
    }
    $svg .= '<text x="4" y="' . ($pad_top + 10) . '" fill="rgba(255,255,255,0.4)" font-size="10">' . (int)$max . '</text>';
    $svg .= '</svg>';
    return $svg;
};

$age_label = function (int $secs): string {
    if ($secs < 60)    return $secs . 's';
    if ($secs < 3600)  return floor($secs / 60)   . 'm';
    if ($secs < 86400) return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
    return floor($secs / 86400) . 'd ' . floor(($secs % 86400) / 3600) . 'h';
};

/* ---------- customer growth (last 12 months) ---------- */
$customer_growth = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) c
       FROM users
      WHERE role = 'client' AND created_at >= (NOW() - INTERVAL 12 MONTH)
      GROUP BY m ORDER BY m ASC"
)->fetchAll();
// Pad missing months with zero so the chart timeline is continuous.
$months = [];
for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("-$i month"))] = 0;
foreach ($customer_growth as $r) $months[$r['m']] = (int)$r['c'];
$growth_rows = [];
foreach ($months as $k => $v) $growth_rows[] = ['m' => substr($k, 5), 'c' => $v]; // "MM" for axis label
$total_growth_12mo = array_sum(array_column($growth_rows, 'c'));

/* ---------- revenue (last 12 months) ---------- */
$revenue = $pdo->query(
    "SELECT DATE_FORMAT(issued_at, '%Y-%m') AS m,
            SUM(total) AS billed,
            SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) AS paid
       FROM invoices
      WHERE issued_at >= (CURDATE() - INTERVAL 12 MONTH)
      GROUP BY m ORDER BY m ASC"
)->fetchAll();
$rev_billed_map = $rev_paid_map = [];
foreach ($months as $k => $_) { $rev_billed_map[$k] = 0; $rev_paid_map[$k] = 0; }
foreach ($revenue as $r) {
    $rev_billed_map[$r['m']] = (float)$r['billed'];
    $rev_paid_map[$r['m']]   = (float)$r['paid'];
}
$rev_rows = [];
foreach ($rev_billed_map as $k => $v) $rev_rows[] = ['m' => substr($k, 5), 'v' => $v];
$total_billed_12mo = array_sum(array_column($rev_rows, 'v'));
$total_paid_12mo   = array_sum(array_values($rev_paid_map));
$collection_pct    = $total_billed_12mo > 0
    ? round(($total_paid_12mo / $total_billed_12mo) * 100, 1)
    : null;

/* ---------- outage trend (last 30 days) ---------- */
$outage_30d = $pdo->query(
    "SELECT DATE(started_at) d, COUNT(*) c
       FROM outages
      WHERE started_at >= (NOW() - INTERVAL 30 DAY)
      GROUP BY d ORDER BY d ASC"
)->fetchAll();
$days = [];
for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = 0;
foreach ($outage_30d as $r) $days[$r['d']] = (int)$r['c'];
$outage_rows = [];
foreach ($days as $k => $v) $outage_rows[] = ['d' => substr($k, 5), 'c' => $v]; // "MM-DD"
$total_outages_30d = array_sum(array_column($outage_rows, 'c'));

/* ---------- outage stats ---------- */
$mttr = $pdo->query(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, resolved_at)) AS avg_secs,
            COUNT(*) AS n
       FROM outages
      WHERE status = 'resolved' AND started_at >= (NOW() - INTERVAL 30 DAY)"
)->fetch();
$mttr_secs = $mttr && $mttr['avg_secs'] !== null ? (int)$mttr['avg_secs'] : null;
$mttr_n    = (int)($mttr['n'] ?? 0);

$customer_minutes = (int)$pdo->query(
    "SELECT COALESCE(SUM(
              affected_count *
              TIMESTAMPDIFF(MINUTE, started_at, COALESCE(resolved_at, NOW()))
            ), 0)
       FROM outages
      WHERE started_at >= (NOW() - INTERVAL 30 DAY)"
)->fetchColumn();

$active_count = (int)$pdo->query("SELECT COUNT(*) FROM outages WHERE status = 'active'")->fetchColumn();

$longest_active = $pdo->query(
    "SELECT scope_label, started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS dur
       FROM outages
      WHERE status = 'active'
      ORDER BY started_at ASC LIMIT 1"
)->fetch() ?: null;

/* ---------- sector capacity ---------- */
$tight_sectors = $pdo->query(
    "SELECT s.id, s.name, t.name AS tower_name, s.max_clients,
            (SELECT COUNT(*) FROM users u
              WHERE u.sector_id = s.id AND u.role = 'client' AND u.status = 'active') AS cust
       FROM sectors s LEFT JOIN sites t ON t.id = s.tower_id
      WHERE s.max_clients IS NOT NULL AND s.max_clients > 0
      ORDER BY (cust / s.max_clients) DESC
      LIMIT 10"
)->fetchAll();
?>

<div class="portal-head">
  <h1>Reports</h1>
  <p class="portal-sub">Read-only rollups over the customer, billing and network tables. All ranges are rolling — no fixed period selectors yet.</p>
</div>

<h2 style="margin: 24px 0 8px;">Customer growth</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">New clients (12 m)</span>
    <div class="card-num"><?= (int)$total_growth_12mo ?></div>
    <p class="card-sub muted">Sum of monthly bars below.</p>
  </div>
  <div class="portal-card" style="grid-column: span 2;">
    <h3 style="margin:0 0 8px;font-size:.95rem;">New clients per month</h3>
    <?= $bar_chart_svg($growth_rows, 'c', 'm', '#08e') ?>
  </div>
</div>

<h2 style="margin: 24px 0 8px;">Revenue</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Billed (12 m)</span>
    <div class="card-num">R<?= number_format($total_billed_12mo, 0) ?></div>
    <p class="card-sub muted">Sum of issued invoices.</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Collected (12 m)</span>
    <div class="card-num" style="color:#0c8;">R<?= number_format($total_paid_12mo, 0) ?></div>
    <p class="card-sub muted"><?= $collection_pct === null ? '—' : $collection_pct . '% of billed' ?></p>
  </div>
  <div class="portal-card" style="grid-column: span 2;">
    <h3 style="margin:0 0 8px;font-size:.95rem;">Billed per month</h3>
    <?= $bar_chart_svg($rev_rows, 'v', 'm', '#0c8') ?>
  </div>
</div>

<h2 style="margin: 24px 0 8px;">Outages (30 days)</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Total opened</span>
    <div class="card-num"><?= $total_outages_30d ?></div>
    <p class="card-sub muted"><?= $active_count ?> still active</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Average MTTR</span>
    <div class="card-num" style="font-size:1.4rem;">
      <?= $mttr_secs !== null ? htmlspecialchars($age_label($mttr_secs)) : '—' ?>
    </div>
    <p class="card-sub muted"><?= $mttr_n ?> resolved outage<?= $mttr_n === 1 ? '' : 's' ?> sampled</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Customer-minutes</span>
    <div class="card-num" style="color:<?= $customer_minutes > 0 ? '#fbbf24' : 'var(--accent)' ?>;"><?= number_format($customer_minutes) ?></div>
    <p class="card-sub muted">Sum of affected_count × duration. Lower is better.</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Longest active</span>
    <div class="card-num" style="font-size:1.2rem;color:<?= $longest_active ? '#d44' : 'var(--accent)' ?>;">
      <?= $longest_active ? htmlspecialchars($age_label((int)$longest_active['dur'])) : 'none' ?>
    </div>
    <p class="card-sub muted"><?= $longest_active ? htmlspecialchars((string)$longest_active['scope_label']) : 'all clear' ?></p>
  </div>
</div>

<div class="portal-card">
  <h3 style="margin:0 0 8px;font-size:.95rem;">Outages opened per day</h3>
  <?= $bar_chart_svg($outage_rows, 'c', 'd', '#d44') ?>
</div>

<h2 style="margin: 24px 0 8px;">Sector capacity</h2>
<div class="portal-card">
  <?php if (!$tight_sectors): ?>
    <p class="muted" style="margin:0;">No sectors have a <code>max_clients</code> limit set yet. Edit a sector on <a href="/admin/sectors.php">/admin/sectors.php</a> and fill in <em>Max clients</em> to track capacity here.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Sector</th><th>Tower</th>
          <th style="text-align:right;">Customers</th>
          <th style="text-align:right;">Max</th>
          <th style="text-align:right;">Utilisation</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tight_sectors as $s):
          $cust = (int)$s['cust'];
          $max  = (int)$s['max_clients'];
          $pct  = $max > 0 ? round(($cust / $max) * 100, 1) : 0;
          $color = $pct >= 95 ? '#d44' : ($pct >= 80 ? '#fbbf24' : '#0c8');
        ?>
          <tr>
            <td><a href="/admin/sectors.php?search=<?= urlencode($s['name']) ?>" style="color:inherit;"><strong><?= htmlspecialchars($s['name']) ?></strong></a></td>
            <td><?= htmlspecialchars((string)$s['tower_name']) ?></td>
            <td style="text-align:right;"><?= $cust ?></td>
            <td style="text-align:right;"><?= $max ?></td>
            <td style="text-align:right;"><strong style="color:<?= $color ?>;"><?= $pct ?>%</strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
