<?php
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/totp.php';

$portal = 'admin';
$page_title = 'Two-factor';

require_admin_ip();

$pending_id = (int)($_SESSION['totp_pending_id'] ?? 0);
$expires    = (int)($_SESSION['totp_pending_expires'] ?? 0);

if ($pending_id <= 0 || $expires < time()) {
    unset($_SESSION['totp_pending_id'], $_SESSION['totp_pending_expires']);
    header('Location: /admin/login.php');
    exit;
}

$candidate = find_user_by_id($pending_id);
if (!$candidate || ($candidate['role'] ?? '') !== 'admin') {
    unset($_SESSION['totp_pending_id'], $_SESSION['totp_pending_expires']);
    header('Location: /admin/login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (is_locked_out(client_ip())) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $code = trim((string)($_POST['code'] ?? ''));
        $accepted = false;

        // Try TOTP code first
        if ($code !== '' && !empty($candidate['totp_secret']) && totp_verify($candidate['totp_secret'], $code, 1)) {
            $accepted = true;
        }
        // Otherwise try recovery code
        if (!$accepted && $code !== '' && !empty($candidate['totp_recovery_codes'])) {
            $r = totp_consume_recovery_code($candidate['totp_recovery_codes'], $code);
            if ($r['ok']) {
                $accepted = true;
                update_user((int)$candidate['id'], function (array $u) use ($r) {
                    $u['totp_recovery_codes'] = $r['codes'];
                    return $u;
                });
            }
        }

        if (!$accepted) {
            record_login_fail(client_ip());
            audit_log('login.2fa_fail', [
                'target_type' => 'user', 'target_id' => (int)$candidate['id'],
                'meta' => ['username' => $candidate['username']],
            ]);
            $error = 'Code is wrong or expired.';
        } else {
            reset_login_fails(client_ip());
            session_regenerate_id(true);
            $_SESSION['user_id']        = (int)$candidate['id'];
            $_SESSION['user_role']      = $candidate['role'];
            $_SESSION['user_name']      = $candidate['name'];
            $_SESSION['logged_in_at']   = time();
            $_SESSION['last_activity']  = time();
            unset($_SESSION['totp_pending_id'], $_SESSION['totp_pending_expires']);
            update_user((int)$candidate['id'], function (array $u) {
                $u['last_login'] = date('c');
                return $u;
            });
            audit_log('login.success', [
                'target_type' => 'user', 'target_id' => (int)$candidate['id'],
                'meta' => ['role' => 'admin', '2fa' => true],
            ]);
            header('Location: /admin/');
            exit;
        }
    }
}

require __DIR__ . '/../auth/portal-header.php';
?>
<div class="portal-card portal-card-narrow">
  <h1>Two-factor authentication</h1>
  <p class="portal-sub">Open your authenticator app and enter the 6-digit code for <strong><?= htmlspecialchars($candidate['username']) ?></strong>.</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" class="form">
    <?= csrf_field() ?>
    <div class="field">
      <label for="code">6-digit code <span class="muted">(or a recovery code)</span></label>
      <input type="text" id="code" name="code" required autofocus inputmode="numeric"
             pattern="[0-9A-Za-z\- ]{6,20}" maxlength="20" autocomplete="one-time-code"
             style="font-family:ui-monospace,'JetBrains Mono',monospace; letter-spacing:.3em; font-size:1.3rem; text-align:center;">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Verify</button>
  </form>

  <p class="portal-foot-link">
    <a href="/admin/logout.php">Cancel and start over</a>
  </p>
</div>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
