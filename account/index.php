<?php
/**
 * Client dashboard — read-only landing page.
 *
 * Pulls together a one-screen summary of every section the customer
 * can drill into (service, billing, tickets, notifications) so they
 * can see what's happening at a glance and click through for detail.
 *
 * No write actions live on this page — every CTA links elsewhere.
 */

declare(strict_types=1);

$page_title  = 'My account';
$active_key  = 'dashboard';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/incidents.php';
require_once __DIR__ . '/../auth/invoices.php';
require_once __DIR__ . '/../auth/payments.php';
require_once __DIR__ . '/../auth/tickets.php';
require_once __DIR__ . '/../auth/products.php';

$pdo = pdo();
$h   = fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

$active_incidents = incidents_active_all();

/* ---------- Network / connection summary ---------- */
$network_label = null;
if (!empty($user['sector_id'])) {
    $stmt = $pdo->prepare(
        "SELECT s.name AS sector_name, t.name AS tower_name
           FROM sectors s LEFT JOIN sites t ON t.id = s.tower_id
          WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([(int)$user['sector_id']]);
    if ($r = $stmt->fetch()) {
        $network_label = $r['sector_name'];
        if (!empty($r['tower_name'])) $network_label .= ' · ' . $r['tower_name'];
    }
}
if (!$network_label && !empty($user['site_id'])) {
    $stmt = $pdo->prepare("SELECT name FROM sites WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$user['site_id']]);
    if ($r = $stmt->fetch()) $network_label = $r['name'];
}

/* ---------- Package / product (preferred over the legacy package text) ---------- */
$product = null;
if (!empty($user['product_id'])) {
    $product = products_find((int)$user['product_id']);
}
$package_label = $product
    ? $product['name'] . ' · ' . number_format((float)$product['down_mbps'], 0) . '/' . number_format((float)$product['up_mbps'], 0) . ' Mbps'
    : ($user['package'] ?? '—');
$package_price = $product ? 'R' . number_format((float)$product['monthly_price'], 2) . ' / month' : null;

/* ---------- Activity rollups ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status IN ('open','in_progress')");
$stmt->execute([(int)$user['id']]);
$open_tickets_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, subject, status, updated_at
       FROM tickets
      WHERE user_id = ?
      ORDER BY updated_at DESC, id DESC
      LIMIT 3"
);
$stmt->execute([(int)$user['id']]);
$recent_tickets = $stmt->fetchAll();

$balance = invoice_outstanding_balance((int)$user['id']);

$stmt = $pdo->prepare(
    "SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM invoices WHERE user_id = ? AND status = 'unpaid'"
);
$stmt->execute([(int)$user['id']]);
$unpaid = $stmt->fetch() ?: ['c' => 0, 't' => 0];
$unpaid_count = (int)$unpaid['c'];
$unpaid_total = (float)$unpaid['t'];

$stmt = $pdo->prepare(
    "SELECT id, number, total, due_at, status, issued_at
       FROM invoices
      WHERE user_id = ?
      ORDER BY issued_at DESC, id DESC
      LIMIT 1"
);
$stmt->execute([(int)$user['id']]);
$latest_invoice = $stmt->fetch() ?: null;

$stmt = $pdo->prepare(
    "SELECT amount, method, received_at
       FROM payments
      WHERE user_id = ? AND status = 'received'
      ORDER BY received_at DESC, id DESC
      LIMIT 1"
);
$stmt->execute([(int)$user['id']]);
$latest_payment = $stmt->fetch() ?: null;

/* ---------- Service quality (last 7 days) ---------- */
$service = null;
if (!empty($user['sector_id'])) {
    $stmt = $pdo->prepare("SELECT ap_device_id FROM sectors WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$user['sector_id']]);
    $ap_id = (int)($stmt->fetchColumn() ?: 0);
    if ($ap_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN status='online'  THEN 1 ELSE 0 END) AS up,
               SUM(CASE WHEN status='offline' THEN 1 ELSE 0 END) AS down,
               COUNT(*) AS total,
               MAX(CASE WHEN status='online' THEN polled_at ELSE NULL END) AS last_up,
               AVG(rtt_ms) AS avg_rtt
             FROM device_health
             WHERE device_id = ? AND polled_at >= (NOW() - INTERVAL 7 DAY)"
        );
        $stmt->execute([$ap_id]);
        $row = $stmt->fetch() ?: [];
        $total = (int)($row['total'] ?? 0);
        if ($total > 0) {
            $up = (int)($row['up'] ?? 0);
            $service = [
                'uptime_pct' => round(($up / $total) * 100, 1),
                'last_up'    => $row['last_up'] ?? null,
                'avg_rtt'    => $row['avg_rtt'] !== null ? round((float)$row['avg_rtt'], 1) : null,
                'total'      => $total,
            ];
            $stmt = $pdo->prepare(
                "SELECT signal_dbm, snr_db, ccq_pct, last_evaluated_at
                   FROM wireless_links
                  WHERE customer_id = ?
                  ORDER BY last_evaluated_at DESC LIMIT 1"
            );
            $stmt->execute([(int)$user['id']]);
            if ($wl = $stmt->fetch()) {
                $service['signal_dbm'] = $wl['signal_dbm'];
                $service['snr_db']     = $wl['snr_db'];
                $service['ccq_pct']    = $wl['ccq_pct'];
            }
        }
    }
}

/* ---------- Account status pill ---------- */
$status      = (string)($user['status'] ?? 'active');
$status_pill = match ($status) {
    'active'       => 'status-paid',
    'suspended'    => 'status-overdue',
    'disconnected' => 'status-cancelled',
    'lead'         => 'status-open',
    default        => 'status-open',
};
?>

<div class="portal-head">
  <h1>Welcome, <?= $h($user['name'] ?: $user['username']) ?>.</h1>
  <p class="portal-sub">
    Your account at a glance. Everything below is a read-only summary —
    use the navigation to drill into invoices, payments, tickets and
    your connection details.
  </p>
</div>

<?php if (!empty($active_incidents)): ?>
  <div class="alert alert-error" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
    <div>
      <strong>Service issue:</strong>
      <?= $h($active_incidents[0]['title']) ?>
      <span class="muted small">
        — <?= $h(INCIDENT_STATUS_LABELS[$active_incidents[0]['status']] ?? $active_incidents[0]['status']) ?>
        <?php if (!empty($active_incidents[0]['affected'])): ?>
          &middot; <?= $h($active_incidents[0]['affected']) ?>
        <?php endif; ?>
      </span>
    </div>
    <a href="/status" class="btn btn-ghost btn-sm">View status &rarr;</a>
  </div>
<?php endif; ?>

<!-- Top row: account headline + status -->
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Account</span>
    <div class="card-num" style="font-size:1.4rem;line-height:1.2;">
      <?= $h($user['account_no'] ?: $user['username']) ?>
    </div>
    <p class="card-sub muted">
      <span class="status-pill <?= $h($status_pill) ?>"><?= $h(ucfirst($status)) ?></span>
      &middot; <?= $h(ucfirst((string)($user['customer_type'] ?? 'residential'))) ?>
    </p>
  </div>
  <div class="portal-card">
    <span class="card-label">Package</span>
    <div class="card-num" style="font-size:1.2rem;line-height:1.3;color:var(--text);"><?= $h($package_label) ?></div>
    <p class="card-sub muted">
      <?php if ($package_price): ?>
        <?= $h($package_price) ?>
      <?php else: ?>
        Need to upgrade? Call <a href="tel:0800111222">0800 111 222</a>.
      <?php endif; ?>
    </p>
  </div>
  <?php if ($network_label): ?>
    <div class="portal-card">
      <span class="card-label">Connected via</span>
      <div class="card-num" style="font-size:1.1rem;line-height:1.3;color:var(--text);"><?= $h($network_label) ?></div>
      <p class="card-sub"><a href="/account/service.php">Connection details &rarr;</a></p>
    </div>
  <?php endif; ?>
  <div class="portal-card">
    <span class="card-label">Service address</span>
    <div class="card-num" style="font-size:.95rem;line-height:1.4;color:var(--text);"><?= $h($user['address'] ?: '—') ?></div>
    <p class="card-sub muted">Member since <?= $h(substr((string)($user['service_start'] ?: $user['created_at'] ?? ''), 0, 10)) ?></p>
  </div>
</div>

<!-- Activity row: balance / unpaid / open tickets / latest payment -->
<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Balance</span>
    <div class="card-num" style="color:<?= $balance['balance'] > 0 ? '#fbbf24' : 'var(--success)' ?>;">R<?= number_format((float)$balance['balance'], 2) ?></div>
    <p class="card-sub muted">
      <?= $unpaid_count ?> unpaid invoice<?= $unpaid_count === 1 ? '' : 's' ?>
      <?php if ($balance['credit'] > 0): ?>
        &middot; R<?= number_format((float)$balance['credit'], 2) ?> credit
      <?php endif; ?>
      <br><a href="/account/invoices.php">View invoices &rarr;</a>
    </p>
  </div>
  <div class="portal-card">
    <span class="card-label">Open tickets</span>
    <div class="card-num" style="color:<?= $open_tickets_count > 0 ? 'var(--accent)' : 'var(--text-muted)' ?>;"><?= $open_tickets_count ?></div>
    <p class="card-sub muted"><a href="/account/tickets.php">Open or log a ticket &rarr;</a></p>
  </div>
  <?php if ($latest_invoice): ?>
    <div class="portal-card">
      <span class="card-label">Latest invoice</span>
      <div class="card-num" style="font-size:1.4rem;color:var(--text);">R<?= number_format((float)$latest_invoice['total'], 2) ?></div>
      <p class="card-sub muted">
        <a href="/account/invoices.php?id=<?= (int)$latest_invoice['id'] ?>"><?= $h($latest_invoice['number'] ?: '#' . (int)$latest_invoice['id']) ?></a>
        &middot; due <?= $h($latest_invoice['due_at']) ?>
        <br><span class="status-pill status-<?= $h($latest_invoice['status']) ?>"><?= $h($latest_invoice['status']) ?></span>
      </p>
    </div>
  <?php endif; ?>
  <?php if ($latest_payment): ?>
    <div class="portal-card">
      <span class="card-label">Last payment</span>
      <div class="card-num" style="font-size:1.4rem;color:var(--success);">R<?= number_format((float)$latest_payment['amount'], 2) ?></div>
      <p class="card-sub muted">
        <?= $h(strtoupper((string)$latest_payment['method'])) ?>
        &middot; <?= $h(substr((string)$latest_payment['received_at'], 0, 10)) ?>
        <br><a href="/account/payments.php">Full history &rarr;</a>
      </p>
    </div>
  <?php endif; ?>
</div>

<?php if ($service): ?>
<div class="portal-card">
  <h2>Service quality <span class="muted small">(last 7 days)</span></h2>
  <div class="card-grid" style="margin-top:8px;">
    <div class="portal-card" style="margin:0;">
      <span class="card-label">Uptime</span>
      <div class="card-num" style="color:<?= $service['uptime_pct'] >= 99 ? '#0c8' : ($service['uptime_pct'] >= 95 ? '#fbbf24' : '#d44') ?>;">
        <?= $h((string)$service['uptime_pct']) ?>%
      </div>
      <p class="card-sub muted">across <?= (int)$service['total'] ?> health checks</p>
    </div>
    <?php if (!empty($service['avg_rtt'])): ?>
    <div class="portal-card" style="margin:0;">
      <span class="card-label">Avg latency</span>
      <div class="card-num"><?= $h((string)$service['avg_rtt']) ?> <small class="muted">ms</small></div>
      <p class="card-sub muted">round-trip from our gear</p>
    </div>
    <?php endif; ?>
    <?php if (!empty($service['signal_dbm'])):
      $s = (int)$service['signal_dbm'];
      $sig_class = $s >= -65 ? '#0c8' : ($s >= -75 ? '#fbbf24' : '#d44');
    ?>
    <div class="portal-card" style="margin:0;">
      <span class="card-label">Signal</span>
      <div class="card-num" style="color:<?= $sig_class ?>;"><?= $s ?> <small class="muted">dBm</small></div>
      <?php if (!empty($service['snr_db'])): ?>
        <p class="card-sub muted">SNR <?= (int)$service['snr_db'] ?> dB</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <p class="muted small" style="margin-top:8px;">
    Last seen online: <?= $h($service['last_up'] ?? '—') ?>.
    Anything not looking right? <a href="/account/tickets.php">Open a ticket</a>
    or check your <a href="/account/link-health.php">link health</a>.
  </p>
</div>
<?php endif; ?>

<?php if ($recent_tickets): ?>
<div class="portal-card">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap;">
    <h2 style="margin:0;">Recent tickets</h2>
    <a href="/account/tickets.php" class="card-link">All tickets &rarr;</a>
  </div>
  <div class="table-scroll" style="margin-top:12px;">
  <table class="data-table">
    <thead>
      <tr><th>#</th><th>Subject</th><th>Status</th><th>Last update</th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent_tickets as $t): ?>
        <tr>
          <td>#<?= (int)$t['id'] ?></td>
          <td><a href="/account/tickets.php?id=<?= (int)$t['id'] ?>"><?= $h($t['subject']) ?></a></td>
          <td><span class="status-pill status-<?= $h($t['status']) ?>"><?= $h(TICKET_STATUS_LABELS[$t['status']] ?? $t['status']) ?></span></td>
          <td class="muted small"><?= $h(substr((string)$t['updated_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="portal-card">
  <h2>What you can do here</h2>
  <p class="muted" style="margin-bottom:14px;">
    Your portal is read-only for everything that affects the network or
    your billing — that protects you from accidental changes. To make
    a change, log a ticket or call us. Here's what's at your fingertips:
  </p>
  <div class="card-grid" style="margin-bottom:0;">
    <div class="portal-card" style="margin:0;">
      <strong>Service & equipment</strong>
      <p class="card-sub muted">See your package, signal, sector, equipment and how you're connected.</p>
      <a class="card-link" href="/account/service.php">View service details &rarr;</a>
    </div>
    <div class="portal-card" style="margin:0;">
      <strong>Billing</strong>
      <p class="card-sub muted">Browse invoices, see what we've received, print a statement.</p>
      <a class="card-link" href="/account/invoices.php">Invoices</a>
      &middot; <a class="card-link" href="/account/payments.php">Payments</a>
      &middot; <a class="card-link" href="/account/statement.php">Statement</a>
    </div>
    <div class="portal-card" style="margin:0;">
      <strong>Support</strong>
      <p class="card-sub muted">Log a new ticket, reply to ours, see what we've sent you.</p>
      <a class="card-link" href="/account/tickets.php">Tickets</a>
      &middot; <a class="card-link" href="/account/notifications.php">Notifications</a>
    </div>
    <div class="portal-card" style="margin:0;">
      <strong>Account</strong>
      <p class="card-sub muted">Update your contact details and change your password.</p>
      <a class="card-link" href="/account/profile.php">My profile</a>
      &middot; <a class="card-link" href="/account/password.php">Password</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
