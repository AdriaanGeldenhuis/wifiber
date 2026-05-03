<?php
/**
 * Admin dashboard — the NOC view.
 *
 * Aggregates everything we now collect: device polling state, network
 * topology counts, customer breakdown, open tickets and unpaid
 * invoices, and a "what's down right now" panel sourced from devices.
 * All read-only, all single-query aggregates — should stay snappy
 * even with thousands of devices and millions of device_health rows.
 */
$page_title = 'Dashboard';
$active_key = 'dashboard';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/outages.php';
require_once __DIR__ . '/../auth/invoices.php';

$pdo = pdo();

/* -------------------------------------------------- active outages */
$active_outages = outages_all(['status' => 'active'], 20);
$active_outage_affected = 0;
foreach ($active_outages as $o) $active_outage_affected += (int)$o['affected_count'];

/* -------------------------------------------------- network counts */

$device_status_counts = ['online' => 0, 'offline' => 0, 'unknown' => 0, 'retired' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM devices GROUP BY status") as $r) {
    $device_status_counts[$r['status']] = (int)$r['c'];
}
$device_total = array_sum($device_status_counts);
$device_active_total = $device_total - $device_status_counts['retired'];

/* -------------------------------------------------- polling health */

$last_poll = $pdo->query(
    "SELECT polled_at FROM device_health ORDER BY id DESC LIMIT 1"
)->fetchColumn();

$polls_last_hour = (int)$pdo->query(
    "SELECT COUNT(*) FROM device_health WHERE polled_at >= (NOW() - INTERVAL 1 HOUR)"
)->fetchColumn();

$polls_last_hour_online = (int)$pdo->query(
    "SELECT COUNT(*) FROM device_health WHERE polled_at >= (NOW() - INTERVAL 1 HOUR) AND status = 'online'"
)->fetchColumn();

$uptime_pct_1h = $polls_last_hour > 0
    ? round(($polls_last_hour_online / $polls_last_hour) * 100, 1)
    : null;

$last_poll_age_label = '';
if ($last_poll) {
    $age = max(0, time() - strtotime((string)$last_poll));
    if      ($age < 60)      $last_poll_age_label = $age . 's ago';
    elseif  ($age < 3600)    $last_poll_age_label = floor($age / 60) . 'm ago';
    elseif  ($age < 86400)   $last_poll_age_label = floor($age / 3600) . 'h ago';
    else                     $last_poll_age_label = floor($age / 86400) . 'd ago';
}

/* -------------------------------------------------- topology */

$site_counts = ['tower' => 0, 'ap' => 0, 'ptp_endpoint' => 0, 'pop' => 0, 'other' => 0];
foreach ($pdo->query("SELECT type, COUNT(*) c FROM sites WHERE is_active = 1 GROUP BY type") as $r) {
    $site_counts[$r['type']] = (int)$r['c'];
}
$site_total = array_sum($site_counts);

$sector_count = (int)$pdo->query("SELECT COUNT(*) FROM sectors")->fetchColumn();

/* -------------------------------------------------- customers */

$customer_status = ['active' => 0, 'lead' => 0, 'suspended' => 0, 'disconnected' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM users WHERE role = 'client' GROUP BY status") as $r) {
    $customer_status[$r['status']] = (int)$r['c'];
}

/* -------------------------------------------------- money & support */

$open_tickets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('open','in_progress')")->fetchColumn();

$unpaid_row = $pdo->query(
    "SELECT COUNT(*) c, COALESCE(SUM(total), 0) t FROM invoices WHERE status = 'unpaid'"
)->fetch();
$unpaid_count = (int)($unpaid_row['c'] ?? 0);
$unpaid_total = (float)($unpaid_row['t'] ?? 0);

$overdue_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM invoices WHERE status = 'unpaid' AND due_at < CURDATE()"
)->fetchColumn();

$overdue_total = (float)$pdo->query(
    "SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'unpaid' AND due_at < CURDATE()"
)->fetchColumn();

