<?php
$page_title = 'Dashboard';
$active_key = 'dashboard';
require __DIR__ . '/_layout.php';

$users     = load_users();
$admins    = array_values(array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin'));
$clients   = array_values(array_filter($users, fn($u) => ($u['role'] ?? '') === 'client'));
$ip_list   = admin_ip_list();
?>

<div class="portal-head">
  <h1>Welcome, <?= htmlspecialchars($user['name']) ?>.</h1>
  <p class="portal-sub">You are signed in as an admin.</p>
</div>

<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Clients</span>
    <div class="card-num"><?= count($clients) ?></div>
    <a href="/admin/clients.php" class="card-link">Manage clients &rarr;</a>
  </div>
  <div class="portal-card">
    <span class="card-label">Admins</span>
    <div class="card-num"><?= count($admins) ?></div>
    <a href="/admin/admins.php" class="card-link">Manage admins &rarr;</a>
  </div>
  <div class="portal-card">
    <span class="card-label">IP allowlist</span>
    <div class="card-num"><?= count($ip_list) ?: '0' ?></div>
    <p class="card-sub muted">
      <?= empty($ip_list)
          ? 'Open access &mdash; admin reachable from any IP.'
          : 'Locked. Edit <code>data/admin-ips.json</code> to manage.' ?>
    </p>
  </div>
</div>

<div class="portal-card">
  <h2>Quick info</h2>
  <ul class="kv">
    <li><span>Your username</span><strong><?= htmlspecialchars($user['username']) ?></strong></li>
    <li><span>Your role</span><strong><?= htmlspecialchars($user['role']) ?></strong></li>
    <li><span>Last login</span><strong><?= htmlspecialchars($user['last_login'] ?? 'first time') ?></strong></li>
    <li><span>Your IP right now</span><strong><?= htmlspecialchars(client_ip()) ?></strong></li>
  </ul>
</div>

<div class="portal-card">
  <h2>What's here so far</h2>
  <p class="muted">This is the auth backend. Coming next: editable site settings, slider editor, pricing editor &mdash; all from this panel.</p>
  <p class="muted">For now you can: <strong>create &amp; manage clients</strong>, <strong>create &amp; manage admins</strong>, and <strong>change your own password</strong>.</p>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
