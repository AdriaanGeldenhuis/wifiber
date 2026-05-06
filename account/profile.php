<?php
$page_title = 'My profile';
$active_key = 'profile';
require __DIR__ . '/_layout.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '')                                                $errors[] = 'Display name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';

    // Notification opt-in checkboxes (notify_prefs JSON column).
    $prefs = [];
    foreach (['outage','maintenance','link'] as $g) {
        foreach (['email','sms','whatsapp','push'] as $c) {
            $key = "{$c}_{$g}";
            $prefs[$key] = !empty($_POST['notify'][$key]);
        }
    }

    if (!$errors) {
        update_user((int)$user['id'], function (array $u) use ($name, $email, $phone, $address, $prefs) {
            $u['name']         = $name;
            $u['email']        = $email;
            $u['phone']        = $phone;
            $u['address']      = $address;
            $u['notify_prefs'] = json_encode($prefs, JSON_UNESCAPED_SLASHES);
            return $u;
        });
        $_SESSION['user_name'] = $name;
        flash('success', 'Your details have been updated.');
        header('Location: /account/profile.php');
        exit;
    }
}
?>

<div class="portal-head">
  <h1>My profile</h1>
  <p class="portal-sub">Update the details we have on file. Need to change your username or your package? Drop us a line on <a href="tel:0800111222">0800 111 222</a>.</p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul style="margin:0;padding-left:18px;">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
  </ul></div>
<?php endif; ?>

<div class="portal-card">
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <div class="field" style="grid-column:1/-1;">
      <label>Username <span class="muted">(read-only)</span></label>
      <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled>
    </div>
    <div class="field"><label>Display name</label>
      <input type="text" name="name" required maxlength="100" value="<?= htmlspecialchars($user['name'] ?? '', ENT_QUOTES) ?>">
    </div>
    <div class="field"><label>Email</label>
      <input type="email" name="email" maxlength="120" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES) ?>">
    </div>
    <div class="field"><label>Phone</label>
      <input type="tel" name="phone" maxlength="40" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES) ?>">
    </div>
    <div class="field"><label>Package <span class="muted">(read-only)</span></label>
      <input type="text" value="<?= htmlspecialchars($user['package'] ?? '—', ENT_QUOTES) ?>" disabled>
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Service address</label>
      <input type="text" name="address" maxlength="200" value="<?= htmlspecialchars($user['address'] ?? '', ENT_QUOTES) ?>">
    </div>
    <div style="grid-column:1/-1;border-top:1px solid rgba(0,0,0,0.05);margin-top:8px;padding-top:14px;">
      <strong style="display:block;margin-bottom:6px;">How should we reach you?</strong>
      <p class="muted" style="margin:0 0 10px;font-size:.85rem;">Tick a box to opt in. Email is on by default for everything; SMS and WhatsApp need to be enabled by us first (we'll send a setup message when they're live).</p>
      <?php
        $current = $user['notify_prefs'] ?? null;
        if (is_string($current)) $current = json_decode($current, true);
        if (!is_array($current)) $current = [];
        $groups = [
          'outage'      => 'Outage alerts',
          'maintenance' => 'Planned maintenance',
          'link'        => 'Link health (signal drop, slow connection)',
        ];
      ?>
      <table style="width:100%;font-size:.9rem;">
        <thead><tr><th style="text-align:left;">&nbsp;</th><th>Email</th><th>SMS</th><th>WhatsApp</th><th>Push <small class="muted">(app)</small></th></tr></thead>
        <tbody>
          <?php foreach ($groups as $g => $label):
            // Default-on for outage, default-off for the rest.
            $default = $g === 'outage';
          ?>
            <tr>
              <td><?= htmlspecialchars($label) ?></td>
              <?php foreach (['email','sms','whatsapp','push'] as $c):
                $key = "{$c}_{$g}";
                $checked = array_key_exists($key, $current) ? !empty($current[$key]) : $default;
              ?>
                <td style="text-align:center;">
                  <input type="checkbox" name="notify[<?= $key ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted" style="margin-top:8px;font-size:.8rem;">Push notifications need our native app — they activate as soon as you sign in on the app and grant notification permission.</p>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save changes</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
