<?php
/**
 * Reusable list+create+edit+delete UI for users of a given role.
 *
 * Posts to itself with action=create | update | reset_password | delete.
 * Output is the page body — caller is responsible for layout header/footer.
 */

require_once __DIR__ . '/../auth/products.php';
require_once __DIR__ . '/../auth/validators.php';

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

            // Normalise phone to E.164 up-front so the new client lands with a
            // populated phone_e164 column — outage SMS / WhatsApp channels
            // prefer phone_e164 over the raw phone string.
            $phone_check = normalize_phone_e164((string)($_POST['phone'] ?? ''));
            if (!$phone_check['ok']) $errors[] = 'Phone: ' . $phone_check['error'];

            $created = null;
            if (!$errors) {
                try {
                    // Resolve product picker -> legacy package text so all
                    // existing readers keep working.
                    $product_id = (int)($_POST['product_id'] ?? 0);
                    $package    = '';
                    if ($product_id > 0 && ($p = products_find($product_id))) {
                        $package = $p['name'];
                    }
                    $created = create_user($username, $password, $role, $name, $email, [
                        'phone'         => $_POST['phone']         ?? '',
                        'address'       => $_POST['address']       ?? '',
                        'package'       => $package,
                        'surname'       => $surname,
                        'customer_type' => $_POST['customer_type'] ?? 'residential',
                    ]);
                    if ($created && !empty($created['id'])) {
                        // Backfill phone_e164 (and product_id below) — create_user's
                        // INSERT only covers the canonical columns.
                        $patch = [];
                        if ($phone_check['value'] !== '') $patch['phone_e164'] = $phone_check['value'];
                        if ($product_id > 0)              $patch['product_id'] = $product_id;
                        if ($patch) {
                            update_user((int)$created['id'], fn(array $u) => array_merge($u, $patch));
                            $created = find_user_by_id((int)$created['id']) ?? $created;
                        }
                    }
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

        if ($action === 'bulk_status' && $role === 'client') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            $new = (string)($_POST['new_status'] ?? '');
            if (!in_array($new, CUSTOMER_STATUS, true)) {
                flash('error', 'Unknown bulk status.');
            } elseif (!$ids) {
                flash('error', 'Select at least one client first.');
            } else {
                $n = 0;
                foreach ($ids as $uid) {
                    if ($uid === (int)$current_user['id']) continue;
                    if (update_user($uid, fn(array $u) => array_merge($u, ['status' => $new]))) $n++;
                }
                audit_log('client.bulk_status', ['target_type' => 'user', 'meta' => ['status' => $new, 'count' => $n]]);
                flash('success', "Updated $n client" . ($n === 1 ? '' : 's') . " to {$new}.");
            }
            header('Location: ' . $self . (parse_url($self, PHP_URL_QUERY) ? '' : ''));
            exit;
        }

        if ($action === 'bulk_delete' && $role === 'client') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) {
                flash('error', 'Select at least one client first.');
            } else {
                $n = 0;
                foreach ($ids as $uid) {
                    if ($uid === (int)$current_user['id']) continue;
                    if (delete_user($uid)) $n++;
                }
                audit_log('client.bulk_delete', ['target_type' => 'user', 'meta' => ['count' => $n]]);
                flash('success', "Deleted $n client" . ($n === 1 ? '' : 's') . '.');
            }
            header('Location: ' . $self);
            exit;
        }
    }

    $users = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === $role));
    $is_client_view = ($role === 'client');

    // ---- Filters (clients only) ----
    $status_filter   = $is_client_view ? trim((string)($_GET['status'] ?? '')) : '';
    $unplaced_filter = $is_client_view && ($_GET['unplaced'] ?? '') === '1';

    // Pre-compute the unfiltered status histogram so chips show counts.
    $status_counts = ['' => count($users), 'active' => 0, 'lead' => 0, 'suspended' => 0, 'disconnected' => 0];
    if ($is_client_view) {
        foreach ($users as $u) {
            $s = $u['status'] ?? 'active';
            if (isset($status_counts[$s])) $status_counts[$s]++;
        }
    }
    $unplaced_count = 0;
    if ($is_client_view) {
        foreach ($users as $u) {
            if (($u['lat'] ?? null) === null || ($u['lng'] ?? null) === null) $unplaced_count++;
        }
    }

    if ($is_client_view && $status_filter !== '') {
        if (in_array($status_filter, CUSTOMER_STATUS, true)) {
            $users = array_values(array_filter($users, fn($u) => ($u['status'] ?? 'active') === $status_filter));
        }
    }
    if ($unplaced_filter) {
        $users = array_values(array_filter($users, fn($u) => ($u['lat'] ?? null) === null || ($u['lng'] ?? null) === null));
    }

    // Free-text search across the most useful columns. Case-insensitive
    // substring match — the dataset is small enough not to warrant SQL FTS.
    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $users = array_values(array_filter($users, function (array $u) use ($needle) {
            $hay = mb_strtolower(implode(' ', [
                $u['username']    ?? '',
                $u['name']        ?? '',
                $u['surname']     ?? '',
                $u['email']       ?? '',
                $u['phone']       ?? '',
                $u['account_no']  ?? '',
                $u['address']     ?? '',
                $u['package']     ?? '',
            ]));
            return strpos($hay, $needle) !== false;
        }));
    }

    // Helper to keep filter state across links/forms (clients only).
    $qs_with = function (array $overrides) use ($search, $status_filter, $unplaced_filter): string {
        $q = array_filter([
            'q'        => $search ?: null,
            'status'   => $status_filter ?: null,
            'unplaced' => $unplaced_filter ? '1' : null,
        ], fn($v) => $v !== null);
        foreach ($overrides as $k => $v) {
            if ($v === null) unset($q[$k]); else $q[$k] = $v;
        }
        return $q ? '?' . http_build_query($q) : '?';
    };
    ?>
    <div class="portal-head">
      <h1><?= htmlspecialchars($heading) ?></h1>
      <p class="portal-sub"><?= htmlspecialchars($subtitle) ?></p>
    </div>

    <div class="portal-card">
      <form method="get" class="inline-form" style="margin:0; flex-wrap:wrap;">
        <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Search by name, surname, account, email, phone…" style="flex:1;min-width:200px;">
        <?php if ($is_client_view && $status_filter !== ''): ?>
          <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter, ENT_QUOTES) ?>">
        <?php endif; ?>
        <?php if ($is_client_view && $unplaced_filter): ?>
          <input type="hidden" name="unplaced" value="1">
        <?php endif; ?>
        <button type="submit" class="btn btn-ghost btn-sm">Search</button>
        <?php if ($search !== '' || $status_filter !== '' || $unplaced_filter): ?>
          <a href="?" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
        <?php if ($is_client_view): ?>
          <a href="<?= htmlspecialchars($qs_with(['export' => 'csv'])) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
        <?php endif; ?>
      </form>

      <?php if ($is_client_view): ?>
        <div class="filter-chips" style="margin-top:12px;">
          <?php
            $chips = [
                ''             => ['All', $status_counts['']],
                'active'       => ['Active', $status_counts['active']],
                'lead'         => ['Lead', $status_counts['lead']],
                'suspended'    => ['Suspended', $status_counts['suspended']],
                'disconnected' => ['Disconnected', $status_counts['disconnected']],
            ];
            foreach ($chips as $key => [$label, $count]):
              $active = $status_filter === $key;
          ?>
            <a class="chip<?= $active ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars($qs_with(['status' => $key === '' ? null : $key])) ?>">
              <?= htmlspecialchars($label) ?> <span class="chip-count"><?= (int)$count ?></span>
            </a>
          <?php endforeach; ?>
          <a class="chip<?= $unplaced_filter ? ' is-active' : '' ?>"
             href="<?= htmlspecialchars($qs_with(['unplaced' => $unplaced_filter ? null : '1'])) ?>"
             title="Clients without lat/lng — won't appear on the network map.">
            Unplaced <span class="chip-count"><?= (int)$unplaced_count ?></span>
          </a>
        </div>
        <style>
          .filter-chips { display:flex; gap:6px; flex-wrap:wrap; }
          .filter-chips .chip {
            display:inline-flex; align-items:center; gap:6px;
            padding:4px 10px; border-radius:999px;
            border:1px solid var(--border); color:var(--text-dim);
            font-size:12px; text-decoration:none;
            background:transparent;
          }
          .filter-chips .chip:hover { color:var(--text); border-color:var(--border-strong); }
          .filter-chips .chip.is-active { background:var(--accent-soft); color:var(--accent); border-color:rgba(5,218,253,.4); }
          .filter-chips .chip-count { font-weight:600; opacity:.7; }
          .bulk-bar {
            display:flex; gap:8px; align-items:center; flex-wrap:wrap;
            padding:8px 12px; background:rgba(5,218,253,.06);
            border:1px solid rgba(5,218,253,.2); border-radius:var(--radius-sm);
            margin-bottom:12px; font-size:13px;
          }
          .bulk-bar.is-empty { opacity:.5; }
          .user-row .row-check { margin-right:8px; vertical-align:middle; }
        </style>
      <?php endif; ?>
    </div>

    <div class="portal-card">
      <h2>Existing accounts <span class="muted">(<?= count($users) ?><?= $search ? ' match' . (count($users) === 1 ? '' : 'es') : '' ?>)</span></h2>
      <?php if (empty($users)): ?>
        <div class="empty-state">
          <div class="empty-icon">+</div>
          <h3>No <?= htmlspecialchars($role) ?>s yet</h3>
          <p>Use the form below to add the first one. <?= $role === 'client' ? 'A welcome email with login credentials can be sent automatically.' : '' ?></p>
        </div>
      <?php else: ?>
        <?php if ($is_client_view): ?>
          <div class="bulk-bar" id="bulk-bar">
            <label class="inline-check" style="margin:0;">
              <input type="checkbox" id="bulk-select-all">
              <span>Select all on page</span>
            </label>
            <span id="bulk-selected" class="muted small">0 selected</span>
            <span style="flex:1;"></span>
            <span class="muted small">Bulk actions:</span>
            <?php foreach (['active' => 'Activate', 'suspended' => 'Suspend', 'disconnected' => 'Disconnect', 'lead' => 'Mark lead'] as $k => $lbl): ?>
              <button type="submit" form="bulk-form" name="action_status_<?= $k ?>" value="<?= $k ?>"
                      class="btn btn-ghost btn-sm bulk-act"
                      data-confirm="Set selected clients to <?= htmlspecialchars($lbl, ENT_QUOTES) ?>?">
                <?= htmlspecialchars($lbl) ?>
              </button>
            <?php endforeach; ?>
            <button type="submit" form="bulk-form" name="action_delete" value="1"
                    class="btn btn-danger btn-sm bulk-act"
                    data-confirm="Delete the selected clients? This cannot be undone.">
              Delete
            </button>
          </div>
          <form id="bulk-form" method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="bulk-form-action" value="bulk_status">
            <input type="hidden" name="new_status" id="bulk-form-status" value="">
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
          <details class="user-row">
            <summary>
              <?php if ($is_client_view): ?>
                <input type="checkbox" class="row-check" name="ids[]" value="<?= (int)$u['id'] ?>"
                       form="bulk-form"
                       onclick="event.stopPropagation();"
                       <?= (int)$u['id'] === (int)$current_user['id'] ? 'disabled title="That\'s you"' : '' ?>>
              <?php endif; ?>
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
              <?php if ($is_client_view && (($u['lat'] ?? null) === null || ($u['lng'] ?? null) === null)): ?>
                <span class="pkg-pill" style="background:#444;" title="No GPS — won't show on the network map.">unplaced</span>
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
        <?php if ($is_client_view): ?>
          </form>
          <script>
          (function () {
            const form     = document.getElementById('bulk-form');
            const selectAll= document.getElementById('bulk-select-all');
            const counter  = document.getElementById('bulk-selected');
            const actInput = document.getElementById('bulk-form-action');
            const stsInput = document.getElementById('bulk-form-status');
            if (!form) return;

            const checks = () => Array.from(document.querySelectorAll('.row-check:not([disabled])'));
            function refresh() {
              const n = checks().filter(c => c.checked).length;
              counter.textContent = n + ' selected';
              document.querySelectorAll('.bulk-act').forEach(b => b.disabled = (n === 0));
              document.getElementById('bulk-bar').classList.toggle('is-empty', n === 0);
            }
            refresh();
            selectAll && selectAll.addEventListener('change', () => {
              checks().forEach(c => { c.checked = selectAll.checked; });
              refresh();
            });
            document.addEventListener('change', (e) => {
              if (e.target.classList && e.target.classList.contains('row-check')) refresh();
            });

            document.querySelectorAll('.bulk-act').forEach((btn) => {
              btn.addEventListener('click', (e) => {
                if (btn.name === 'action_delete') {
                  actInput.value = 'bulk_delete';
                  stsInput.value = '';
                } else if (btn.name && btn.name.indexOf('action_status_') === 0) {
                  actInput.value = 'bulk_status';
                  stsInput.value = btn.value;
                }
                const msg = btn.getAttribute('data-confirm');
                if (msg && !confirm(msg)) e.preventDefault();
              });
            });
          })();
          </script>
        <?php endif; ?>
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
          <div class="field"><label>Product</label>
            <select name="product_id">
              <option value="">— pick later —</option>
              <?php foreach (products_all(true) as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars(product_dropdown_label($p)) ?></option>
              <?php endforeach; ?>
            </select>
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
