<?php
require_once __DIR__ . '/../auth/helpers.php';

$portal = 'admin';
$page_title = 'Reset password';

require_admin_ip();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$found = $token !== '' ? pw_reset_lookup($token) : null;
// Only admin tokens are valid in the admin portal.
$valid = $found && (($found['user']['role'] ?? '') === 'admin');

$errors = [];

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $new     = (string)($_POST['new']     ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    // Admins must use stronger passwords than clients (matches admin/setup.php).
    if (strlen($new) < 10)  $errors[] = 'New password must be at least 10 characters.';
    if ($new !== $confirm)  $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $u = pw_reset_consume($token);
        if ($u) {
            update_user((int)$u['id'], function (array $row) use ($new) {
                $row['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                return $row;
            });
            pw_reset_invalidate_for_user((int)$u['id']);
            audit_log('password.reset', [
                'user_id'     => (int)$u['id'],
                'username'    => (string)$u['username'],
                'target_type' => 'user',
                'target_id'   => (int)$u['id'],
                'meta'        => ['portal' => 'admin'],
            ]);
            flash('success', 'Password updated. Please sign in with your new password.');
            header('Location: /admin/login.php');
            exit;
        }
        // Token consumed by another tab between lookup and submit.
        $valid = false;
    }
}

require __DIR__ . '/../auth/portal-header.php';
?>
<div class="portal-card portal-card-narrow">
  <h1>Reset your admin password</h1>

  <?php if (!$valid): ?>
    <div class="alert alert-error">
      This reset link is invalid or has expired. Reset links are good for 1 hour and can only be used once.
    </div>
    <p class="portal-foot-link">
      <a href="/admin/forgot.php">Request a new link</a> &middot;
      <a href="/admin/login.php">Back to sign in</a>
    </p>
  <?php else: ?>
    <p class="portal-sub">Pick a new password &mdash; at least 10 characters. Use a passphrase.</p>
    <?php if (!empty($found['user']['totp_enabled'])): ?>
      <p class="portal-sub">Two-factor stays on; you'll still need your authenticator code when you sign in.</p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <ul style="margin:0; padding-left:18px;">
          <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="form" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="field">
        <label for="new">New password</label>
        <input type="password" id="new" name="new" required minlength="10" autocomplete="new-password" autofocus>
      </div>
      <div class="field">
        <label for="confirm">Confirm new password</label>
        <input type="password" id="confirm" name="confirm" required minlength="10" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Set new password</button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