/* -------------------------------------------------- 30-day revenue sparkline */
// Daily totals of paid invoices over the last 30 days. Used to draw a
// small inline-SVG sparkline on the dashboard money card.
$revenue_rows = $pdo->query(
    "SELECT DATE(paid_at) d, COALESCE(SUM(total), 0) t
       FROM invoices
      WHERE status = 'paid' AND paid_at >= (CURDATE() - INTERVAL 30 DAY)
      GROUP BY DATE(paid_at)"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$revenue_30d = [];
$revenue_30d_total = 0.0;
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $v   = (float)($revenue_rows[$day] ?? 0);
    $revenue_30d[] = $v;
    $revenue_30d_total += $v;
}

/* -------------------------------------------------- top problem APs (last 24h) */
// APs that flapped most in the last 24 hours — count of online↔offline
// transitions in device_health. Helps the NOC notice flaky gear before
// it goes hard-down.
$problem_aps = $pdo->query(
    "SELECT d.id, d.name, d.vendor, d.model, d.status, d.mgmt_ip,
            COUNT(*) flap_count
       FROM device_health h
       JOIN devices d ON d.id = h.device_id
      WHERE h.polled_at >= (NOW() - INTERVAL 24 HOUR)
        AND h.status IN ('online','offline')
        AND d.role = 'ap'
      GROUP BY d.id
     HAVING COUNT(DISTINCT h.status) > 1
      ORDER BY flap_count DESC
      LIMIT 5"
)->fetchAll();

/* -------------------------------------------------- what's down right now */

$offline_now = $pdo->query(
    "SELECT id, name, role, vendor, model, mgmt_ip, last_seen_at
       FROM devices
      WHERE status = 'offline'
      ORDER BY (last_seen_at IS NULL), last_seen_at DESC
      LIMIT 10"
)->fetchAll();

$offline_count = count($offline_now);

$last_seen_label = function (?string $when): string {
    if (!$when) return 'never';
    $age = max(0, time() - strtotime($when));
    if      ($age < 60)    return $age . 's ago';
    elseif  ($age < 3600)  return floor($age / 60) . 'm ago';
    elseif  ($age < 86400) return floor($age / 3600) . 'h ago';
    return floor($age / 86400) . 'd ago';
};
?>

<div class="portal-head">
  <h1>NOC dashboard</h1>
  <p class="portal-sub">Welcome, <?= htmlspecialchars($user['name']) ?>. Network health, topology and money in one place.</p>
</div>

<?php if ($active_outages): ?>
<div class="portal-card" style="border-left: 3px solid #d44;">
  <h2>Active outages
    <span class="muted">(<?= count($active_outages) ?> &middot; <?= $active_outage_affected ?> customer<?= $active_outage_affected === 1 ? '' : 's' ?> affected)</span>
  </h2>
  <table class="data-table">
    <thead><tr><th>Scope</th><th>Where</th><th>Cause</th><th style="text-align:right;">Affected</th><th>Since</th></tr></thead>
    <tbody>
      <?php foreach ($active_outages as $o): ?>
        <tr>
          <td><span style="background:#d44;color:#fff;padding:1px 6px;border-radius:6px;font-size:10px;text-transform:uppercase;"><?= htmlspecialchars($o['scope']) ?></span></td>
          <td><strong><?= htmlspecialchars($o['scope_label']) ?></strong></td>
          <td><small><?= htmlspecialchars($o['cause'] ?? '—') ?></small></td>
          <td style="text-align:right;"><?= $o['affected_count'] ?></td>
          <td><small class="muted"><?= htmlspecialchars($o['started_at']) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p style="margin: 8px 0 0;"><a href="/admin/outages.php" class="card-link">Manage outages &rarr;</a></p>
</div>
<?php endif; ?>

