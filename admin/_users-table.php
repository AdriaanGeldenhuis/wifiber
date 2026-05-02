<?php
/**
 * Reusable list+create+edit+delete UI for users of a given role.
 *
 * Posts to itself with action=create | reset_password | delete.
 * Output is the page body — caller is responsible for layout header/footer.
 */

function render_users_admin(string $role, string $heading, string $subtitle, array $current_user): void {
    $self = strtok($_SERVER['REQUEST_URI'], '?');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $name     = trim($_POST['name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $errors = [];
            if (strlen($username) < 3)                                       $errors[] = 'Username must be at least 3 characters.';
            if ($name === '')                                                $errors[] = 'Display name is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
            if (strlen($password) < 8)                                       $errors[] = 'Password must be at least 8 characters.';
            if (!$errors) {
                try {
                    create_user($username, $password, $role, $name, $email);
                    flash('success', ucfirst($role) . " '{$username}' created.");
                } catch (Throwable $e) {
                    flash('error', $e->getMessage());
                }
            } else {
                flash('error', implode(' ', $errors));
            }
            header('Location: ' . $self);
            exit;
        }

        if ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                flash('error', 'Password must be at least 8 characters.');
            } else {
                $ok = update_user($id, function (array $u) use ($password) {
                    $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    return $u;
                });
                flash($ok ? 'success' : 'error', $ok ? 'Password updated.' : 'User not found.');
            }
            header('Location: ' . $self);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$current_user['id']) {
                flash('error', "You can't delete your own account.");
            } else {
                $users = array_values(array_filter(load_users(), fn($u) => (int)($u['id'] ?? 0) !== $id));
                save_users($users);
                flash('success', 'User deleted.');
            }
            header('Location: ' . $self);
            exit;
        }
    }

    $users = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === $role));
    ?>
    <div class="portal-head">
      <h1><?= htmlspecialchars($heading) ?></h1>
      <p class="portal-sub"><?= htmlspecialchars($subtitle) ?></p>
    </div>

    <div class="portal-card">
      <h2>Existing accounts</h2>
      <?php if (empty($users)): ?>
        <p class="muted">No <?= htmlspecialchars($role) ?>s yet.</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Username</th>
              <th>Name</th>
              <th>Email</th>
              <th>Last login</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                <td><?= htmlspecialchars($u['name'] ?? '') ?></td>
                <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td class="muted"><?= htmlspecialchars($u['last_login'] ?? 'never') ?></td>
                <td class="row-actions">
                  <details>
                    <summary>Reset pw</summary>
                    <form method="post" class="inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reset_password">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <input type="password" name="password" placeholder="new password (8+ chars)" minlength="8" required>
                      <button class="btn btn-ghost btn-sm" type="submit">Save</button>
                    </form>
                  </details>
                  <?php if ((int)$u['id'] !== (int)$current_user['id']): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Delete <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                  <?php else: ?>
                    <span class="muted small">(you)</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="portal-card">
      <h2>Create new <?= htmlspecialchars($role) ?></h2>
      <form method="post" class="form form-grid" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field">
          <label>Username</label>
          <input type="text" name="username" required minlength="3" maxlength="60">
        </div>
        <div class="field">
          <label>Display name</label>
          <input type="text" name="name" required maxlength="100">
        </div>
        <div class="field">
          <label>Email <span class="muted">(optional)</span></label>
          <input type="email" name="email" maxlength="120">
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Create <?= htmlspecialchars($role) ?></button>
        </div>
      </form>
    </div>
    <?php
}
