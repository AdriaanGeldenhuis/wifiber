<?php
$page_title = 'My account';
$active_key = 'dashboard';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/incidents.php';

$active_incidents = incidents_active_all();

// Network info for this customer — joined-up "Tower · Sector".
$pdo = pdo();
$network_label = null;
if (!empty($user['sector_id']) || !empty($user['site_id'])) {
    if (!empty($user['sector_id'])) {
        $stmt = $pdo->prepare(
            "SELECT s.name AS sector_name, t.name AS tower_name
               FROM sectors s LEFT JOIN sites t ON t.id = s.tower_id
              WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([(int)$user['sector_id']]);
        if ($r = $stmt->fetch()) {
            $network_label = $r['sector_name'];
            if ($r['tower_name']) $network_label .= ' · ' . $r['tower_name'];
        }
    }
    if (!$network_label && !empty($user['site_id'])) {
        $stmt = $pdo->prepare("SELECT name FROM sites WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['site_id']]);
        if ($r = $stmt->fetch()) $network_label = $r['name'];
    }
}

// Activity rollups — open tickets and unpaid invoices for this customer.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status IN ('open','in_progress')");
$stmt->execute([(int)$user['id']]);
$open_tickets_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM invoices WHERE user_id = ? AND status = 'unpaid'");
$stmt->execute([(int)$user['id']]);
$unpaid = $stmt->fetch();
$unpaid_count = (int)($unpaid['c'] ?? 0);
$unpaid_total = (float)($unpaid['t'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT number, total, due_at, status FROM invoices
      WHERE user_id = ? ORDER BY issued_at DESC, id DESC LIMIT 1"
);
$stmt->execute([(int)$user['id']]);
$latest_invoice = $stmt->fetch() ?: null;

/* ---------- Service quality (last 7 days) ----------
 * Pull health stats for the AP that drives this customer's sector.
 * Three numbers we surface:
 *   - uptime_pct: % of polls online
 *   - last_seen:  most recent online moment for the AP
 *   - signal_dbm: most recent reading for this customer's link, if any */
$service = null;
if (!empty($user['sector_id'])) {
    $stmt = $pdo->prepare(
        "SELECT ap_device_id FROM sectors WHERE id = ? LIMIT 1"
    );
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
        $h = $stmt->fetch() ?: [];
        $total = (int)($h['total'] ?? 0);
        if ($total > 0) {
            $up = (int)($h['up'] ?? 0);
            $service = [
                'uptime_pct' => round(($up / $total) * 100, 1),
                'last_up'    => $h['last_up'] ?? null,
                'avg_rtt'    => $h['avg_rtt'] !== null ? round((float)$h['avg_rtt'], 1) : null,
                'total'      => $total,
            ];
            // If wireless_links has a reading for this customer, surface it.
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
?>

<div class="portal-head">
  <h1>Welcome, <?= htmlspecialchars($user['name']) ?>.</h1>
  <p class="portal-sub">This is your WiFIBER account portal.</p>
</div>

<?php if (!empty($active_incidents)): ?>
  <div class="alert alert-error" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
    <div>
      <strong>Service issue:</strong>
      <?= htmlspecialchars($active_incidents[0]['title']) ?>
      <span class="muted small">
        — <?= htmlspecialchars(INCIDENT_STATUS_LABELS[$active_incidents[0]['status']] ?? $active_incidents[0]['status']) ?>
        <?php if (!empty($active_incidents[0]['affected'])): ?>
          &middot; <?= htmlspecialchars($active_incidents[0]['affected']) ?>
        <?php endif; ?>
      </span>
    </div>
    <a href="/status" class="btn btn-ghost btn-sm">View status &rarr;</a>
  </div>
<?php endif; ?>

<div class="card-grid">
  <?php if (!empty($user['account_no'])): ?>
    <div class="portal-card">
      <span class="card-label">Account number</span>
      <div class="card-num" style="font-size:1.4rem;line-height:1.2;"><?= htmlspecialchars($user['account_no']) ?></div>
      <p class="card-sub muted">Quote this when paying or contacting support.</p>
    </div>
  <?php endif; ?>
  <div class="portal-card">
    <span class="card-label">Your package</span>
    <div class="card-num" style="font-size:1.4rem;line-height:1.2;"><?= htmlspecialchars($user['package'] ?? '—') ?></div>
    <p class="card-sub muted">Need to upgrade or change? Call us on <a href="tel:0800111222">0800 111 222</a>.</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Service address</span>
    <div class="card-num" style="font-size:1rem;line-height:1.4;color:var(--text);"><?= htmlspecialchars($user['address'] ?? '—') ?></div>
    <p class="card-sub"><a href="/account/profile.php">Edit address &rarr;</a></p>
  </div>
  <?php if ($network_label): ?>
    <div class="portal-card">
      <span class="card-label">Connected via</span>
      <div class="card-num" style="font-size:1rem;line-height:1.4;color:var(--text);"><?= htmlspecialchars($network_label) ?></div>
      <p class="card-sub muted">Your local tower / sector.</p>
    </div>
  <?php endif; ?>
  <div class="portal-card">
    <span class="card-label">Member since</span>
    <div class="card-num" style="font-size:1.2rem;color:var(--text);"><?= htmlspecialchars(substr((string)($user['created_at'] ?? ''), 0, 10)) ?></div>
    <p class="card-sub muted">Last login: <?= htmlspecialchars($user['last_login'] ?? 'first time') ?></p>
  </div>
</div>

<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Unpaid invoices</span>
    <div class="card-num" style="color:<?= $unpaid_count > 0 ? '#fbbf24' : 'var(--accent)' ?>;"><?= $unpaid_count ?></div>
    <p class="card-sub muted">
      <?php if ($unpaid_count > 0): ?>
        R<?= number_format($unpaid_total, 2) ?> outstanding &middot;
      <?php endif; ?>
      <a href="/account/invoices.php">View invoices &rarr;</a>
    </p>
  </div>
  <div class="portal-card">
    <span class="card-label">Open tickets</span>
    <div class="card-num" style="color:<?= $open_tickets_count > 0 ? 'var(--accent)' : 'var(--text-muted)' ?>;"><?= $open_tickets_count ?></div>
    <p class="card-sub muted"><a href="/account/tickets.php">Open tickets &rarr;</a></p>
  </div>
  <?php if ($latest_invoice): ?>
    <div class="portal-card">
      <span class="card-label">Latest invoice</span>
      <div class="card-num" style="font-size:1.4rem;color:var(--text);">R<?= number_format((float)$latest_invoice['total'], 2) ?></div>
      <p class="card-sub muted">
        <?= htmlspecialchars($latest_invoice['number'] ?: '—') ?>
        &middot; due <?= htmlspecialchars($latest_invoice['due_at']) ?>
        &middot; <?= htmlspecialchars($latest_invoice['status']) ?>
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
        <?= htmlspecialchars((string)$service['uptime_pct']) ?>%
      </div>
      <p class="card-sub muted">across <?= (int)$service['total'] ?> health checks</p>
    </div>
    <?php if (!empty($service['avg_rtt'])): ?>
    <div class="portal-card" style="margin:0;">
      <span class="card-label">Avg latency</span>
      <div class="card-num"><?= htmlspecialchars((string)$service['avg_rtt']) ?> <small class="muted">ms</small></div>
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
    Last seen online: <?= htmlspecialchars($service['last_up'] ?? '—') ?>.
    Anything not looking right? <a href="/account/tickets.php">Open a ticket</a>.
  </p>
</div>
<?php endif; ?>

<div class="portal-card">
  <h2>Your details</h2>
  <ul class="kv">
    <li><span>Username</span><strong><?= htmlspecialchars($user['username']) ?></strong></li>
    <li><span>Name</span><strong><?= htmlspecialchars($user['name']) ?></strong></li>
    <?php if (!empty($user['email'])): ?>
      <li><span>Email</span><strong><?= htmlspecialchars($user['email']) ?></strong></li>
    <?php endif; ?>
    <?php if (!empty($user['phone'])): ?>
      <li><span>Phone</span><strong><?= htmlspecialchars($user['phone']) ?></strong></li>
    <?php endif; ?>
  </ul>
  <div style="margin-top:18px; display:flex; gap:10px;">
    <a href="/account/profile.php" class="btn btn-primary btn-sm">Edit profile</a>
    <a href="/account/password.php" class="btn btn-ghost btn-sm">Change password</a>
  </div>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
