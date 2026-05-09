<?php
/**
 * Client dashboard — UISP-style customer details page. Same banner-and-
 * cards layout as link-view.php / sector-view.php / device-view.php so
 * the operator's eye doesn't have to retrain when jumping between a
 * customer record and the radio that serves them.
 *
 *   Banner       Customer icon + name + status pill + account number,
 *                wireless health dial (worst-of all their links), distance
 *                to the AP that serves them, package + service-start dial,
 *                site / sector card.
 *   Tabs         Map · Profile · Service · Edit · (Link ↗ if any)
 *   Cards        Profile (personal: name, surname, ID, VAT, type, phone,
 *                email, address, GPS, billing day, package, payment
 *                method, service start, alt contact); Service (RADIUS
 *                username, package, sector, site, equipment MAC/IP/
 *                serial/model, last login); Wireless link health (signal,
 *                SNR, CCQ, TX/RX, distance, last sample) for each
 *                wireless_links row tied to the customer; CPE / linked
 *                devices table with status pills.
 *   Billing      Recent invoices, payments, account status timeline.
 *   Tickets      Open + recent support tickets with severity + age.
 *   Notes        Pinned + timestamped client_notes.
 *
 * Data sources:
 *   users                     the customer row.
 *   wireless_links            radio links keyed by customer_id.
 *   sectors                   the sector they're attached to.
 *   sites                     the site/tower for that sector.
 *   devices                   their CPE(s) via devices_for_customer().
 *   device_health             latest poll for each CPE.
 *   invoices, payments        last few of each.
 *   tickets, client_notes     last few support / annotation rows.
 */
$page_title = 'Client';
$active_key = 'clients';
$auto_refresh_seconds = 60;
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/poll_status.php';
require_once __DIR__ . '/_link-charts.php';

$id = (int)($_GET['id'] ?? 0);
$client = $id ? find_user_by_id($id) : null;
if (!$client || ($client['role'] ?? '') !== 'client') {
    flash('error', 'Client not found.');
    header('Location: /admin/clients.php');
    exit;
}

$tab = (string)($_GET['tab'] ?? 'profile');

/* Their site + sector. */
$site   = !empty($client['site_id'])   ? site_find((int)$client['site_id'])     : null;
$sector = !empty($client['sector_id']) ? sector_find((int)$client['sector_id']) : null;

/* Their devices (typically a CPE at the premises). */
$devices = devices_for_customer((int)$client['id']);

/* Their wireless links (joined with AP/CPE/sector names). */
$links = wireless_links_all(['customer_id' => (int)$client['id']]);

/* Worst health score across their links → the dial in the banner. */
$worst_health = null;
foreach ($links as $l) {
    if ($l['health_score'] !== null) {
        $hs = (int)$l['health_score'];
        if ($worst_health === null || $hs < $worst_health) $worst_health = $hs;
    }
}

/* Latest health for each CPE (for the linked-devices card). */
$dev_health = [];
foreach ($devices as $d) {
    $r = device_recent_health((int)$d['id'], 1);
    $dev_health[(int)$d['id']] = $r[0] ?? null;
}

/* Recent invoices, payments, tickets, notes — best-effort, tables may
   not exist on a fresh install so wrap each in a try/catch. */
$invoices = [];
$payments = [];
$tickets  = [];
$notes    = [];
try {
    $stmt = pdo()->prepare(
        "SELECT id, number, issue_date, due_date, status, total
           FROM invoices WHERE user_id = ? ORDER BY issue_date DESC, id DESC LIMIT 6"
    );
    $stmt->execute([(int)$client['id']]);
    $invoices = $stmt->fetchAll();
} catch (Throwable $e) { /* table may not exist */ }
try {
    $stmt = pdo()->prepare(
        "SELECT id, paid_at, amount, method, reference
           FROM payments WHERE user_id = ? ORDER BY paid_at DESC, id DESC LIMIT 6"
    );
    $stmt->execute([(int)$client['id']]);
    $payments = $stmt->fetchAll();
} catch (Throwable $e) { }
try {
    $stmt = pdo()->prepare(
        "SELECT id, subject, status, severity, created_at, updated_at
           FROM tickets WHERE user_id = ? ORDER BY (status='open') DESC, updated_at DESC LIMIT 6"
    );
    $stmt->execute([(int)$client['id']]);
    $tickets = $stmt->fetchAll();
} catch (Throwable $e) { }
try {
    $stmt = pdo()->prepare(
        "SELECT id, body, pinned, created_at FROM client_notes
          WHERE user_id = ? ORDER BY pinned DESC, created_at DESC LIMIT 8"
    );
    $stmt->execute([(int)$client['id']]);
    $notes = $stmt->fetchAll();
} catch (Throwable $e) { }

