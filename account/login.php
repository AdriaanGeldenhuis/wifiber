<?php
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/totp.php';

$portal = 'account';
$page_title = 'Sign in';

// Where each role lands once authenticated. Clients see the customer
// portal (their own dashboard); staff bounce into the admin panel,
// since that's where their work lives. Both are reachable from the
// native app's WebView.
$home_for = function (string $role): string {
    return $role === 'client' ? '/account/' : '/admin/';
};

$user = current_user();
if ($user) {
    header('Location: ' . $home_for((string)($user['role'] ?? '')));
    exit;
}

$error = '';
$username = '';

// Allow any registered role to sign in here — clients plus every staff
// role. The native app uses this single endpoint for everyone, which
// keeps the technician-with-FCM-token flow simple.
$allowed_roles = array_merge(ACL_STAFF_ROLES_FALLBACK, ['client']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (is_locked_out(client_ip())) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        // Manual two-step (mirrors /admin/login.php): verify creds
        // first so we can divert TOTP-enabled accounts before
        // finalising the session.
        $candidate = find_user_by_username($username);
        $valid = $candidate
              && in_array($candidate['role'] ?? '', $allowed_roles, true)
              && password_verify($password, $candidate['password_hash'] ?? '');
        if (!$valid) {
            record_login_fail(client_ip());
            audit_log('login.fail', ['meta' => ['username' => $username, 'via' => 'account']]);
            $error = 'Invalid username or password.';
        } else {
            $is_staff = in_array($candidate['role'] ?? '', ACL_STAFF_ROLES_FALLBACK, true);
            // 2FA gate — staff only. Clients haven't historically
            // been forced through TOTP from the customer portal and
            // we keep that behaviour intact.
            if ($is_staff && !empty($candidate['totp_enabled']) && !empty($candidate['totp_secret'])) {
                session_regenerate_id(true);
                $_SESSION['totp_pending_id']      = (int)$candidate['id'];
                $_SESSION['totp_pending_expires'] = time() + 300; // 5 minutes
                audit_log('login.password_ok_pending_2fa', [
                    'target_type' => 'user', 'target_id' => (int)$candidate['id'],
                    'meta' => ['username' => $candidate['username'], 'via' => 'account'],
                ]);
                header('Location: /admin/login-2fa.php');
                exit;
            }
            // Finalise the session inline so we can route by role.
            reset_login_fails(client_ip());
            session_regenerate_id(true);
            $_SESSION['user_id']      = (int)$candidate['id'];
            $_SESSION['user_role']    = $candidate['role'];
            $_SESSION['user_name']    = $candidate['name'];
            $_SESSION['logged_in_at'] = time();
            update_user((int)$candidate['id'], function (array $u) {
                $u['last_login'] = date('c');
                return $u;
            });
            audit_log('login.success', [
                'target_type' => 'user', 'target_id' => (int)$candidate['id'],
                'meta' => ['role' => $candidate['role'], '2fa' => false, 'via' => 'account'],
            ]);
            header('Location: ' . $home_for((string)$candidate['role']));
            exit;
        }
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
