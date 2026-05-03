<?php
/**
 * Full client editor — Splynx-style record with personal, billing,
 * equipment and notes sections. Linked from /admin/clients.php.
 */
$page_title = 'Edit client';
$active_key = 'clients';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/products.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/outages.php';

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

    if (($_POST['action'] ?? '') === 'geocode') {
        if (empty($client['address'])) {
            flash('error', 'Add a street address first.');
        } else {
            $hit = geocode_address((string)$client['address']);
            if ($hit) {
                update_user($id, function (array $u) use ($hit) {
                    $u['lat'] = $hit['lat'];
                    $u['lng'] = $hit['lng'];
                    return $u;
                });
                flash('success', 'Located: ' . $hit['display_name']);
            } else {
                flash('error', 'Nominatim found nothing for that address. Place the marker manually on the network map.');
            }
        }
        header('Location: /admin/client-edit.php?id=' . $id);
        exit;
    }

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
            'product_id'        => $_POST['product_id']             ?? '',
            'package'           => trim($_POST['package']           ?? ''),
            'site_id'           => $_POST['site_id']                ?? '',
            'sector_id'         => $_POST['sector_id']              ?? '',
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

        // Keep the legacy `package` text in sync with the product picker
        // so existing readers (public package_price_lookup, the client
        // portal dashboard, older invoice flows) keep working.
        if (!empty($patch['product_id']) && ($p = products_find((int)$patch['product_id']))) {
            $patch['package'] = $p['name'];
        } elseif ($patch['product_id'] === '' || $patch['product_id'] === null) {
            // Cleared the picker — leave whatever they typed in `package`.
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
    <p class="muted small">
      Use <strong>Geocode from address</strong> to call OpenStreetMap Nominatim and fill in the GPS, then fine-tune the marker on the <a href="/admin/map.php">network map</a>.
    </p>
    <div class="form-actions" style="margin-top:6px;">
      <button type="submit" name="action" value="geocode" class="btn btn-ghost btn-sm" formnovalidate>Geocode from address</button>
      <?php if (!empty($client['lat']) && !empty($client['lng'])): ?>
        <a href="/admin/map.php" class="btn btn-ghost btn-sm">Open on network map</a>
      <?php endif; ?>
    </div>
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
      <div class="field" style="grid-column:1/-1;"><label>Product</label>
        <select name="product_id">
          <option value="">— no product assigned —</option>
          <?php
          $catalogue   = products_all(false);
          $current_pid = (int)($client['product_id'] ?? 0);
          $current_in_list = false;
          foreach ($catalogue as $p):
              if (!$p['is_active'] && $p['id'] !== $current_pid) continue;
              if ($p['id'] === $current_pid) $current_in_list = true;
          ?>
            <option value="<?= (int)$p['id'] ?>" <?= $current_pid===(int)$p['id']?'selected':'' ?>>
              <?= htmlspecialchars(product_dropdown_label($p)) ?><?= $p['is_active'] ? '' : ' (inactive)' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="muted small" style="margin:6px 0 0;">Manage the catalogue under <a href="/admin/products.php">Products (billing)</a>. Picking a product also updates the legacy "package" field so older invoice templates keep working.</p>
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Network</h2>
    <div class="form form-grid">
      <div class="field"><label>Tower / site</label>
        <select name="site_id">
          <option value="">— none —</option>
          <?php
          $all_sites      = sites_all(false);
          $current_site   = (int)($client['site_id'] ?? 0);
          foreach ($all_sites as $s):
              if (!$s['is_active'] && (int)$s['id'] !== $current_site) continue;
          ?>
            <option value="<?= (int)$s['id'] ?>" <?= $current_site === (int)$s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['type']) ?>)<?= $s['is_active'] ? '' : ' — inactive' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Sector</label>
        <select name="sector_id">
          <option value="">— none —</option>
          <?php
          $all_sectors    = sectors_all();
          $current_sector = (int)($client['sector_id'] ?? 0);
          foreach ($all_sectors as $sec):
          ?>
            <option value="<?= (int)$sec['id'] ?>" <?= $current_sector === (int)$sec['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sec['name']) ?>
              <?php if (!empty($sec['tower_name'])): ?>
                &middot; <?= htmlspecialchars($sec['tower_name']) ?>
              <?php endif; ?>
              <?php if (!empty($sec['frequency_mhz'])): ?>
                &middot; <?= (int)$sec['frequency_mhz'] ?> MHz
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="muted small" style="margin:6px 0 0;">Manage sectors under <a href="/admin/sectors.php">Sectors</a>. Tower and sector are independent — picking a sector that lives on a different tower than the one above is allowed (the customer might be on a CPE attached directly to a non-tower AP).</p>
      </div>
    </div>
  </div>

  <?php
    // Past outages affecting this customer's sector — the same hits
    // that would have shown them an outage banner on /account/. Useful
    // for support staff investigating "why was my service down on Tue?"
    $client_sector_id = !empty($client['sector_id']) ? (int)$client['sector_id'] : 0;
    $client_outage_active   = $client_sector_id ? outage_active('sector', $client_sector_id) : null;
    $client_outage_history  = $client_sector_id
        ? outages_all(['scope' => 'sector', 'status' => 'resolved'], 5)
        : [];
    if ($client_sector_id && $client_outage_history) {
        $client_outage_history = array_values(array_filter(
            $client_outage_history,
            fn($o) => (int)$o['scope_id'] === $client_sector_id
        ));
    }
  ?>
  <?php if ($client_sector_id): ?>
  <div class="portal-card">
    <h2>Network activity</h2>
    <?php if ($client_outage_active): ?>
      <div class="alert alert-warning" style="margin-bottom:14px;">
        <strong>Active outage on this customer's sector.</strong>
        Started <?= htmlspecialchars((string)$client_outage_active['started_at']) ?>
        <?php if (!empty($client_outage_active['cause'])): ?>
          &middot; <?= htmlspecialchars((string)$client_outage_active['cause']) ?>
        <?php endif; ?>
        <span class="alert-meta"><?= (int)$client_outage_active['affected_count'] ?> customer<?= (int)$client_outage_active['affected_count'] === 1 ? '' : 's' ?> on this sector.</span>
      </div>
    <?php endif; ?>
    <?php if ($client_outage_history): ?>
      <table class="data-table">
        <thead><tr><th>Started</th><th>Resolved</th><th>Cause</th><th style="text-align:right;">Affected</th></tr></thead>
        <tbody>
          <?php foreach ($client_outage_history as $o): ?>
            <tr>
              <td><small><?= htmlspecialchars((string)$o['started_at']) ?></small></td>
              <td><small><?= htmlspecialchars((string)($o['resolved_at'] ?? '')) ?></small></td>
              <td><small><?= htmlspecialchars((string)($o['cause'] ?? '—')) ?></small></td>
              <td style="text-align:right;"><?= (int)$o['affected_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif (!$client_outage_active): ?>
      <p class="muted" style="margin:0;">No outage history on this customer's sector yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

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
