<?php
$page_title = 'My password';
$active_key = 'password';
require __DIR__ . '/_layout.php';

$errors = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current = (string)($_POST['current'] ?? '');
    $new     = (string)($_POST['new']     ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if (!password_verify($current, $user['password_hash'] ?? '')) $errors[] = 'Current password is wrong.';
    if (strlen($new) < 10)                                        $errors[] = 'New password must be at least 10 characters.';
    if ($new !== $confirm)                                        $errors[] = 'Passwords do not match.';

    if (!$errors) {
        update_user((int)$user['id'], function (array $u) use ($new) {
            $u['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            return $u;
        });
        flash('success', 'Password updated.');
        header('Location: /admin/password.php');
        exit;
    }
}
?>

<div class="portal-head">
  <h1>Change my password</h1>
  <p class="portal-sub">Use a strong, unique password &mdash; at least 10 characters. A passphrase works well.</p>
</div>

<div class="portal-card portal-card-narrow">
  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul style="margin:0; padding-left:18px;">
        <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?>
    <div class="field">
      <label for="current">Current password</label>
      <input type="password" id="current" name="current" required autocomplete="current-password">
    </div>
    <div class="field">
      <label for="new">New password</label>
      <input type="password" id="new" name="new" required minlength="10" autocomplete="new-password">
    </div>
    <div class="field">
      <label for="confirm">Confirm new password</label>
      <input type="password" id="confirm" name="confirm" required minlength="10" autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary">Update password</button>
  </form>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
