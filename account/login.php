<?php
require_once __DIR__ . '/../auth/helpers.php';

$portal = 'account';
$page_title = 'Sign in';

$user = current_user();
if ($user && ($user['role'] ?? '') === 'client') {
    header('Location: /account/');
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
        $user = attempt_login($username, $password, 'client');
        if ($user) {
            header('Location: /account/');
            exit;
        }
        $error = 'Invalid username or password.';
    }
}

require __DIR__ . '/../auth/portal-header.php';
?>
<div class="portal-card portal-card-narrow">
  <h1>My WiFIBER account</h1>
  <p class="portal-sub">Sign in to manage your account.</p>

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
    <a href="/account/forgot.php">Forgot your password?</a>
  </p>
  <p class="portal-foot-link">
    Don't have an account? Give us a call on <a href="tel:0800111222">0800 111 222</a> and we'll set you up.
  </p>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
