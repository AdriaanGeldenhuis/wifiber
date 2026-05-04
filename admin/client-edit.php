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
require_once __DIR__ . '/../auth/tickets.php';
require_once __DIR__ . '/../auth/invoices.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/client_notes.php';
require_once __DIR__ . '/../auth/validators.php';

$id     = (int)($_GET['id'] ?? 0);
$client = $id ? find_user_by_id($id) : null;
if (!$client || ($client['role'] ?? '') !== 'client') {
    flash('error', 'Client not found.');
    header('Location: /admin/clients.php');
    exit;
}

// Address-picker AJAX endpoints — return JSON and exit before the page
// chrome renders. Auth has already been enforced by _layout.php.
if (isset($_GET['suggest'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $results = nominatim_search((string)$_GET['suggest'], 5);
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}
if (isset($_GET['reverse_lat'], $_GET['reverse_lng'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $name = nominatim_reverse((float)$_GET['reverse_lat'], (float)$_GET['reverse_lng']);
    echo json_encode(['ok' => true, 'display_name' => $name]);
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ---------- Note sub-actions ----------
    if ($action === 'add_note') {
        try {
            $note_id = client_note_create(
                $id,
                (int)($user['id'] ?? 0) ?: null,
                (string)($_POST['note_body'] ?? ''),
                !empty($_POST['note_pin'])
            );
            audit_log('client.note_add', ['target_type' => 'user', 'target_id' => $id, 'meta' => ['note_id' => $note_id]]);
            flash('success', 'Note added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /admin/client-edit.php?id=' . $id . '#notes');
        exit;
    }
    if ($action === 'delete_note') {
        $nid = (int)($_POST['note_id'] ?? 0);
        if ($nid && client_note_delete($nid, $id)) {
            audit_log('client.note_delete', ['target_type' => 'user', 'target_id' => $id, 'meta' => ['note_id' => $nid]]);
            flash('success', 'Note deleted.');
        } else {
            flash('error', 'Could not delete note.');
        }
        header('Location: /admin/client-edit.php?id=' . $id . '#notes');
        exit;
    }
    if ($action === 'pin_note') {
        $nid = (int)($_POST['note_id'] ?? 0);
        if ($nid) client_note_toggle_pin($nid, $id);
        header('Location: /admin/client-edit.php?id=' . $id . '#notes');
        exit;
    }

    // ---------- Device sub-actions ----------
    if ($action === 'attach_device') {
        $did = (int)($_POST['device_id'] ?? 0);
        if ($did > 0 && device_find($did)) {
            device_set_customer($did, $id);
            audit_log('client.device_attach', ['target_type' => 'user', 'target_id' => $id, 'meta' => ['device_id' => $did]]);
            flash('success', 'Device linked to this client.');
        } else {
            flash('error', 'Device not found.');
        }
        header('Location: /admin/client-edit.php?id=' . $id . '#devices');
        exit;
    }
    if ($action === 'detach_device') {
        $did = (int)($_POST['device_id'] ?? 0);
        if ($did > 0) {
            device_set_customer($did, null);
            audit_log('client.device_detach', ['target_type' => 'user', 'target_id' => $id, 'meta' => ['device_id' => $did]]);
            flash('success', 'Device unlinked.');
        }
        header('Location: /admin/client-edit.php?id=' . $id . '#devices');
        exit;
    }

    // ---------- Main field save ----------
    $name    = trim($_POST['name']    ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $email   = trim($_POST['email']   ?? '');
    if ($name === '')                                                $errors[] = 'Display name is required.';
    if ($surname === '')                                             $errors[] = 'Surname is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
    if (($_POST['lat'] ?? '') !== '' && !is_numeric($_POST['lat']))  $errors[] = 'Latitude must be numeric.';
    if (($_POST['lng'] ?? '') !== '' && !is_numeric($_POST['lng']))  $errors[] = 'Longitude must be numeric.';

    $id_check    = validate_sa_id((string)($_POST['id_number']    ?? ''));
    $vat_check   = validate_sa_vat((string)($_POST['vat_number']   ?? ''));
    $mac_check   = validate_mac((string)($_POST['equipment_mac']  ?? ''));
    $ip_check    = validate_ip((string)($_POST['equipment_ip']    ?? ''));
    $phone_check = normalize_phone_e164((string)($_POST['phone']  ?? ''));
    $alt_check   = normalize_phone_e164((string)($_POST['alt_contact_phone'] ?? ''));
    foreach ([
        'ID number'        => $id_check,
        'VAT number'       => $vat_check,
        'Equipment MAC'    => $mac_check,
        'Equipment IP'     => $ip_check,
        'Phone'            => $phone_check,
        'Alt phone'        => $alt_check,
    ] as $label => $check) {
        if (!$check['ok']) $errors[] = "$label: {$check['error']}";
    }

    if (!$errors) {
        $patch = [
            'name'              => $name,
            'surname'           => $surname,
            'id_number'         => $id_check['value'],
            'vat_number'        => $vat_check['value'],
            'email'             => $email,
            'phone'             => trim($_POST['phone']             ?? ''),
            'phone_e164'        => $phone_check['value'],
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
            'equipment_mac'     => $mac_check['value'],
            'equipment_ip'      => $ip_check['value'],
            'equipment_serial'  => trim($_POST['equipment_serial']  ?? ''),
            'equipment_model'   => trim($_POST['equipment_model']   ?? ''),
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
      <div class="field" style="grid-column:1/-1; position:relative;">
        <label>Street address <span class="muted small">(start typing for suggestions)</span></label>
        <input type="text" name="address" id="addr-input" maxlength="200" value="<?= $v('address') ?>" autocomplete="off">
        <div id="addr-suggestions" class="addr-suggestions" hidden></div>
      </div>
      <div class="field"><label>Latitude</label>
        <input type="number" step="any" name="lat" id="addr-lat" value="<?= $v('lat') ?>" placeholder="-26.7100000">
      </div>
      <div class="field"><label>Longitude</label>
        <input type="number" step="any" name="lng" id="addr-lng" value="<?= $v('lng') ?>" placeholder="27.8300000">
      </div>
    </div>
    <div id="addr-map" class="addr-map" aria-label="Click or drag the pin to set GPS coordinates"></div>
    <p class="muted small" id="addr-hint" style="margin:8px 0 0;">
      Click anywhere on the map to drop a pin, drag it to fine-tune, or pick a Nominatim suggestion above. Lat/Lng update live.
    </p>
    <div class="form-actions" style="margin-top:8px; flex-wrap:wrap;">
      <button type="button" id="addr-locate"  class="btn btn-ghost btn-sm">Use my location</button>
      <button type="button" id="addr-reverse" class="btn btn-ghost btn-sm">Fill address from pin</button>
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
    <h2>Legacy CPE fields <span class="muted small" style="font-weight:normal;">(single record on the user row)</span></h2>
    <p class="muted small">Prefer the <a href="#devices">Linked devices</a> panel below for new installs — it supports multiple radios/routers/switches per client and ties them to the network map. These fields are kept so older readers don't break; MAC and IP are validated and normalised on save.</p>
    <div class="form form-grid">
      <div class="field"><label>MAC address</label>
        <input type="text" name="equipment_mac" maxlength="20" value="<?= $v('equipment_mac') ?>" placeholder="aa:bb:cc:dd:ee:ff" pattern="^([0-9a-fA-F]{2}[:\-]?){5}[0-9a-fA-F]{2}$">
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

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Save all changes</button>
    <a href="/admin/clients.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<?php
  // ---------- Read-only context panels (tickets / invoices / devices / notes) ----------
  $client_tickets   = tickets_for_user($id);
  $client_invoices  = invoices_for_user($id);
  $client_devices   = devices_for_customer($id);
  $client_notes     = client_notes_for($id);

  $invoice_outstanding = 0.0;
  $invoice_overdue_n   = 0;
  foreach ($client_invoices as $inv) {
      $eff = invoice_effective_status($inv);
      if (in_array($eff, ['unpaid', 'overdue'], true)) {
          $invoice_outstanding += (float)$inv['total'];
          if ($eff === 'overdue') $invoice_overdue_n++;
      }
  }
  $open_tickets = array_values(array_filter($client_tickets, fn($t) => $t['status'] !== 'closed'));
?>

<div class="portal-card" id="devices">
  <h2>Linked devices <span class="muted small" style="font-weight:normal;">(<?= count($client_devices) ?>)</span></h2>
  <?php if ($client_devices): ?>
    <table class="data-table">
      <thead><tr><th>Name</th><th>Role</th><th>Vendor / model</th><th>MAC</th><th>Mgmt IP</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($client_devices as $d): ?>
          <tr>
            <td><a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></a></td>
            <td><small><?= htmlspecialchars($d['role']) ?></small></td>
            <td><small><?= htmlspecialchars(trim(($d['vendor'] ?? '') . ' ' . ($d['model'] ?? ''))) ?></small></td>
            <td><small><code><?= htmlspecialchars($d['mac'] ?? '') ?></code></small></td>
            <td><small><code><?= htmlspecialchars($d['mgmt_ip'] ?? '') ?></code></small></td>
            <td><small><?= htmlspecialchars($d['status'] ?? '') ?></small></td>
            <td style="text-align:right;">
              <form method="post" class="inline-form" data-confirm="Unlink <?= htmlspecialchars($d['name'], ENT_QUOTES) ?> from this client?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="detach_device">
                <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Unlink</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted" style="margin:0 0 12px;">No devices linked yet. Create devices on <a href="/admin/devices.php">Devices</a>, then attach one below.</p>
  <?php endif; ?>

  <form method="post" class="inline-form" style="margin-top:12px;flex-wrap:wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="attach_device">
    <select name="device_id" required style="min-width:280px;">
      <option value="">— pick a device to link —</option>
      <?php
        // Show devices not currently linked to anyone, plus any of this client's already-linked
        // ones (excluded so the dropdown doesn't repeat them).
        $linked_ids = array_map(fn($d) => (int)$d['id'], $client_devices);
        $available = array_values(array_filter(devices_all(), fn($d) =>
            !in_array((int)$d['id'], $linked_ids, true) &&
            ($d['customer_id'] === null || (int)$d['customer_id'] === $id)
        ));
        foreach ($available as $d):
          $bits = [$d['name']];
          if (!empty($d['role']))     $bits[] = $d['role'];
          if (!empty($d['site_name']))$bits[] = '@ ' . $d['site_name'];
          if (!empty($d['mac']))      $bits[] = $d['mac'];
      ?>
        <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars(implode(' · ', $bits)) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Link device</button>
  </form>
</div>

<div class="portal-card" id="tickets">
  <h2>
    Support tickets
    <span class="muted small" style="font-weight:normal;">
      (<?= count($open_tickets) ?> open / <?= count($client_tickets) ?> total)
    </span>
  </h2>
  <?php if ($client_tickets): ?>
    <table class="data-table">
      <thead><tr><th>#</th><th>Subject</th><th>Status</th><th>Updated</th><th>Msgs</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($client_tickets, 0, 10) as $t): ?>
          <tr>
            <td>#<?= (int)$t['id'] ?></td>
            <td><a href="/admin/tickets.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['subject']) ?></a></td>
            <td><small><?= htmlspecialchars(TICKET_STATUS_LABELS[$t['status']] ?? $t['status']) ?></small></td>
            <td><small><?= htmlspecialchars((string)$t['updated_at']) ?></small></td>
            <td style="text-align:right;"><?= (int)$t['message_count'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($client_tickets) > 10): ?>
      <p class="muted small" style="margin:8px 0 0;">Showing 10 most-recent. <a href="/admin/tickets.php?user_id=<?= $id ?>">See all</a>.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted" style="margin:0;">No tickets yet.</p>
  <?php endif; ?>
</div>

<div class="portal-card" id="invoices">
  <h2>
    Invoices
    <?php if ($invoice_outstanding > 0): ?>
      <span class="pkg-pill" style="background:<?= $invoice_overdue_n ? '#a33' : '#553' ?>;">
        R <?= number_format($invoice_outstanding, 2) ?> outstanding<?= $invoice_overdue_n ? " · {$invoice_overdue_n} overdue" : '' ?>
      </span>
    <?php endif; ?>
    <span class="muted small" style="font-weight:normal;">(<?= count($client_invoices) ?> total)</span>
  </h2>
  <?php if ($client_invoices): ?>
    <table class="data-table">
      <thead><tr><th>Number</th><th>Issued</th><th>Due</th><th>Status</th><th style="text-align:right;">Total</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($client_invoices, 0, 12) as $inv):
          $eff = invoice_effective_status($inv);
        ?>
          <tr>
            <td><a href="/admin/invoice-edit.php?id=<?= (int)$inv['id'] ?>"><?= htmlspecialchars($inv['number'] ?: '#' . $inv['id']) ?></a></td>
            <td><small><?= htmlspecialchars((string)$inv['issued_at']) ?></small></td>
            <td><small><?= htmlspecialchars((string)$inv['due_at']) ?></small></td>
            <td><small><?= htmlspecialchars(INVOICE_STATUS_LABELS[$eff] ?? $eff) ?></small></td>
            <td style="text-align:right;">R <?= number_format((float)$inv['total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small" style="margin:8px 0 0;"><a href="/admin/invoices.php?user_id=<?= $id ?>">All invoices for this client</a> &middot; <a href="/admin/invoice-edit.php?user_id=<?= $id ?>">+ New invoice</a></p>
  <?php else: ?>
    <p class="muted" style="margin:0;">No invoices yet. <a href="/admin/invoice-edit.php?user_id=<?= $id ?>">Create one</a>.</p>
  <?php endif; ?>
</div>

<div class="portal-card" id="notes">
  <h2>Notes <span class="muted small" style="font-weight:normal;">(<?= count($client_notes) ?>)</span></h2>

  <form method="post" class="form" style="margin-bottom:14px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_note">
    <textarea name="note_body" rows="3" required maxlength="4000"
              placeholder="Add a note (visible to admins only)…"
              style="width:100%; font-family:inherit;"></textarea>
    <div class="form-actions" style="margin-top:8px;">
      <label class="inline-check">
        <input type="checkbox" name="note_pin" value="1"> Pin to top
      </label>
      <button type="submit" class="btn btn-primary btn-sm">Add note</button>
    </div>
  </form>

  <?php if (!empty($client['notes'])): ?>
    <div class="alert alert-warning" style="margin-bottom:14px;">
      <strong>Legacy notes</strong> <span class="muted small">(from before phase 22)</span>
      <pre style="white-space:pre-wrap;font-family:inherit;margin:8px 0 0;"><?= htmlspecialchars($client['notes']) ?></pre>
    </div>
  <?php endif; ?>

  <?php if ($client_notes): ?>
    <ul class="note-list">
      <?php foreach ($client_notes as $n): ?>
        <li class="note-item<?= $n['is_pinned'] ? ' is-pinned' : '' ?>">
          <div class="note-meta">
            <strong><?= htmlspecialchars($n['author_name'] ?: $n['author_username'] ?: 'system') ?></strong>
            <span class="muted small">&middot; <?= htmlspecialchars((string)$n['created_at']) ?></span>
            <?php if ($n['is_pinned']): ?>
              <span class="pkg-pill" style="background:#553;">pinned</span>
            <?php endif; ?>
            <span style="margin-left:auto; display:inline-flex; gap:6px;">
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pin_note">
                <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="Toggle pin"><?= $n['is_pinned'] ? 'Unpin' : 'Pin' ?></button>
              </form>
              <form method="post" class="inline-form" data-confirm="Delete this note?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_note">
                <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Delete</button>
              </form>
            </span>
          </div>
          <div class="note-body"><?= nl2br(htmlspecialchars($n['body'])) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted" style="margin:0;">No notes yet.</p>
  <?php endif; ?>
</div>

<style>
  .note-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:10px; }
  .note-item {
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 10px 12px;
  }
  .note-item.is-pinned { border-color: rgba(5,218,253,.35); background: var(--accent-soft, rgba(5,218,253,.06)); }
  .note-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12px; color:var(--text-dim); margin-bottom:4px; }
  .note-body { white-space:pre-wrap; color:var(--text); font-size:13px; line-height:1.5; }
</style>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="anonymous">
<style>
  .addr-suggestions {
    position: absolute;
    top: 100%; left: 0; right: 0;
    background: var(--bg-card, #1a1d24);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    z-index: 1000;
    max-height: 240px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.4);
    margin-top: 2px;
  }
  .addr-suggestion {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
    color: var(--text-dim);
  }
  .addr-suggestion:last-child { border-bottom: none; }
  .addr-suggestion:hover,
  .addr-suggestion.is-active {
    background: var(--accent-soft);
    color: var(--accent);
  }
  .addr-map {
    height: 320px;
    margin-top: 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: #0a0d12;
  }
  .leaflet-container { font-family: inherit; }
</style>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous" defer></script>
<script defer>
(function initAddrPicker() {
  if (document.readyState === 'loading') {
    return document.addEventListener('DOMContentLoaded', initAddrPicker);
  }
  if (typeof L === 'undefined') {
    // Leaflet's <script defer> is queued behind us — wait a tick.
    return setTimeout(initAddrPicker, 50);
  }

  const mapEl     = document.getElementById('addr-map');
  const addrInput = document.getElementById('addr-input');
  const latInput  = document.getElementById('addr-lat');
  const lngInput  = document.getElementById('addr-lng');
  const sugBox    = document.getElementById('addr-suggestions');
  const hint      = document.getElementById('addr-hint');
  const locateBtn = document.getElementById('addr-locate');
  const reverseBtn= document.getElementById('addr-reverse');
  if (!mapEl || !addrInput || !latInput || !lngInput) return;

  const ENDPOINT = '?id=' + <?= (int)$id ?>;
  const DEFAULT_CENTER = [-26.7100, 27.8300]; // Vaal Triangle
  const startLat = parseFloat(latInput.value);
  const startLng = parseFloat(lngInput.value);
  const hasStart = Number.isFinite(startLat) && Number.isFinite(startLng);

  const map = L.map(mapEl).setView(
    hasStart ? [startLat, startLng] : DEFAULT_CENTER,
    hasStart ? 16 : 11
  );
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);

  // Re-trigger Leaflet's size calc once layout settles — prevents the
  // "grey tiles top-left only" issue when the card is inside a long form.
  setTimeout(() => map.invalidateSize(), 100);

  let marker = null;
  function setCoords(lat, lng, opts) {
    opts = opts || {};
    const ll = [lat, lng];
    if (marker) {
      marker.setLatLng(ll);
    } else {
      marker = L.marker(ll, { draggable: true }).addTo(map);
      marker.on('drag dragend', () => {
        const p = marker.getLatLng();
        latInput.value = p.lat.toFixed(7);
        lngInput.value = p.lng.toFixed(7);
      });
    }
    latInput.value = (+lat).toFixed(7);
    lngInput.value = (+lng).toFixed(7);
    if (opts.recenter) map.setView(ll, opts.zoom || Math.max(map.getZoom(), 16));
  }
  if (hasStart) setCoords(startLat, startLng);

  map.on('click', (e) => setCoords(e.latlng.lat, e.latlng.lng));

  function onCoordsTyped() {
    const la = parseFloat(latInput.value);
    const ln = parseFloat(lngInput.value);
    if (Number.isFinite(la) && Number.isFinite(ln)) {
      setCoords(la, ln, { recenter: true });
    }
  }
  latInput.addEventListener('change', onCoordsTyped);
  lngInput.addEventListener('change', onCoordsTyped);

  // ---------- Address autocomplete ----------
  let sugAbort = null, sugTimer = null, sugResults = [], sugIndex = -1;
  function clearSug() {
    sugBox.innerHTML = ''; sugBox.hidden = true;
    sugResults = []; sugIndex = -1;
  }
  function renderSug() {
    sugBox.innerHTML = sugResults.map((r, i) =>
      '<div class="addr-suggestion' + (i === sugIndex ? ' is-active' : '') +
      '" data-i="' + i + '">' + escapeHtml(r.display_name) + '</div>'
    ).join('');
    sugBox.hidden = sugResults.length === 0;
  }
  function pickSuggestion(i) {
    const r = sugResults[i];
    if (!r) return;
    addrInput.value = r.display_name;
    setCoords(r.lat, r.lng, { recenter: true });
    hint.textContent = 'Address picked. Drag the pin if needed.';
    clearSug();
  }
  addrInput.addEventListener('input', () => {
    clearTimeout(sugTimer);
    if (sugAbort) sugAbort.abort();
    const q = addrInput.value.trim();
    if (q.length < 3) { clearSug(); return; }
    sugTimer = setTimeout(() => {
      sugAbort = new AbortController();
      fetch(ENDPOINT + '&suggest=' + encodeURIComponent(q), {
        credentials: 'same-origin', signal: sugAbort.signal,
      })
        .then(r => r.json())
        .then(j => {
          if (!j || !j.ok) return clearSug();
          sugResults = j.results || [];
          sugIndex = -1;
          renderSug();
        })
        .catch(() => {});
    }, 350);
  });
  addrInput.addEventListener('keydown', (e) => {
    if (sugBox.hidden || !sugResults.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      sugIndex = (sugIndex + 1) % sugResults.length;
      renderSug();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      sugIndex = (sugIndex - 1 + sugResults.length) % sugResults.length;
      renderSug();
    } else if (e.key === 'Enter' && sugIndex >= 0) {
      e.preventDefault();
      pickSuggestion(sugIndex);
    } else if (e.key === 'Escape') {
      clearSug();
    }
  });
  addrInput.addEventListener('blur', () => setTimeout(clearSug, 200));
  sugBox.addEventListener('mousedown', (e) => {
    const item = e.target.closest('.addr-suggestion');
    if (item) pickSuggestion(+item.dataset.i);
  });

  // ---------- Use my location ----------
  if (locateBtn) {
    locateBtn.addEventListener('click', () => {
      if (!navigator.geolocation) {
        hint.textContent = 'Geolocation not supported in this browser.';
        return;
      }
      hint.textContent = 'Getting your location…';
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          setCoords(pos.coords.latitude, pos.coords.longitude, { recenter: true, zoom: 18 });
          hint.textContent = 'Located. Drag the pin to fine-tune.';
        },
        (err) => { hint.textContent = 'Could not get location: ' + err.message; },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  // ---------- Reverse geocode (pin → address) ----------
  if (reverseBtn) {
    reverseBtn.addEventListener('click', () => {
      if (!marker) { hint.textContent = 'Drop a pin on the map first.'; return; }
      const ll = marker.getLatLng();
      hint.textContent = 'Looking up address…';
      fetch(ENDPOINT + '&reverse_lat=' + ll.lat + '&reverse_lng=' + ll.lng, {
        credentials: 'same-origin',
      })
        .then(r => r.json())
        .then(j => {
          if (j && j.ok && j.display_name) {
            addrInput.value = j.display_name;
            hint.textContent = 'Address filled from pin location.';
          } else {
            hint.textContent = 'No address found for that pin.';
          }
        })
        .catch(() => { hint.textContent = 'Address lookup failed.'; });
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
})();
</script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
