<?php
/**
 * Read-only "My service" page for clients.
 *
 * Surfaces everything we know about the customer's connection in one
 * place — package, equipment, sector / tower, RADIUS username, install
 * date — without giving them anything they can change.  All edits go
 * through admin (or are surfaced as "Contact support" call-outs).
 */

declare(strict_types=1);

$page_title = 'My service';
$active_key = 'service';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/products.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';

$pdo = pdo();
$h   = fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

// Customer's product (if any) — gives us speed + monthly price.
$product = null;
if (!empty($user['product_id'])) {
    $product = products_find((int)$user['product_id']);
}

// Sector / tower so the customer knows which point on our network
// serves them.
$sector_row = null;
$tower_row  = null;
if (!empty($user['sector_id'])) {
    $stmt = $pdo->prepare(
        "SELECT s.*, t.name AS tower_name, t.id AS tower_id
           FROM sectors s
           LEFT JOIN sites t ON t.id = s.tower_id
          WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([(int)$user['sector_id']]);
    $sector_row = $stmt->fetch() ?: null;
}
if (!$sector_row && !empty($user['site_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$user['site_id']]);
    $tower_row = $stmt->fetch() ?: null;
}

// Equipment we've recorded against this customer.
$customer_devices = devices_for_customer((int)$user['id']);

// Wireless link summary (re-uses the link-health page's data without
// any actions).
$link_stmt = $pdo->prepare(
    "SELECT wl.*, ap.name AS ap_name, ap.model AS ap_model
       FROM wireless_links wl
       JOIN devices ap ON ap.id = wl.ap_device_id
      WHERE wl.customer_id = ?
      ORDER BY wl.last_evaluated_at DESC
      LIMIT 1"
);
$link_stmt->execute([(int)$user['id']]);
$link = $link_stmt->fetch() ?: null;

// RADIUS username (read-only — the password lives encrypted server-side).
$radius_username = null;
if (function_exists('radius_username_for')) {
    $radius_username = radius_username_for($user);
}

// Status pill colour: active = good, suspended/disconnected = warn.
$status     = (string)($user['status'] ?? 'active');
$status_pill = match ($status) {
    'active'       => 'status-paid',
    'suspended'    => 'status-overdue',
    'disconnected' => 'status-cancelled',
    'lead'         => 'status-open',
    default        => 'status-open',
};
$status_label = ucfirst($status);

// Customer-type label.
$cust_type_label = ucfirst((string)($user['customer_type'] ?? 'residential'));
?>

<div class="portal-head">
  <h1>My service</h1>
  <p class="portal-sub">A read-only view of everything we have on file for your connection. Need a change? <a href="/account/tickets.php">Open a ticket</a> or call us on <a href="tel:0800111222">0800 111 222</a>.</p>
</div>

<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Status</span>
    <div class="card-num" style="font-size:1.4rem;line-height:1.2;">
      <span class="status-pill <?= $h($status_pill) ?>"><?= $h($status_label) ?></span>
    </div>
    <p class="card-sub muted"><?= $h($cust_type_label) ?> account</p>
  </div>
  <?php if (!empty($user['account_no'])): ?>
    <div class="portal-card">
      <span class="card-label">Account number</span>
      <div class="card-num" style="font-size:1.4rem;line-height:1.2;"><?= $h($user['account_no']) ?></div>
      <p class="card-sub muted">Quote this when paying or contacting us.</p>
    </div>
  <?php endif; ?>
  <div class="portal-card">
    <span class="card-label">Service start</span>
    <div class="card-num" style="font-size:1.2rem;line-height:1.2;color:var(--text);">
      <?= $h($user['service_start'] ?: substr((string)($user['created_at'] ?? ''), 0, 10) ?: '—') ?>
    </div>
    <?php if (!empty($user['billing_day'])): ?>
      <p class="card-sub muted">Billed on day <?= (int)$user['billing_day'] ?> of each month.</p>
    <?php else: ?>
      <p class="card-sub muted">Billing day not set yet.</p>
    <?php endif; ?>
  </div>
  <?php if ($product): ?>
    <div class="portal-card">
      <span class="card-label">Package</span>
      <div class="card-num" style="font-size:1.2rem;line-height:1.2;color:var(--text);"><?= $h($product['name']) ?></div>
      <p class="card-sub muted">
        <?= number_format((float)$product['down_mbps'], 0) ?>/<?= number_format((float)$product['up_mbps'], 0) ?> Mbps
        &middot; R<?= number_format((float)$product['monthly_price'], 2) ?>/m
      </p>
    </div>
  <?php elseif (!empty($user['package'])): ?>
    <div class="portal-card">
      <span class="card-label">Package</span>
      <div class="card-num" style="font-size:1.2rem;line-height:1.2;color:var(--text);"><?= $h($user['package']) ?></div>
      <p class="card-sub muted">Contact support for current pricing.</p>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Service address</h2>
  <ul class="kv">
    <li><span>Address</span><strong><?= $h($user['address'] ?: '—') ?></strong></li>
    <?php if (!empty($user['lat']) && !empty($user['lng'])): ?>
      <li><span>Coordinates</span><strong><?= $h(number_format((float)$user['lat'], 6)) ?>, <?= $h(number_format((float)$user['lng'], 6)) ?></strong></li>
    <?php endif; ?>
  </ul>
  <p class="muted small" style="margin-top:10px;">Need to update your address? Email or phone our team — we'll arrange a re-survey if the new spot is in coverage.</p>
</div>

<?php if ($sector_row || $tower_row): ?>
<div class="portal-card">
  <h2>How you're connected</h2>
  <ul class="kv">
    <?php if ($sector_row): ?>
      <li><span>Sector</span><strong><?= $h($sector_row['name']) ?></strong></li>
      <?php if (!empty($sector_row['tower_name'])): ?>
        <li><span>Tower</span><strong><?= $h($sector_row['tower_name']) ?></strong></li>
      <?php endif; ?>
      <?php if (!empty($sector_row['band'])): ?>
        <li><span>Band</span><strong><?= $h($sector_row['band']) ?></strong></li>
      <?php endif; ?>
      <?php if (!empty($sector_row['frequency_mhz'])): ?>
        <li><span>Frequency</span><strong><?= (int)$sector_row['frequency_mhz'] ?> MHz<?= !empty($sector_row['channel_width_mhz']) ? ' (' . (int)$sector_row['channel_width_mhz'] . ' MHz wide)' : '' ?></strong></li>
      <?php endif; ?>
      <?php if (!empty($sector_row['azimuth_deg'])): ?>
        <li><span>Azimuth</span><strong><?= (int)$sector_row['azimuth_deg'] ?>°</strong></li>
      <?php endif; ?>
    <?php elseif ($tower_row): ?>
      <li><span>Tower</span><strong><?= $h($tower_row['name']) ?></strong></li>
    <?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($link): ?>
<div class="portal-card">
  <h2>Wireless link <span class="muted small">(latest reading)</span></h2>
  <ul class="kv">
    <li><span>Connected to</span><strong><?= $h($link['ap_name']) ?><?= !empty($link['ap_model']) ? ' (' . $h($link['ap_model']) . ')' : '' ?></strong></li>
    <li><span>Signal</span><strong><?= $link['signal_dbm'] !== null ? (int)$link['signal_dbm'] . ' dBm' : '—' ?></strong></li>
    <li><span>SNR</span><strong><?= $link['snr_db'] !== null ? (int)$link['snr_db'] . ' dB' : '—' ?></strong></li>
    <?php if ($link['tx_rate_mbps'] !== null): ?>
      <li><span>TX rate</span><strong><?= number_format((float)$link['tx_rate_mbps'], 0) ?> Mbps</strong></li>
    <?php endif; ?>
    <?php if ($link['distance_km'] !== null): ?>
      <li><span>Distance</span><strong><?= number_format((float)$link['distance_km'], 2) ?> km</strong></li>
    <?php endif; ?>
    <?php if ($link['health_score'] !== null): ?>
      <li><span>Health score</span><strong><?= (int)$link['health_score'] ?> / 100</strong></li>
    <?php endif; ?>
    <li><span>Last reading</span><strong class="muted small"><?= $h($link['last_evaluated_at'] ?: 'never') ?></strong></li>
  </ul>
  <p class="muted small" style="margin-top:10px;">For live diagnostics and a self-serve speed test, head to <a href="/account/link-health.php">Link health</a>.</p>
</div>
<?php endif; ?>

<?php
// Equipment summary.  Merge what's on the user record (legacy fields) with
// the devices table so a client without an attached device row still sees
// what's on file.
$has_user_equip = !empty($user['equipment_mac'])
    || !empty($user['equipment_ip'])
    || !empty($user['equipment_serial'])
    || !empty($user['equipment_model']);
?>
<?php if ($has_user_equip || $customer_devices): ?>
<div class="portal-card">
  <h2>Your equipment</h2>
  <?php if ($has_user_equip): ?>
    <ul class="kv">
      <?php if (!empty($user['equipment_model'])): ?>
        <li><span>Model</span><strong><?= $h($user['equipment_model']) ?></strong></li>
      <?php endif; ?>
      <?php if (!empty($user['equipment_serial'])): ?>
        <li><span>Serial</span><strong><?= $h($user['equipment_serial']) ?></strong></li>
      <?php endif; ?>
      <?php if (!empty($user['equipment_mac'])): ?>
        <li><span>MAC address</span><strong><?= $h($user['equipment_mac']) ?></strong></li>
      <?php endif; ?>
      <?php if (!empty($user['equipment_ip'])): ?>
        <li><span>IP address</span><strong><?= $h($user['equipment_ip']) ?></strong></li>
      <?php endif; ?>
    </ul>
  <?php endif; ?>

  <?php if ($customer_devices): ?>
    <?php if ($has_user_equip): ?><h3 style="margin-top:18px;">Devices on your premises</h3><?php endif; ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Role</th><th>Vendor / model</th><th>MAC</th><th>Status</th><th>Last seen</th></tr></thead>
      <tbody>
        <?php foreach ($customer_devices as $d): ?>
          <tr>
            <td><?= $h($d['name']) ?></td>
            <td><?= $h($d['role']) ?></td>
            <td><?= $h(trim((string)$d['vendor'] . ' ' . (string)$d['model'])) ?: '—' ?></td>
            <td><?= $h($d['mac'] ?: '—') ?></td>
            <td><span class="status-pill status-<?= $h($d['status'] === 'online' ? 'paid' : ($d['status'] === 'offline' ? 'overdue' : 'cancelled')) ?>"><?= $h($d['status']) ?></span></td>
            <td class="muted small"><?= $h($d['last_seen_at'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <p class="muted small" style="margin-top:10px;">Equipment details are managed by our team. If anything looks off — wrong serial, mis-typed MAC — let us know via a support ticket.</p>
</div>
<?php endif; ?>

<?php if ($radius_username): ?>
<div class="portal-card">
  <h2>Network credentials</h2>
  <ul class="kv">
    <li><span>RADIUS username</span><strong><?= $h($radius_username) ?></strong></li>
    <li><span>Password</span><strong class="muted">— stored securely, contact support if you need it re-issued —</strong></li>
    <?php if (!empty($user['payment_method'])): ?>
      <li><span>Payment method</span><strong><?= $h(strtoupper((string)$user['payment_method'])) ?></strong></li>
    <?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<div class="portal-card">
  <h2>Need to make a change?</h2>
  <p class="muted">All package upgrades, address changes, equipment swaps and account-status changes go through our team so we can plan the install or move properly.</p>
  <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
    <a href="/account/tickets.php" class="btn btn-primary btn-sm">Open a support ticket</a>
    <a href="tel:0800111222" class="btn btn-ghost btn-sm">Call 0800 111 222</a>
    <a href="/account/profile.php" class="btn btn-ghost btn-sm">Update contact details</a>
  </div>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
