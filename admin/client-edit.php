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
