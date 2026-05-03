<?php
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/totp.php';

$portal = 'admin';
$page_title = 'Sign in';

if (!any_admin_exists()) {
    header('Location: /admin/setup.php');
    exit;
}

require_admin_ip();

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
        // Manual two-step: verify creds without finalising the session yet
        $candidate = find_user_by_username($username);
        $valid = $candidate
              && ($candidate['role'] ?? '') === 'admin'
              && password_verify($password, $candidate['password_hash'] ?? '');
        if (!$valid) {
            record_login_fail(client_ip());
            audit_log('login.fail', ['meta' => ['username' => $username, 'role' => 'admin']]);
            $error = 'Invalid username or password.';
        } else {
            // Step 2: if 2FA is enabled, divert to the TOTP page
            if (!empty($candidate['totp_enabled']) && !empty($candidate['totp_secret'])) {
                session_regenerate_id(true);
                $_SESSION['totp_pending_id']      = (int)$candidate['id'];
                $_SESSION['totp_pending_expires'] = time() + 300; // 5 minute window
                audit_log('login.password_ok_pending_2fa', [
                    'target_type' => 'user', 'target_id' => (int)$candidate['id'],
                    'meta' => ['username' => $candidate['username']],
                ]);
                header('Location: /admin/login-2fa.php');
                exit;
            }
            // Otherwise finalise login the same way attempt_login does
            reset_login_fails(client_ip());
            session_regenerate_id(true);
            $_SESSION['user_id']        = (int)$candidate['id'];
            $_SESSION['user_role']      = $candidate['role'];
            $_SESSION['user_name']      = $candidate['name'];
            $_SESSION['logged_in_at']   = time();
            $_SESSION['last_activity']  = time();
            update_user((int)$candidate['id'], function (array $u) {
                $u['last_login'] = date('c');
                return $u;
            });
            audit_log('login.success', [
                'target_type' => 'user', 'target_id' => (int)$candidate['id'],
                'meta' => ['role' => 'admin', '2fa' => false],
            ]);
            header('Location: /admin/');
            exit;
        }
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
    <a href="/admin/forgot.php">Forgot your password?</a>
  </p>
  <p class="portal-foot-link">
    Looking for the customer portal? <a href="/account/login.php">Sign in here</a>.
  </p>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
