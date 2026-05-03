<?php
/**
 * Full client editor — Splynx-style record with personal, billing,
 * equipment and notes sections. Linked from /admin/clients.php.
 */
$page_title = 'Edit client';
$active_key = 'clients';
require __DIR__ . '/_layout.php';

$id     = (int)($_GET['id'] ?? 0);
$client = $id ? find_user_by_id($id) : null;
if (!$client || ($client['role'] ?? '') !== 'client') {
    flash('error', 'Client not found.');
    header('Location: /admin/clients.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $name    = trim($_POST['name']    ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $email   = trim($_POST['email']   ?? '');
    if ($name === '')                                                $errors[] = 'Display name is required.';
    if ($surname === '')                                             $errors[] = 'Surname is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
    if ($_POST['lat'] !== '' && !is_numeric($_POST['lat']))          $errors[] = 'Latitude must be numeric.';
    if ($_POST['lng'] !== '' && !is_numeric($_POST['lng']))          $errors[] = 'Longitude must be numeric.';

    if (!$errors) {
        $patch = [
            'name'              => $name,
            'surname'           => $surname,
            'id_number'         => trim($_POST['id_number']         ?? ''),
            'vat_number'        => trim($_POST['vat_number']        ?? ''),
            'email'             => $email,
            'phone'             => trim($_POST['phone']             ?? ''),
            'alt_contact_name'  => trim($_POST['alt_contact_name']  ?? ''),
            'alt_contact_phone' => trim($_POST['alt_contact_phone'] ?? ''),
            'address'           => trim($_POST['address']           ?? ''),
            'lat'               => $_POST['lat']                    ?? '',
            'lng'               => $_POST['lng']                    ?? '',
            'customer_type'     => $_POST['customer_type']          ?? 'residential',
            'status'            => $_POST['status']                 ?? 'active',
            'service_start'     => $_POST['service_start']          ?? '',
            'billing_day'       => $_POST['billing_day']            ?? '',
            'payment_method'    => $_POST['payment_method']         ?? 'eft',
            'package'           => trim($_POST['package']           ?? ''),
            'equipment_mac'     => trim($_POST['equipment_mac']     ?? ''),
            'equipment_ip'      => trim($_POST['equipment_ip']      ?? ''),
            'equipment_serial'  => trim($_POST['equipment_serial']  ?? ''),
            'equipment_model'   => trim($_POST['equipment_model']   ?? ''),
            'notes'             => trim($_POST['notes']             ?? ''),
        ];

        // Lazy-issue an account number if this client predates the schema
        // migration. Done after the surname is committed so the prefix is
        // taken from whatever the admin actually saved.
        if (empty($client['account_no'])) {
            $patch['account_no'] = generate_account_no($surname);
        }

        update_user($id, fn(array $u) => array_merge($u, $patch));
        audit_log('client.update', [
            'target_type' => 'user',
            'target_id'   => $id,
            'meta'        => ['account_no' => $patch['account_no'] ?? $client['account_no']],
        ]);
        flash('success', 'Client details saved.');
        header('Location: /admin/client-edit.php?id=' . $id);
        exit;
    }
    flash('error', implode(' ', $errors));
}

$client = find_user_by_id($id) ?? $client;
$v = fn($k, $d = '') => htmlspecialchars((string)($client[$k] ?? $d), ENT_QUOTES);
?>

<div class="portal-head">
  <h1>
    <?= htmlspecialchars($client['name'] ?: $client['username']) ?>
    <?php if (!empty($client['account_no'])): ?>
      <span class="muted small" style="font-weight:normal;">&middot; <?= htmlspecialchars($client['account_no']) ?></span>
    <?php endif; ?>
  </h1>
  <p class="portal-sub">
    Username <strong><?= htmlspecialchars($client['username']) ?></strong>
    &middot; created <?= htmlspecialchars(substr((string)($client['created_at'] ?? ''), 0, 10)) ?>
    &middot; last login: <?= htmlspecialchars($client['last_login'] ?? 'never') ?>
  </p>
</div>

<p>
  <a href="/admin/clients.php" class="btn btn-ghost btn-sm">&larr; Back to clients</a>
</p>

<form method="post" class="form">
  <?= csrf_field() ?>

  <div class="portal-card">
    <h2>Account &amp; status</h2>
    <div class="form form-grid">
      <div class="field"><label>Account number</label>
        <input type="text" disabled value="<?= $v('account_no', '— will be issued on save —') ?>">
      </div>
      <div class="field"><label>Status</label>
        <select name="status">
          <?php foreach (['active'=>'Active','lead'=>'Lead','suspended'=>'Suspended','disconnected'=>'Disconnected'] as $k=>$lbl): ?>
            <option value="<?= $k ?>" <?= ($client['status']??'')===$k?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Customer type</label>
        <select name="customer_type">
          <option value="residential" <?= ($client['customer_type']??'')==='residential'?'selected':'' ?>>Residential</option>
          <option value="business"    <?= ($client['customer_type']??'')==='business'?'selected':'' ?>>Business</option>
        </select>
      </div>
      <div class="field"><label>Service start</label>
        <input type="date" name="service_start" value="<?= $v('service_start') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Personal &amp; identity</h2>
    <div class="form form-grid">
      <div class="field"><label>Display name</label>
        <input type="text" name="name" required maxlength="100" value="<?= $v('name') ?>">
      </div>
      <div class="field"><label>Surname <span class="muted small">(drives the account number)</span></label>
        <input type="text" name="surname" required maxlength="60" value="<?= $v('surname') ?>">
      </div>
      <div class="field"><label>SA ID number</label>
        <input type="text" name="id_number" maxlength="20" value="<?= $v('id_number') ?>">
      </div>
      <div class="field"><label>VAT number</label>
        <input type="text" name="vat_number" maxlength="20" value="<?= $v('vat_number') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Contact</h2>
    <div class="form form-grid">
      <div class="field"><label>Email</label>
        <input type="email" name="email" maxlength="120" value="<?= $v('email') ?>">
      </div>
      <div class="field"><label>Phone</label>
        <input type="tel" name="phone" maxlength="40" value="<?= $v('phone') ?>">
      </div>
      <div class="field"><label>Alt. contact name</label>
        <input type="text" name="alt_contact_name" maxlength="100" value="<?= $v('alt_contact_name') ?>">
      </div>
      <div class="field"><label>Alt. contact phone</label>
        <input type="tel" name="alt_contact_phone" maxlength="40" value="<?= $v('alt_contact_phone') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Service address &amp; GPS</h2>
    <div class="form form-grid">
      <div class="field" style="grid-column:1/-1;"><label>Street address</label>
        <input type="text" name="address" maxlength="200" value="<?= $v('address') ?>">
      </div>
      <div class="field"><label>Latitude</label>
        <input type="text" name="lat" maxlength="20" value="<?= $v('lat') ?>" placeholder="-26.7100000">
      </div>
      <div class="field"><label>Longitude</label>
        <input type="text" name="lng" maxlength="20" value="<?= $v('lng') ?>" placeholder="27.8300000">
      </div>
    </div>
    <p class="muted small">Once Phase 3 (network map) is wired up these will fill in automatically from the address, with drag-to-correct on the map. For now you can paste them in from Google Maps.</p>
  </div>

  <div class="portal-card">
    <h2>Billing</h2>
    <div class="form form-grid">
      <div class="field"><label>Billing day (1–31)</label>
        <input type="number" name="billing_day" min="1" max="31" value="<?= $v('billing_day') ?>" placeholder="1">
      </div>
      <div class="field"><label>Payment method</label>
        <select name="payment_method">
          <?php foreach (['eft'=>'EFT','debit_order'=>'Debit order','cash'=>'Cash','card'=>'Card'] as $k=>$lbl): ?>
            <option value="<?= $k ?>" <?= ($client['payment_method']??'')===$k?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="grid-column:1/-1;"><label>Package <span class="muted small">(replaced by the product picker in Phase 2)</span></label>
        <input type="text" name="package" maxlength="80" value="<?= $v('package') ?>" placeholder="e.g. Home 10 Mbps">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Equipment / CPE</h2>
    <div class="form form-grid">
      <div class="field"><label>MAC address</label>
        <input type="text" name="equipment_mac" maxlength="20" value="<?= $v('equipment_mac') ?>" placeholder="aa:bb:cc:dd:ee:ff">
      </div>
      <div class="field"><label>IP address</label>
        <input type="text" name="equipment_ip" maxlength="45" value="<?= $v('equipment_ip') ?>" placeholder="10.0.0.1">
      </div>
      <div class="field"><label>Serial number</label>
        <input type="text" name="equipment_serial" maxlength="60" value="<?= $v('equipment_serial') ?>">
      </div>
      <div class="field"><label>Model</label>
        <input type="text" name="equipment_model" maxlength="80" value="<?= $v('equipment_model') ?>" placeholder="LiteBeam AC LR">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Notes</h2>
    <div class="field" style="grid-column:1/-1;">
      <textarea name="notes" rows="6" style="width:100%; font-family:inherit;"><?= htmlspecialchars($client['notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Save all changes</button>
    <a href="/admin/clients.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