<h2 style="margin: 24px 0 8px;">Devices</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Online</span>
    <div class="card-num" style="color:#0c8;"><?= $device_status_counts['online'] ?></div>
    <p class="card-sub muted">of <?= $device_active_total ?> active</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Offline</span>
    <div class="card-num" style="color:<?= $device_status_counts['offline'] > 0 ? '#d44' : 'var(--accent)' ?>;"><?= $device_status_counts['offline'] ?></div>
    <a href="/admin/devices.php?status=offline" class="card-link">View offline &rarr;</a>
  </div>
  <div class="portal-card">
    <span class="card-label">Unknown</span>
    <div class="card-num" style="color:#888;"><?= $device_status_counts['unknown'] ?></div>
    <p class="card-sub muted">never polled or no IP</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Last poll</span>
    <div class="card-num" style="font-size:1.4rem;"><?= htmlspecialchars($last_poll_age_label ?: 'never') ?></div>
    <p class="card-sub muted">
      <?php if ($polls_last_hour > 0): ?>
        <?= $polls_last_hour ?> polls / 1h &middot; <?= $uptime_pct_1h ?>% online
      <?php else: ?>
        cron not running yet — see <a href="https://github.com/AdriaanGeldenhuis/wifiber#device-polling" target="_blank">README</a>
      <?php endif; ?>
    </p>
  </div>
</div>

<h2 style="margin: 24px 0 8px;">Topology</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Sites</span>
    <div class="card-num"><?= $site_total ?></div>
    <a href="/admin/map.php" class="card-link">Open map &rarr;</a>
  </div>
  <div class="portal-card">
    <span class="card-label">Towers</span>
    <div class="card-num"><?= $site_counts['tower'] ?></div>
    <p class="card-sub muted"><?= $site_counts['ap'] ?> AP &middot; <?= $site_counts['pop'] ?> PoP</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Sectors</span>
    <div class="card-num"><?= $sector_count ?></div>
    <a href="/admin/sectors.php" class="card-link">Manage sectors &rarr;</a>
  </div>
</div>

<h2 style="margin: 24px 0 8px;">Customers</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Active</span>
    <div class="card-num" style="color:#0c8;"><?= $customer_status['active'] ?></div>
    <a href="/admin/clients.php" class="card-link">Manage clients &rarr;</a>
  </div>
  <div class="portal-card">
    <span class="card-label">Leads</span>
    <div class="card-num" style="color:#08e;"><?= $customer_status['lead'] ?></div>
    <a href="/admin/coverage.php" class="card-link">Coverage waitlist &rarr;</a>
  </div>
  <div class="portal-card">
    <span class="card-label">Suspended</span>
    <div class="card-num" style="color:<?= $customer_status['suspended'] > 0 ? '#fa0' : 'var(--accent)' ?>;"><?= $customer_status['suspended'] ?></div>
    <p class="card-sub muted"><?= $customer_status['disconnected'] ?> disconnected</p>
  </div>
</div>