/* AP that serves them (via the sector → ap_device_id, fall back to the
   first link's ap device). */
$serving_ap = null;
if ($sector && $sector['ap_device_id']) {
    $serving_ap = device_find((int)$sector['ap_device_id']);
} elseif ($links) {
    $serving_ap = device_find((int)$links[0]['ap_device_id']);
}

/* Distance to the AP for the banner pill. */
$dist_km = null;
$ap_site = $serving_ap && $serving_ap['site_id'] ? site_find((int)$serving_ap['site_id']) : null;
if ($ap_site && $client['lat'] !== null && $client['lng'] !== null) {
    $dist_km = haversine_km(
        (float)$ap_site['lat'], (float)$ap_site['lng'],
        (float)$client['lat'],  (float)$client['lng']
    );
}
$dist_km ??= ($links && $links[0]['distance_km'] !== null ? (float)$links[0]['distance_km'] : null);

$status_colour = match ($client['status'] ?? '') {
    'active'        => '#4ade80',
    'suspended'     => '#e8a814',
    'disconnected'  => '#ff5470',
    'lead'          => '#05DAFD',
    default         => '#6b7480',
};

$customer_label = trim((string)($client['name'] ?? '') . ' ' . (string)($client['surname'] ?? '')) ?: ($client['username'] ?? '#' . (int)$client['id']);
$cust_type      = (string)($client['customer_type'] ?? 'residential');
$service_age_d  = !empty($client['service_start']) ? max(0, (int)((time() - strtotime((string)$client['service_start'])) / 86400)) : null;
?>

