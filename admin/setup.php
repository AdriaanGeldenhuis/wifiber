<?php
require_once __DIR__ . '/../auth/helpers.php';

$portal = 'admin';
$page_title = 'Setup';

// Block setup once an admin user exists
if (any_admin_exists()) {
    $page_title = 'Already set up';
    require __DIR__ . '/../auth/portal-header.php';
    ?>
    <div class="portal-card">
      <h1>WiFIBER admin is already set up.</h1>
      <p>An admin account already exists. For security, this setup page is disabled.</p>
      <p><a href="/admin/login.php" class="btn btn-primary">Go to login</a></p>
      <p style="margin-top:24px; color:var(--text-muted); font-size:.85rem;">
        Need to reset? SSH into the server and edit <code>data/users.json</code>, or delete it and reload this page.
      </p>
    </div>
    <?php
    require __DIR__ . '/../auth/portal-footer.php';
    exit;
}

$errors = [];
$values = ['username' => '', 'name' => '', 'email' => '', 'lock_ip' => '1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $values['username'] = trim($_POST['username'] ?? '');
    $values['name']     = trim($_POST['name']     ?? '');
    $values['email']    = trim($_POST['email']    ?? '');
    $values['lock_ip']  = isset($_POST['lock_ip']) ? '1' : '0';
    $password           = (string)($_POST['password'] ?? '');
    $confirm            = (string)($_POST['confirm']  ?? '');

    if ($values['username'] === '' || strlen($values['username']) < 3) $errors[] = 'Username must be at least 3 characters.';
    if ($values['name']     === '')                                    $errors[] = 'Please enter a display name.';
    if ($values['email']    !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
    if (strlen($password) < 10)                                        $errors[] = 'Password must be at least 10 characters.';
    if ($password !== $confirm)                                        $errors[] = 'Passwords do not match.';

    if (!$errors) {
        try {
            create_user(
                $values['username'],
                $password,
                'admin',
                $values['name'],
                $values['email']
            );
            if ($values['lock_ip'] === '1') {
                save_admin_ips([client_ip()]);
            }
            flash('success', 'Admin account created. Please log in.');
            header('Location: /admin/login.php');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

require __DIR__ . '/../auth/portal-header.php';
?>
<div class="portal-card portal-card-narrow">
  <h1>Set up your admin account</h1>
  <p class="portal-sub">This page only works once. After you submit it, the first admin account is created and this form will be disabled.</p>

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
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required minlength="3" maxlength="60"
             value="<?= htmlspecialchars($values['username']) ?>" autocomplete="username">
    </div>

    <div class="field">
      <label for="name">Display name</label>
      <input type="text" id="name" name="name" required maxlength="100"
             value="<?= htmlspecialchars($values['name']) ?>">
    </div>

    <div class="field">
      <label for="email">Email <span class="muted">(optional)</span></label>
      <input type="email" id="email" name="email" maxlength="120"
             value="<?= htmlspecialchars($values['email']) ?>">
    </div>

    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
      <small class="muted">At least 10 characters. Use a passphrase.</small>
    </div>

    <div class="field">
      <label for="confirm">Confirm password</label>
      <input type="password" id="confirm" name="confirm" required minlength="10" autocomplete="new-password">
    </div>

    <div class="field-check">
      <input type="checkbox" id="lock_ip" name="lock_ip" value="1" <?= $values['lock_ip'] === '1' ? 'checked' : '' ?>>
      <label for="lock_ip">
        Lock admin to my current IP (<code><?= htmlspecialchars(client_ip()) ?></code>)<br>
        <small class="muted">Recommended. You can manage the IP list later via <code>data/admin-ips.json</code>.</small>
      </label>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Create admin account</button>
  </form>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
