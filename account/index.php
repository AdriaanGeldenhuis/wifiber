<?php
$page_title = 'My account';
$active_key = 'dashboard';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/incidents.php';

$active_incidents = incidents_active_all();
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
  <div class="portal-card">
    <span class="card-label">Member since</span>
    <div class="card-num" style="font-size:1.2rem;color:var(--text);"><?= htmlspecialchars(substr((string)($user['created_at'] ?? ''), 0, 10)) ?></div>
    <p class="card-sub muted">Last login: <?= htmlspecialchars($user['last_login'] ?? 'first time') ?></p>
  </div>
</div>

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

<div class="portal-card">
  <h2>Coming soon</h2>
  <p class="muted">More account features will appear here as we add them &mdash; invoices, support tickets and the rest.</p>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