<style>
  .lv-grid     { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
  .lv-grid > * { min-width:0; }
  @media (max-width: 980px) { .lv-grid { grid-template-columns: 1fr; } }

  .lv-bigstat  { font-size:34px; font-weight:300; line-height:1; color:var(--text); letter-spacing:-0.02em; }
  .lv-suffix   { font-size:13px; color:var(--text-muted); }
  .lv-label    { font-size:10.5px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.07em; font-weight:600; }
  .lv-row      { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04); font-size:13px; gap:12px; }
  .lv-row:last-child { border-bottom:none; }
  .lv-row b    { font-weight:500; color:var(--text-dim); }
  .lv-row span:last-child { color:var(--text); font-variant-numeric:tabular-nums; }

  .lv-pill     { display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;color:#001218;font-weight:700;letter-spacing:.02em;text-transform:uppercase; }
  .lv-tag      { display:inline-block;padding:1px 8px;border-radius:8px;font-size:10.5px;color:var(--text-dim);background:rgba(255,255,255,0.04);border:1px solid var(--border);letter-spacing:.05em; }

  .lv-tabs     { display:flex; gap:4px; padding:4px; background:var(--bg-elev); border:1px solid var(--border); border-radius:9px; width:max-content; margin:18px auto; }
  .lv-tab      { padding:5px 16px; border-radius:7px; font-size:12px; color:var(--text-dim); }
  .lv-tab:hover { color:var(--text); }
  .lv-tab.active { background:var(--accent-soft); color:var(--accent); box-shadow:inset 0 0 0 1px var(--accent); }

  .lv-banner {
    display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; gap:20px;
    padding:18px 22px; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
  }
  .lv-banner .lv-side  { display:flex; align-items:center; gap:16px; }
  .lv-banner .lv-side.lv-end { justify-content:flex-end; }
  .lv-banner .lv-mid   { text-align:center; }
  .lv-icon {
    width:46px; height:46px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
    border-radius:50%; background:var(--bg-elev); border:1px solid var(--border-strong); color:var(--accent);
  }
  .lv-banner-distance {
    display:inline-flex; align-items:center; gap:8px;
    background:#000; color:#f4f6f8; padding:8px 16px; border-radius:18px; font-size:13px;
    border:1px solid var(--border-strong); font-variant-numeric:tabular-nums;
  }
  .lv-airtime  { font-size:11px; color:var(--text-muted); margin-top:6px; letter-spacing:.04em; }
  .lv-airtime b { color:var(--text-dim); font-weight:500; }
  .lv-arrow    {
    flex:1; display:flex; align-items:center; justify-content:center; gap:8px;
    color:var(--text-muted); font-size:11px;
  }
  .lv-arrow .lv-arrow-line { flex:1; height:1px; background:linear-gradient(90deg, transparent, var(--border-strong), transparent); }

  .lv-dial {
    display:inline-flex; flex-direction:column; align-items:center; justify-content:center;
    width:88px; height:88px; border-radius:50%;
    border:3px solid var(--accent); background:var(--bg-elev);
    box-shadow:0 0 0 4px var(--accent-soft); flex-shrink:0;
  }
  .lv-dial small { display:block; font-size:8.5px; color:var(--text-muted); text-align:center; line-height:1.05; text-transform:uppercase; letter-spacing:.04em; }
  .lv-dial b     { font-size:18px; font-weight:500; color:var(--text); margin:2px 0; font-variant-numeric:tabular-nums; }
  .lv-dial .lv-dial-unit { font-size:9px; color:var(--text-muted); }

  .lv-endpoint h4 { margin:0 0 2px; font-size:14px; font-weight:600; color:var(--text); }

  .lv-grid-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
  .lv-grid-hdr h3 { margin:0; }
  .lv-mini-section { padding-top:14px; border-top:1px solid var(--border); margin-top:14px; }
  .lv-mini-section h4 { font-size:11px; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); margin:0 0 8px; font-weight:600; }

  .data-table.compact th, .data-table.compact td { padding:8px 10px; font-size:12.5px; }
  .data-table tr.row-poor td { background:rgba(212,68,68,0.06); }
  .data-table tr.row-fair td { background:rgba(232,168,20,0.06); }

  .device-list { display:flex; flex-direction:column; gap:6px; }
  .device-list a {
    display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 10px;
    background:var(--bg-elev); border:1px solid var(--border); border-radius:8px;
    font-size:13px; color:var(--text);
  }
  .device-list a:hover { border-color:var(--accent); color:var(--accent); }
  .device-list small { color:var(--text-muted); }
</style>

