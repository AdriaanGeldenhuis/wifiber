<?php
require_once __DIR__ . '/../auth/helpers.php';

$portal = 'admin';
$page_title = 'Sign in';

// Force setup first if no admin exists
if (!any_admin_exists()) {
    header('Location: /admin/setup.php');
    exit;
}

// IP allowlist (admin-only)
require_admin_ip();

// Already logged in?
$user = current_user();
if ($user && ($user['role'] ?? '') === 'admin') {
    header('Location: /admin/');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (is_locked_out(client_ip())) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $user = attempt_login($username, $password, 'admin');
        if ($user) {
            header('Location: /admin/');
            exit;
        }
        $error = 'Invalid username or password.';
    }
}

require __DIR__ . '/../auth/portal-header.php';
?>
<div class="portal-card portal-card-narrow">
  <h1>Admin sign in</h1>
  <p class="portal-sub">WiFIBER staff only.</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" class="form">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus
             value="<?= htmlspecialchars($username) ?>" autocomplete="username">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>

  <p class="portal-foot-link">
    Looking for the customer portal? <a href="/account/login.php">Sign in here</a>.
  </p>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
