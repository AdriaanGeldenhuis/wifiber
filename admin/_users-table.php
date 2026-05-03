<?php
/**
 * Reusable list+create+edit+delete UI for users of a given role.
 *
 * Posts to itself with action=create | update | reset_password | delete.
 * Output is the page body — caller is responsible for layout header/footer.
 */

function render_users_admin(string $role, string $heading, string $subtitle, array $current_user): void {
    $self = strtok($_SERVER['REQUEST_URI'], '?');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $name     = trim($_POST['name']     ?? '');
            $surname  = trim($_POST['surname']  ?? '');
            $email    = trim($_POST['email']    ?? '');
            $password = (string)($_POST['password'] ?? '');
            $errors   = [];
            if (strlen($username) < 3)                                       $errors[] = 'Username must be at least 3 characters.';
            if ($name === '')                                                $errors[] = 'Display name is required.';
            if ($role === 'client' && $surname === '')                       $errors[] = 'Surname is required so we can issue an account number.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
            if (strlen($password) < 8)                                       $errors[] = 'Password must be at least 8 characters.';
            $created = null;
            if (!$errors) {
                try {
                    $created = create_user($username, $password, $role, $name, $email, [
                        'phone'         => $_POST['phone']         ?? '',
                        'address'       => $_POST['address']       ?? '',
                        'package'       => $_POST['package']       ?? '',
                        'surname'       => $surname,
                        'customer_type' => $_POST['customer_type'] ?? 'residential',
                    ]);
                    $msg = ucfirst($role) . " '{$username}' created";
                    if (!empty($created['account_no'])) {
                        $msg .= " (account #{$created['account_no']})";
                    }
                    $msg .= '.';
                    $email_ok = true;
                    if (!empty($_POST['send_welcome'])) {
                        $r = send_welcome_email($created, $password);
                        $email_ok = $r['ok'];
                        $msg .= $r['ok']
                            ? " Welcome email sent to {$created['email']}."
                            : " (Welcome email could not be sent: {$r['reason']}.)";
                    }
                    flash($email_ok ? 'success' : 'error', $msg);
                } catch (Throwable $e) {
                    flash('error', $e->getMessage());
                }
            } else {
                flash('error', implode(' ', $errors));
            }
            // Send admins straight to the rich edit page so they can fill in
            // the rest of the client record (ID, GPS, equipment, etc.).
            if ($role === 'client' && $created && !empty($created['id'])) {
                header('Location: /admin/client-edit.php?id=' . (int)$created['id']);
            } else {
                header('Location: ' . $self);
            }
            exit;
        }

        if ($action === 'update') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name']  ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $addr  = trim($_POST['address'] ?? '');
            $pkg   = trim($_POST['package'] ?? '');
            if ($name === '') {
                flash('error', 'Display name is required.');
                header('Location: ' . $self);
                exit;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Email is not valid.');
                header('Location: ' . $self);
                exit;
            }
            $ok = update_user($id, function (array $u) use ($name, $email, $phone, $addr, $pkg) {
                $u['name']    = $name;
                $u['email']   = $email;
                $u['phone']   = $phone;
                $u['address'] = $addr;
                $u['package'] = $pkg;
                return $u;
            });
            flash($ok ? 'success' : 'error', $ok ? 'Account updated.' : 'User not found.');
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
                $msg = $ok ? 'Password updated.' : 'User not found.';
                if ($ok) {
                    audit_log('admin.password_reset', [
                        'target_type' => 'user',
                        'target_id'   => $id,
                        'meta'        => ['emailed' => !empty($_POST['send_email'])],
                    ]);
                }
                if ($ok && !empty($_POST['send_email'])) {
                    $u = find_user_by_id($id);
                    if ($u) {
                        $r = send_welcome_email($u, $password);
                        $msg .= $r['ok'] ? " Email sent to {$u['email']}." : " (Email failed: {$r['reason']}.)";
                    }
                }
                flash($ok ? 'success' : 'error', $msg);
            }
            header('Location: ' . $self);
            exit;
        }

        if ($action === 'send_welcome') {
            $id = (int)($_POST['id'] ?? 0);
            $newpw = bin2hex(random_bytes(5));
            update_user($id, function (array $u) use ($newpw) {
                $u['password_hash'] = password_hash($newpw, PASSWORD_DEFAULT);
                return $u;
            });
            audit_log('admin.welcome_resend', ['target_type' => 'user', 'target_id' => $id]);
            $u = find_user_by_id($id);
            if ($u) {
                $r = send_welcome_email($u, $newpw);
                flash($r['ok'] ? 'success' : 'error',
                    $r['ok']
                        ? "Welcome email sent to {$u['email']}. New temp password: {$newpw}"
                        : "Could not send email ({$r['reason']}). Temp password set anyway: {$newpw}");
            }
            header('Location: ' . $self);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$current_user['id']) {
                flash('error', "You can't delete your own account.");
            } else {
                delete_user($id);
                flash('success', 'User deleted.');
            }
            header('Location: ' . $self);
            exit;
        }
    }

    $users = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === $role));
    $is_client_view = ($role === 'client');
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
        <?php foreach ($users as $u): ?>
          <details class="user-row">
            <summary>
              <?php if ($is_client_view && !empty($u['account_no'])): ?>
                <strong><?= htmlspecialchars($u['account_no']) ?></strong>
                <span class="muted">&middot; <?= htmlspecialchars($u['username']) ?></span>
              <?php else: ?>
                <strong><?= htmlspecialchars($u['username']) ?></strong>
              <?php endif; ?>
              <span class="muted">&middot; <?= htmlspecialchars($u['name'] ?? '') ?></span>
              <?php if ($is_client_view && !empty($u['package'])): ?>
                <span class="pkg-pill"><?= htmlspecialchars($u['package']) ?></span>
              <?php endif; ?>
              <?php if ($is_client_view && !empty($u['status']) && $u['status'] !== 'active'): ?>
                <span class="pkg-pill" style="background:#552;"><?= htmlspecialchars($u['status']) ?></span>
              <?php endif; ?>
              <span class="muted small" style="margin-left:auto;">
                last login: <?= htmlspecialchars($u['last_login'] ?? 'never') ?>
              </span>
            </summary>

            <div class="user-row-body">
              <?php if ($is_client_view): ?>
                <ul class="kv">
                  <li><span>Account #</span><strong><?= htmlspecialchars($u['account_no'] ?: '— pending — open editor to issue one') ?></strong></li>
                  <li><span>Type</span><strong><?= htmlspecialchars($u['customer_type'] ?? 'residential') ?></strong></li>
                  <li><span>Status</span><strong><?= htmlspecialchars($u['status'] ?? 'active') ?></strong></li>
                  <?php if (!empty($u['email'])): ?><li><span>Email</span><strong><?= htmlspecialchars($u['email']) ?></strong></li><?php endif; ?>
                  <?php if (!empty($u['phone'])): ?><li><span>Phone</span><strong><?= htmlspecialchars($u['phone']) ?></strong></li><?php endif; ?>
                  <?php if (!empty($u['address'])): ?><li><span>Address</span><strong><?= htmlspecialchars($u['address']) ?></strong></li><?php endif; ?>
                </ul>
                <div class="form-actions" style="margin-top:14px;">
                  <a href="/admin/client-edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-primary btn-sm">Edit full details</a>
                </div>
              <?php else: ?>
                <form method="post" class="form form-grid">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">

                  <div class="field"><label>Display name</label>
                    <input type="text" name="name" required maxlength="100" value="<?= htmlspecialchars($u['name'] ?? '', ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Email</label>
                    <input type="email" name="email" maxlength="120" value="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Phone</label>
                    <input type="tel" name="phone" maxlength="40" value="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES) ?>">
                  </div>
                  <input type="hidden" name="package" value="<?= htmlspecialchars($u['package'] ?? '', ENT_QUOTES) ?>">
                  <input type="hidden" name="address" value="<?= htmlspecialchars($u['address'] ?? '', ENT_QUOTES) ?>">
                  <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                  </div>
                </form>
              <?php endif; ?>

              <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

              <div class="user-row-extras">
                <details>
                  <summary>Reset password</summary>
                  <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <input type="password" name="password" placeholder="new password (8+ chars)" minlength="8" required>
                    <?php if ($is_client_view && !empty($u['email'])): ?>
                      <label class="inline-check">
                        <input type="checkbox" name="send_email" value="1"> email it to them
                      </label>
                    <?php endif; ?>
                    <button class="btn btn-ghost btn-sm" type="submit">Save new password</button>
                  </form>
                </details>

                <?php if ($is_client_view && !empty($u['email'])): ?>
                  <form method="post" class="inline-form" data-confirm="Generate a new temp password and email it to <?= htmlspecialchars($u['email'], ENT_QUOTES) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_welcome">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Resend welcome email</button>
                  </form>
                <?php endif; ?>

                <?php if ((int)$u['id'] !== (int)$current_user['id']): ?>
                  <form method="post" class="inline-form" data-confirm="Delete <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? This cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Delete account</button>
                  </form>
                <?php else: ?>
                  <span class="muted small">(this is you)</span>
                <?php endif; ?>
              </div>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="portal-card">
      <h2>Create new <?= htmlspecialchars($role) ?></h2>
      <?php if ($is_client_view): ?>
        <p class="muted small">Just the basics here. After save you'll land on the full client editor where you can fill in ID number, GPS, equipment, billing day and the rest.</p>
      <?php endif; ?>
      <form method="post" class="form form-grid" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field"><label>Username</label>
          <input type="text" name="username" required minlength="3" maxlength="60">
        </div>
        <div class="field"><label>Display name</label>
          <input type="text" name="name" required maxlength="100">
        </div>
        <?php if ($is_client_view): ?>
          <div class="field"><label>Surname <span class="muted small">(used for the account number, e.g. GEL0001)</span></label>
            <input type="text" name="surname" required maxlength="60">
          </div>
          <div class="field"><label>Customer type</label>
            <select name="customer_type">
              <option value="residential">Residential</option>
              <option value="business">Business</option>
            </select>
          </div>
        <?php endif; ?>
        <div class="field"><label>Email <span class="muted">(optional)</span></label>
          <input type="email" name="email" maxlength="120">
        </div>
        <div class="field"><label>Password</label>
          <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <?php if ($is_client_view): ?>
          <div class="field"><label>Phone</label>
            <input type="tel" name="phone" maxlength="40">
          </div>
          <div class="field"><label>Package</label>
            <input type="text" name="package" maxlength="80" placeholder="e.g. Home 10 Mbps">
          </div>
          <div class="field" style="grid-column:1/-1;"><label>Address</label>
            <input type="text" name="address" maxlength="200">
          </div>
          <div class="field-check" style="grid-column:1/-1;">
            <input type="checkbox" id="send_welcome_<?= htmlspecialchars($role) ?>" name="send_welcome" value="1" checked>
            <label for="send_welcome_<?= htmlspecialchars($role) ?>">
              Send a welcome email with login details to the email address above.
              <small class="muted">Leave unticked if you'd rather give them the password yourself.</small>
            </label>
          </div>
        <?php endif; ?>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Create <?= htmlspecialchars($role) ?></button>
        </div>
      </form>
    </div>
    <?php
}
