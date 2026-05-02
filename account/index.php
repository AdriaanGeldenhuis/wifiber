<?php
$page_title = 'My account';
$active_key = 'dashboard';
require __DIR__ . '/_layout.php';
?>

<div class="portal-head">
  <h1>Welcome, <?= htmlspecialchars($user['name']) ?>.</h1>
  <p class="portal-sub">This is your WiFIBER account portal.</p>
</div>

<div class="portal-card">
  <h2>Your details</h2>
  <ul class="kv">
    <li><span>Username</span><strong><?= htmlspecialchars($user['username']) ?></strong></li>
    <li><span>Name</span><strong><?= htmlspecialchars($user['name']) ?></strong></li>
    <?php if (!empty($user['email'])): ?>
      <li><span>Email</span><strong><?= htmlspecialchars($user['email']) ?></strong></li>
    <?php endif; ?>
    <li><span>Member since</span><strong><?= htmlspecialchars($user['created_at'] ?? '') ?></strong></li>
    <li><span>Last login</span><strong><?= htmlspecialchars($user['last_login'] ?? 'first time') ?></strong></li>
  </ul>
</div>

<div class="portal-card">
  <h2>Coming soon</h2>
  <p class="muted">More account features will appear here as we add them &mdash; package details, invoices, support tickets and the rest.</p>
  <p class="muted">For now you can <a href="/account/password.php">change your password</a> or <a href="/account/logout.php">log out</a>.</p>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