<div class="lv-banner">
  <div class="lv-side lv-endpoint">
    <span class="lv-icon" title="Customer">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="8" r="4"/><path d="M4 22c0-4.418 3.582-8 8-8s8 3.582 8 8"/>
      </svg>
    </span>
    <div>
      <div class="lv-label">Customer · <?= lv_h(ucfirst($cust_type)) ?></div>
      <h4><?= lv_h($customer_label) ?>
        <span class="lv-pill" style="background:<?= $status_colour ?>;color:#001218;"><?= lv_h($client['status'] ?? 'active') ?></span>
      </h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?php if (!empty($client['account_no'])): ?>Account #<?= lv_h($client['account_no']) ?><?php endif; ?>
        <?php if (!empty($client['username'])):   ?> · <?= lv_h($client['username']) ?><?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        <?= lv_h($client['email'] ?? '') ?: '—' ?>
        <?php if (!empty($client['phone'])): ?> · <?= lv_h($client['phone']) ?><?php endif; ?>
      </div>
    </div>
    <div class="lv-dial" title="Worst link health across this customer's wireless links">
      <small>Link<br>Health</small>
      <b><?= $worst_health !== null ? $worst_health : '—' ?></b>
      <span class="lv-dial-unit"><?= $worst_health !== null ? '/100' : 'no data' ?></span>
    </div>
  </div>

  <div class="lv-mid">
    <div class="lv-arrow">
      <span class="lv-arrow-line"></span>
      <span class="lv-banner-distance">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11V7a4 4 0 0 0-8 0v4"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
        <strong><?= $dist_km !== null ? lv_fmt_ft($dist_km) : '—' ?></strong>
      </span>
      <span class="lv-arrow-line"></span>
    </div>
    <div class="lv-airtime">
      <b>Package</b> <?= lv_h($client['package'] ?? '') ?: '—' ?>
      &nbsp;·&nbsp; <b>Billing day</b> <?= !empty($client['billing_day']) ? (int)$client['billing_day'] : '—' ?>
    </div>
    <div class="lv-airtime"><b>Service start</b>
      <?= !empty($client['service_start']) ? lv_h($client['service_start']) : '—' ?>
      <?php if ($service_age_d !== null): ?> <span class="muted">(<?= $service_age_d ?> day<?= $service_age_d === 1 ? '' : 's' ?>)</span><?php endif; ?>
    </div>
    <div class="lv-airtime"><b>Payment</b> <?= lv_h(strtoupper((string)($client['payment_method'] ?? 'eft'))) ?></div>
    <?php if (!empty($client['lat']) && !empty($client['lng'])): ?>
      <div class="lv-airtime"><b>GPS</b> <?= number_format((float)$client['lat'], 5) ?>, <?= number_format((float)$client['lng'], 5) ?></div>
    <?php endif; ?>
  </div>

  <div class="lv-side lv-end lv-endpoint">
    <div class="lv-dial" title="Service days">
      <small>Service<br>days</small>
      <b><?= $service_age_d !== null ? $service_age_d : '—' ?></b>
      <span class="lv-dial-unit">d</span>
    </div>
    <div style="text-align:right;">
      <div class="lv-label">Site / Sector</div>
      <h4><?= lv_h($site['name'] ?? '— unassigned —') ?></h4>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-dim);font-weight:400;">
        <?php if ($sector): ?>
          <a href="/admin/sector-view.php?id=<?= (int)$sector['id'] ?>"><?= lv_h($sector['name']) ?></a>
        <?php else: ?>—<?php endif; ?>
        <?php if ($serving_ap): ?> · <a href="/admin/device-view.php?id=<?= (int)$serving_ap['id'] ?>"><?= lv_h($serving_ap['name']) ?></a><?php endif; ?>
      </div>
      <div class="lv-label" style="text-transform:none;letter-spacing:0;color:var(--text-muted);font-weight:400;">
        <?= count($devices) ?> device<?= count($devices) === 1 ? '' : 's' ?> · <?= count($links) ?> link<?= count($links) === 1 ? '' : 's' ?>
      </div>
    </div>
    <span class="lv-icon" title="Site">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2C7 2 4 5 4 9c0 6 8 13 8 13s8-7 8-13c0-4-3-7-8-7z"/><circle cx="12" cy="9" r="2"/>
      </svg>
    </span>
  </div>
</div>

<?php
  $client_freshness = poll_classify(poll_customer_latest_at((int)$client['id']));
  $client_pollable_dev = null;
  foreach ($devices as $d) {
      if (!empty($d['mgmt_ip']) && in_array($d['vendor'] ?? '', ['ubiquiti','mikrotik','cambium','mimosa'], true)) {
          $client_pollable_dev = $d;
          break;
      }
  }
?>
<div style="display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:14px;">
  <?= poll_badge_html($client_freshness, "Newest sample on this customer's links") ?>
  <?php if ($client_pollable_dev): ?>
    <button type="button" class="btn btn-ghost btn-sm" data-poll-device-now="<?= (int)$client_pollable_dev['id'] ?>" data-poll-device-name="<?= lv_h($client_pollable_dev['name']) ?>" title="Run the vendor adapter against this customer's CPE">Poll CPE now</button>
  <?php endif; ?>
  <a class="btn btn-ghost btn-sm" href="/admin/diagnostics.php">Polling status ↗</a>
