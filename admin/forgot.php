<?php
require_once __DIR__ . '/../auth/helpers.php';

$portal = 'admin';
$page_title = 'Forgot password';

require_admin_ip();

$user = current_user();
if ($user && ($user['role'] ?? '') === 'admin') {
    header('Location: /admin/');
    exit;
}

$submitted = false;
$error = '';
$entered = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $entered = trim($_POST['username'] ?? '');

    if (is_locked_out(client_ip())) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif ($entered === '') {
        $error = 'Please enter your username or email.';
    } else {
        $u = find_user_by_username_or_email($entered);
        if ($u && ($u['role'] ?? '') === 'admin') {
            $token = pw_reset_create_token((int)$u['id']);
            send_password_reset_email($u, $token, '/admin/reset.php');
        }
        // Generic response either way to avoid revealing whether the account exists.
        $submitted = true;
    }
}

require __DIR__ . '/../auth/portal-header.php';
?>
<div class="portal-card portal-card-narrow">
  <h1>Forgot your password?</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($submitted): ?>
    <div class="alert alert-success">
      If an admin account matches that username or email, we've sent a link to reset the password.
      The link is good for 1 hour.
    </div>
    <p class="portal-foot-link"><a href="/admin/login.php">Back to admin sign in</a></p>
  <?php else: ?>
    <p class="portal-sub">Enter your admin username or email and we'll send a link to set a new password.</p>

    <form method="post" class="form">
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Username or email</label>
        <input type="text" id="username" name="username" required autofocus
               value="<?= htmlspecialchars($entered) ?>" autocomplete="username">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
    </form>
    <p class="portal-foot-link"><a href="/admin/login.php">Back to admin sign in</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
