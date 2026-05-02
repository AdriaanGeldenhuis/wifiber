<?php
$page_title = 'Site settings';
$active_key = 'settings';
require __DIR__ . '/_layout.php';

$file = __DIR__ . '/../data/site.json';
$data = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $new = [
        'name'           => trim($_POST['name']           ?? ''),
        'tagline'        => trim($_POST['tagline']        ?? ''),
        'phone'          => trim($_POST['phone']          ?? ''),
        'phone_link'     => preg_replace('/[^0-9+]/', '', $_POST['phone_link'] ?? ''),
        'email_admin'    => trim($_POST['email_admin']    ?? ''),
        'email_accounts' => trim($_POST['email_accounts'] ?? ''),
        'email_support'  => trim($_POST['email_support']  ?? ''),
        'address_line1'  => trim($_POST['address_line1']  ?? ''),
        'address_line2'  => trim($_POST['address_line2']  ?? ''),
        'social' => [
            'facebook' => trim($_POST['social_facebook'] ?? '#'),
            'linkedin' => trim($_POST['social_linkedin'] ?? '#'),
            'youtube'  => trim($_POST['social_youtube']  ?? '#'),
        ],
    ];

    if ($new['name']    === '') $errors[] = 'Site name cannot be empty.';
    if ($new['tagline'] === '') $errors[] = 'Tagline cannot be empty.';
    foreach (['email_admin', 'email_accounts', 'email_support'] as $f) {
        if ($new[$f] !== '' && !filter_var($new[$f], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' is not a valid email.';
        }
    }

    if (!$errors) {
        if (json_save($file, $new)) {
            flash('success', 'Site settings saved.');
        } else {
            flash('error', 'Could not write data/site.json. Check permissions.');
        }
        header('Location: /admin/settings.php');
        exit;
    }
    $data = $new;
}

$v = function (string $key, $default = '') use ($data) {
    return htmlspecialchars((string)($data[$key] ?? $default), ENT_QUOTES);
};
$social = $data['social'] ?? [];
?>

<div class="portal-head">
  <h1>Site settings</h1>
  <p class="portal-sub">Edit the contact details, brand info and social links shown on the public site. Changes go live immediately on save.</p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul style="margin:0;padding-left:18px;">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
  </ul></div>
<?php endif; ?>

<form method="post" class="form">
  <?= csrf_field() ?>

  <div class="portal-card">
    <h2>Brand</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Site name</label>
        <input type="text" name="name" required maxlength="60" value="<?= $v('name') ?>">
      </div>
      <div class="field">
        <label>Tagline</label>
        <input type="text" name="tagline" required maxlength="120" value="<?= $v('tagline') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Contact</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Phone (display)</label>
        <input type="text" name="phone" maxlength="40" value="<?= $v('phone') ?>">
      </div>
      <div class="field">
        <label>Phone (dial link)</label>
        <input type="text" name="phone_link" maxlength="20" value="<?= $v('phone_link') ?>" placeholder="0800111222">
        <small class="muted">Digits only &mdash; used for the click-to-dial link.</small>
      </div>
      <div class="field">
        <label>Admin email</label>
        <input type="email" name="email_admin" maxlength="120" value="<?= $v('email_admin') ?>">
      </div>
      <div class="field">
        <label>Accounts email</label>
        <input type="email" name="email_accounts" maxlength="120" value="<?= $v('email_accounts') ?>">
      </div>
      <div class="field">
        <label>Support email</label>
        <input type="email" name="email_support" maxlength="120" value="<?= $v('email_support') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Address</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Address line 1</label>
        <input type="text" name="address_line1" maxlength="120" value="<?= $v('address_line1') ?>">
      </div>
      <div class="field">
        <label>Address line 2</label>
        <input type="text" name="address_line2" maxlength="120" value="<?= $v('address_line2') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Social links</h2>
    <p class="muted">Use <code>#</code> to hide the icon, or paste a full URL.</p>
    <div class="form form-grid">
      <div class="field">
        <label>Facebook</label>
        <input type="text" name="social_facebook" maxlength="200" value="<?= htmlspecialchars($social['facebook'] ?? '#', ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>LinkedIn</label>
        <input type="text" name="social_linkedin" maxlength="200" value="<?= htmlspecialchars($social['linkedin'] ?? '#', ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>YouTube</label>
        <input type="text" name="social_youtube" maxlength="200" value="<?= htmlspecialchars($social['youtube'] ?? '#', ENT_QUOTES) ?>">
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Save settings</button>
</form>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