<?php
// Inline-SVG sparkline for the 30-day revenue card. Width=100%, height=36
// matches the .sparkline class in portal.css.
$spark_max = max($revenue_30d) ?: 1;
$spark_w   = 100;
$spark_h   = 30;
$spark_pts = [];
foreach ($revenue_30d as $i => $v) {
    $x = round(($i / max(1, count($revenue_30d) - 1)) * $spark_w, 2);
    $y = round($spark_h - ($v / $spark_max) * $spark_h, 2);
    $spark_pts[] = $x . ',' . $y;
}
$spark_path = 'M' . implode(' L', $spark_pts);
$spark_fill = 'M0,' . $spark_h . ' L' . implode(' L', $spark_pts) . ' L' . $spark_w . ',' . $spark_h . ' Z';
?>
<h2 style="margin: 24px 0 8px;">Money &amp; support</h2>
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Unpaid invoices</span>
    <div class="card-num" style="color:<?= $overdue_count > 0 ? '#d44' : ($unpaid_count > 0 ? '#fa0' : 'var(--accent)') ?>;"><?= $unpaid_count ?></div>
    <p class="card-sub muted">
      R<?= number_format($unpaid_total, 2) ?>
      <?php if ($overdue_count > 0): ?>
        &middot; <span class="status-pill status-overdue" style="font-size:10px;padding:1px 8px;"><?= $overdue_count ?> overdue · R<?= number_format($overdue_total, 0) ?></span>
      <?php endif; ?>
    </p>
  </div>
  <div class="portal-card">
    <span class="card-label">Revenue (30d)</span>
    <div class="card-num"><?= htmlspecialchars(money($revenue_30d_total)) ?></div>
    <svg class="sparkline" viewBox="0 0 <?= $spark_w ?> <?= $spark_h ?>" preserveAspectRatio="none" aria-hidden="true">
      <path class="sparkline-fill" d="<?= $spark_fill ?>"/>
      <path class="sparkline-line" d="<?= $spark_path ?>"/>
    </svg>
  </div>
  <div class="portal-card">
    <span class="card-label">Open tickets</span>
    <div class="card-num" style="color:<?= $open_tickets > 0 ? '#08e' : 'var(--accent)' ?>;"><?= $open_tickets ?></div>
    <a href="/admin/tickets.php?status=open" class="card-link">Open tickets &rarr;</a>
  </div>
</div>

<?php if ($problem_aps): ?>
<div class="portal-card" style="border-left: 3px solid #fbbf24;">
  <h2>Flaky APs <span class="muted">(last 24h, top <?= count($problem_aps) ?>)</span></h2>
  <p class="muted small" style="margin: -2px 0 10px;">Devices whose status flipped between online and offline. Worth investigating before they go hard-down.</p>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Device</th><th>Vendor</th><th>Mgmt IP</th><th>Now</th><th style="text-align:right;">Flaps</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($problem_aps as $ap): ?>
        <tr>
          <td><strong><?= htmlspecialchars($ap['name']) ?></strong></td>
          <td><?= htmlspecialchars($ap['vendor']) ?><?= $ap['model'] ? ' &middot; ' . htmlspecialchars($ap['model']) : '' ?></td>
          <td><small><?= htmlspecialchars($ap['mgmt_ip'] ?: '—') ?></small></td>
          <td><span class="status-pill status-<?= htmlspecialchars($ap['status']) ?>"><?= htmlspecialchars($ap['status']) ?></span></td>
          <td style="text-align:right;"><strong style="color:#fbbf24;"><?= (int)$ap['flap_count'] ?></strong></td>
          <td><a class="card-link" href="/admin/devices.php?search=<?= urlencode($ap['name']) ?>">Open &rarr;</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($offline_count > 0): ?>
<div class="portal-card" style="border-left: 3px solid #d44;">
  <h2>Offline now <span class="muted">(<?= $offline_count ?>)</span></h2>
  <table class="data-table">
    <thead>
      <tr>
        <th>Device</th><th>Role</th><th>Vendor</th><th>Mgmt IP</th><th>Last seen</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($offline_now as $d): ?>
        <tr>
          <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
          <td><?= htmlspecialchars($d['role']) ?></td>
          <td><?= htmlspecialchars($d['vendor']) ?><?= $d['model'] ? ' &middot; ' . htmlspecialchars($d['model']) : '' ?></td>
          <td><small><?= htmlspecialchars($d['mgmt_ip'] ?: '—') ?></small></td>
          <td><small class="muted"><?= htmlspecialchars($last_seen_label($d['last_seen_at'])) ?></small></td>
          <td><a href="/admin/devices.php?search=<?= urlencode($d['name']) ?>" class="card-link">Open &rarr;</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif ($device_active_total > 0): ?>
<div class="portal-card" style="border-left: 3px solid #0c8;">
  <p class="muted" style="margin:0;"><strong>All <?= $device_active_total ?> active devices online.</strong> No outages right now.</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