</div>

<div class="lv-tabs">
  <a class="lv-tab" href="/admin/map.php?focus=client&amp;id=<?= (int)$client['id'] ?>">Map</a>
  <a class="lv-tab <?= $tab === 'profile' ? 'active' : '' ?>" href="?id=<?= (int)$client['id'] ?>&amp;tab=profile">Profile</a>
  <a class="lv-tab <?= $tab === 'service' ? 'active' : '' ?>" href="?id=<?= (int)$client['id'] ?>&amp;tab=service">Service</a>
  <a class="lv-tab <?= $tab === 'billing' ? 'active' : '' ?>" href="?id=<?= (int)$client['id'] ?>&amp;tab=billing">Billing</a>
  <a class="lv-tab <?= $tab === 'support' ? 'active' : '' ?>" href="?id=<?= (int)$client['id'] ?>&amp;tab=support">Support</a>
  <a class="lv-tab" href="/admin/client-edit.php?id=<?= (int)$client['id'] ?>">Edit</a>
  <?php if ($links): ?>
    <a class="lv-tab" href="/admin/link-view.php?id=<?= (int)$links[0]['id'] ?>">Link ↗</a>
  <?php endif; ?>
</div>

<?php if ($tab === 'service'): ?>
<div class="lv-grid">
  <!-- Wireless link health -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Wireless links · this customer</h3>
      <span class="lv-tag"><?= count($links) ?> link<?= count($links) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$links): ?>
      <small class="muted">No wireless link records yet. Once the polling worker sees the customer's CPE associate to an AP, a link auto-registers and live RF data fills in.</small>
    <?php else: foreach ($links as $l):
      $freshness = lv_sample_freshness($l['last_evaluated_at'] ?? null);
      $freshTone = match ($freshness) {
          'fresh'   => '#4ade80',
          'aging'   => '#e8a814',
          'stale'   => '#ff5470',
          default   => '#6b7480',
      };
      $freshLabel = match ($freshness) {
          'fresh'   => 'live',
          'aging'   => 'aging',
          'stale'   => 'stale',
          default   => 'no data',
      };
      $freq_parts = [];
      if (!empty($l['sector_freq']))  $freq_parts[] = (int)$l['sector_freq'] . ' MHz';
      if (!empty($l['sector_width'])) $freq_parts[] = (int)$l['sector_width'] . ' MHz wide';
      if (!empty($l['sector_band']))  $freq_parts[] = (string)$l['sector_band'];
    ?>
      <div class="lv-mini-section" style="border-top:0;padding-top:0;">
        <div class="lv-grid-hdr">
          <h4 style="margin:0;">
            <?= lv_h($l['ap_name']) ?> ↔ <?= lv_h($l['cpe_name'] ?? '—') ?>
            <?= lv_health_pill($l['health_score']) ?>
          </h4>
          <a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=<?= (int)$l['id'] ?>">Open ↗</a>
        </div>
        <div class="lv-row"><span><b>Frequency</b></span>
          <span><?= $freq_parts ? lv_h(implode(' · ', $freq_parts)) : '—' ?></span></div>
        <div class="lv-row"><span><b>Signal · noise</b></span>
          <span><?= $l['signal_dbm'] !== null ? (int)$l['signal_dbm'] . ' dBm' : '—' ?>
            <span class="muted"><?= $l['noise_dbm'] !== null ? '/ ' . (int)$l['noise_dbm'] . ' dBm' : '' ?></span>
          </span>
        </div>
        <div class="lv-row"><span><b>SNR</b></span>
          <span><?= $l['snr_db'] !== null ? (int)$l['snr_db'] . ' dB' : '—' ?></span></div>
        <div class="lv-row"><span><b>CCQ</b></span>
          <span><?= $l['ccq_pct'] !== null ? number_format((float)$l['ccq_pct'], 0) . ' %' : '—' ?></span></div>
        <div class="lv-row"><span><b>TX / RX rate</b></span>
          <span><?= $l['tx_rate_mbps'] !== null
              ? number_format((float)$l['tx_rate_mbps'], 0) . ' / ' . number_format((float)($l['rx_rate_mbps'] ?? 0), 0) . ' Mbps'
              : '—' ?></span></div>
        <div class="lv-row"><span><b>Distance</b></span>
          <span><?= $l['distance_km'] !== null
              ? number_format((float)$l['distance_km'], 2) . ' km · ' . lv_fmt_ft((float)$l['distance_km'])
              : '—' ?></span></div>
        <div class="lv-row"><span><b>Last sample</b></span>
          <span><?= lv_h(lv_fmt_dt($l['last_evaluated_at'] ?? null)) ?>
            <span class="lv-pill" style="background:<?= $freshTone ?>;color:#001218;margin-left:6px;"><?= $freshLabel ?></span>
          </span></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Equipment / linked devices -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Equipment · linked devices</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/devices.php?customer_id=<?= (int)$client['id'] ?>">All ↗</a>
    </div>
    <div class="lv-row"><span><b>RADIUS username</b></span><span><?= lv_h($client['username'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Equipment MAC</b></span>  <span><code><?= lv_h($client['equipment_mac'] ?? '') ?: '—' ?></code></span></div>
    <div class="lv-row"><span><b>Equipment IP</b></span>   <span><code><?= lv_h($client['equipment_ip'] ?? '') ?: '—' ?></code></span></div>
    <div class="lv-row"><span><b>Equipment serial</b></span><span><?= lv_h($client['equipment_serial'] ?? '') ?: '—' ?></span></div>
    <div class="lv-row"><span><b>Equipment model</b></span> <span><?= lv_h($client['equipment_model']  ?? '') ?: '—' ?></span></div>

    <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Devices (<?= count($devices) ?>)</h3>
    <?php if (!$devices): ?>
      <small class="muted">No devices attached to this customer yet. Add one in <a href="/admin/devices.php">/admin/devices.php</a>.</small>
    <?php else: ?>
      <div class="device-list">
        <?php foreach ($devices as $d):
          $h_ = $dev_health[(int)$d['id']] ?? null;
        ?>
          <a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>">
            <span><?= lv_h($d['name']) ?>
              <small> · <?= lv_h(ucfirst((string)$d['role'])) ?>
                <?php if (!empty($d['model'])): ?> · <?= lv_h($d['model']) ?><?php endif; ?>
                <?php if ($h_ && $h_['rtt_ms'] !== null): ?> · <?= number_format((float)$h_['rtt_ms'], 1) ?> ms<?php endif; ?>
              </small>
            </span>
            <span><?= lv_status_pill($d['status'] ?? null) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'billing'): ?>
<div class="lv-grid">
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Recent invoices</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/invoices.php?user_id=<?= (int)$client['id'] ?>">All ↗</a>
    </div>
    <?php if (!$invoices): ?>
      <small class="muted">No invoices on file.</small>
    <?php else: ?>
      <table class="data-table compact">
        <thead><tr><th>#</th><th>Issued</th><th>Due</th><th>Status</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
          <?php foreach ($invoices as $i): ?>
            <tr>
              <td><a href="/admin/invoice-edit.php?id=<?= (int)$i['id'] ?>"><?= lv_h($i['number'] ?? '#' . (int)$i['id']) ?></a></td>
              <td><small><?= lv_h($i['issue_date']) ?></small></td>
              <td><small><?= lv_h($i['due_date']   ?? '—') ?></small></td>
              <td><span class="lv-pill" style="background:<?= match($i['status']) {'paid'=>'#4ade80','overdue'=>'#ff5470','partial'=>'#e8a814','draft'=>'#6b7480',default=>'#05DAFD'} ?>;color:#001218;"><?= lv_h($i['status']) ?></span></td>
              <td style="text-align:right;"><?= number_format((float)$i['total'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Recent payments</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/payments.php?user_id=<?= (int)$client['id'] ?>">All ↗</a>
    </div>
    <?php if (!$payments): ?>
      <small class="muted">No payments on file.</small>
    <?php else: ?>
      <table class="data-table compact">
        <thead><tr><th>Paid</th><th>Method</th><th>Reference</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><small><?= lv_h($p['paid_at']) ?></small></td>
              <td><?= lv_h($p['method'] ?? '—') ?></td>
              <td><small class="muted"><?= lv_h($p['reference'] ?? '') ?></small></td>
              <td style="text-align:right;"><?= number_format((float)$p['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'support'): ?>
<div class="lv-grid">
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Tickets</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/tickets.php?user_id=<?= (int)$client['id'] ?>">All ↗</a>
    </div>
    <?php if (!$tickets): ?>
      <small class="muted">No tickets on file.</small>
    <?php else: ?>
      <table class="data-table compact">
        <thead><tr><th>#</th><th>Subject</th><th>Severity</th><th>Status</th><th>Updated</th></tr></thead>
        <tbody>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td>#<?= (int)$t['id'] ?></td>
              <td><a href="/admin/tickets.php?id=<?= (int)$t['id'] ?>"><?= lv_h($t['subject'] ?? '—') ?></a></td>
              <td><?= lv_h($t['severity'] ?? '—') ?></td>
              <td><span class="lv-pill" style="background:<?= ($t['status'] ?? '') === 'open' ? '#e8a814' : '#4ade80' ?>;color:#001218;"><?= lv_h($t['status']) ?></span></td>
              <td><small><?= lv_h($t['updated_at']) ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Notes</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/client-edit.php?id=<?= (int)$client['id'] ?>#notes">Add ↗</a>
    </div>
    <?php if (!$notes): ?>
      <small class="muted">No notes on file.</small>
    <?php else: foreach ($notes as $n): ?>
      <div class="lv-row" style="display:block;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;">
          <strong style="color:<?= !empty($n['pinned']) ? 'var(--accent)' : 'var(--text-dim)' ?>;font-size:12px;">
            <?= !empty($n['pinned']) ? '★ Pinned' : 'Note' ?>
          </strong>
          <small class="muted"><?= lv_h($n['created_at']) ?></small>
        </div>
        <div style="white-space:pre-wrap;color:var(--text-dim);font-size:13px;margin-top:4px;"><?= lv_h($n['body']) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php else: /* profile (default) */ ?>
<div class="lv-grid">
  <!-- Personal -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Personal</h3>
      <a class="btn btn-ghost btn-sm" href="/admin/client-edit.php?id=<?= (int)$client['id'] ?>">Edit ↗</a>
    </div>
    <div class="lv-row"><span><b>Name</b></span>      <span><?= lv_h($client['name'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Surname</b></span>   <span><?= lv_h($client['surname'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Type</b></span>      <span><?= lv_h(ucfirst((string)$client['customer_type'] ?? 'residential')) ?></span></div>
    <div class="lv-row"><span><b>ID number</b></span> <span><?= lv_h($client['id_number'] ?? '') ?: '—' ?></span></div>
    <div class="lv-row"><span><b>VAT number</b></span><span><?= lv_h($client['vat_number'] ?? '') ?: '—' ?></span></div>
    <div class="lv-row"><span><b>Email</b></span>     <span><?= lv_h($client['email'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Phone</b></span>     <span><?= lv_h($client['phone'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Address</b></span>   <span><?= lv_h($client['address'] ?? '') ?: '—' ?></span></div>
    <?php if (!empty($client['lat']) && !empty($client['lng'])): ?>
      <div class="lv-row"><span><b>GPS</b></span>     <span><?= number_format((float)$client['lat'], 6) ?>, <?= number_format((float)$client['lng'], 6) ?></span></div>
    <?php endif; ?>

    <?php if (!empty($client['alt_contact_name']) || !empty($client['alt_contact_phone'])): ?>
      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Alternate contact</h3>
      <div class="lv-row"><span><b>Name</b></span>    <span><?= lv_h($client['alt_contact_name']  ?? '') ?: '—' ?></span></div>
      <div class="lv-row"><span><b>Phone</b></span>   <span><?= lv_h($client['alt_contact_phone'] ?? '') ?: '—' ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Account / billing -->
  <div class="portal-card">
    <div class="lv-grid-hdr">
      <h3 class="lv-label" style="font-size:11px;">Account &amp; billing</h3>
      <span class="lv-tag"><?= lv_h($client['status'] ?? 'active') ?></span>
    </div>
    <div class="lv-row"><span><b>Account no</b></span>   <span><?= lv_h($client['account_no'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Username</b></span>     <span><code><?= lv_h($client['username'] ?? '—') ?></code></span></div>
    <div class="lv-row"><span><b>Status</b></span>
      <span><span class="lv-pill" style="background:<?= $status_colour ?>;color:#001218;"><?= lv_h($client['status'] ?? '—') ?></span></span></div>
    <div class="lv-row"><span><b>Service start</b></span><span><?= lv_h($client['service_start'] ?? '—') ?></span></div>
    <div class="lv-row"><span><b>Billing day</b></span>  <span><?= !empty($client['billing_day']) ? (int)$client['billing_day'] : '—' ?></span></div>
    <div class="lv-row"><span><b>Payment method</b></span><span><?= lv_h(strtoupper((string)$client['payment_method'] ?? 'eft')) ?></span></div>
    <div class="lv-row"><span><b>Package</b></span>      <span><?= lv_h($client['package'] ?? '—') ?></span></div>

    <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Network attachment</h3>
    <div class="lv-row"><span><b>Site</b></span>
      <span><?php if ($site): ?>
        <a href="/admin/site-view.php?id=<?= (int)$site['id'] ?>"><?= lv_h($site['name']) ?></a>
      <?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Sector</b></span>
      <span><?php if ($sector): ?>
        <a href="/admin/sector-view.php?id=<?= (int)$sector['id'] ?>"><?= lv_h($sector['name']) ?></a>
        <?php if ($sector['frequency_mhz'] !== null): ?> <span class="lv-tag"><?= (int)$sector['frequency_mhz'] ?> MHz</span><?php endif; ?>
      <?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Serving AP</b></span>
      <span><?php if ($serving_ap): ?>
        <a href="/admin/device-view.php?id=<?= (int)$serving_ap['id'] ?>"><?= lv_h($serving_ap['name']) ?></a>
        <?= lv_status_pill($serving_ap['status'] ?? null) ?>
      <?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Distance to AP</b></span>
      <span><?= $dist_km !== null
        ? number_format($dist_km, 2) . ' km · ' . lv_fmt_ft($dist_km) : '—' ?></span></div>
    <div class="lv-row"><span><b>Last login</b></span>
      <span><?= lv_h($client['last_login'] ?? 'never') ?></span></div>
    <?php if (!empty($client['notes'])): ?>
      <h3 class="lv-label" style="margin-top:18px;font-size:11px;">Notes</h3>
      <p style="white-space:pre-wrap;color:var(--text-dim);font-size:13px;"><?= lv_h($client['notes']) ?></p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-ghost btn-sm" href="/admin/clients.php">← All clients</a>
    <?php if ($site):       ?><a class="btn btn-ghost btn-sm" href="/admin/site-view.php?id=<?= (int)$site['id'] ?>">Open site</a><?php endif; ?>
    <?php if ($sector):     ?><a class="btn btn-ghost btn-sm" href="/admin/sector-view.php?id=<?= (int)$sector['id'] ?>">Open sector</a><?php endif; ?>
    <?php if ($serving_ap): ?><a class="btn btn-ghost btn-sm" href="/admin/device-view.php?id=<?= (int)$serving_ap['id'] ?>">Open AP</a><?php endif; ?>
    <?php if ($links):      ?><a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=<?= (int)$links[0]['id'] ?>">Open link</a><?php endif; ?>
    <a class="btn btn-primary btn-sm" href="/admin/client-edit.php?id=<?= (int)$client['id'] ?>">Edit client</a>
  </div>
  <small class="muted">created <?= lv_h(lv_fmt_dt($client['created_at'] ?? null)) ?></small>
</div>
