<?php
/**
 * Per-install workflow page — the technician's checklist for a single
 * job. Mobile-first card layout, but reuses the admin nav so they can
 * jump back to the queue or open the customer record.
 *
 * Workflow steps the page records (each is just a button + audit log,
 * never touches users.status):
 *   1. Equipment received   →  saves cpe_mac / cpe_serial / cpe_model
 *   2. Started              →  install_job_start (status → in_progress)
 *   3. Aligned              →  link to /admin/align.php (live signal meter)
 *   4. Sign-off             →  install_job_complete (status → completed,
 *                              records signal/SNR/MAC for the audit row)
 *
 * Cancel / re-edit available at any time.
 */
$page_title = 'Install';
$active_key = 'installs';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/installs.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';

$id  = (int)($_GET['id'] ?? 0);
$job = $id ? install_job_find($id) : null;
if (!$job) {
    flash('error', 'Install job not found.');
    header('Location: /admin/installs.php');
    exit;
}

$self = $_SERVER['REQUEST_URI'];
$back = '/admin/install-view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_equipment') {
        install_job_save([
            'customer_id'  => (int)$job['customer_id'],
            'assigned_to'  => $job['assigned_to'],
            'scheduled_at' => $job['scheduled_at'],
            'priority'     => (int)$job['priority'],
            'notes'        => (string)$job['notes'],
            'cpe_mac'      => $_POST['cpe_mac']    ?? '',
            'cpe_serial'   => $_POST['cpe_serial'] ?? '',
            'cpe_model'    => $_POST['cpe_model']  ?? '',
        ], $id);
        flash('success', 'Equipment saved.');
    }
    elseif ($action === 'save_meta') {
        install_job_save([
            'customer_id'  => (int)$job['customer_id'],
            'assigned_to'  => $_POST['assigned_to']  ?? null,
            'scheduled_at' => $_POST['scheduled_at'] ?? null,
            'priority'     => (int)($_POST['priority'] ?? 3),
            'notes'        => $_POST['notes'] ?? '',
            'cpe_mac'      => (string)$job['cpe_mac'],
            'cpe_serial'   => (string)$job['cpe_serial'],
            'cpe_model'    => (string)$job['cpe_model'],
        ], $id);
        flash('success', 'Job updated.');
    }
    elseif ($action === 'start') {
        install_job_start($id);
        flash('success', 'Install marked in-progress.');
    }
    elseif ($action === 'complete') {
        install_job_complete($id, [
            'signal_dbm' => $_POST['signal_dbm'] ?? null,
            'snr_db'     => $_POST['snr_db']     ?? null,
            'cpe_mac'    => $_POST['cpe_mac']    ?? null,
            'cpe_serial' => $_POST['cpe_serial'] ?? null,
        ]);
        flash('success', 'Install signed off — audit log entry written.');
    }
    elseif ($action === 'cancel') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        install_job_cancel($id, $reason !== '' ? $reason : 'cancelled from workflow page');
        flash('success', 'Install cancelled.');
    }

    header('Location: ' . $back);
    exit;
}

/* Re-fetch after any mutation by reloading the canonical row. */
$job = install_job_find($id) ?: $job;

$customer_id = (int)$job['customer_id'];
$customer    = find_user_by_id($customer_id);
$cust_label  = trim(($customer['name'] ?? '') . ' ' . ($customer['surname'] ?? ''))
             ?: ($customer['username'] ?? ('client #' . $customer_id));

$sector = !empty($job['customer_sector_id']) ? sector_find((int)$job['customer_sector_id']) : null;
$ap_site = null;
if ($sector && !empty($sector['ap_device_id'])) {
    require_once __DIR__ . '/../auth/devices.php';
    $ap_dev  = device_find((int)$sector['ap_device_id']);
    if ($ap_dev && !empty($ap_dev['site_id'])) $ap_site = site_find((int)$ap_dev['site_id']);
}

$dist_km = null;
if ($ap_site && $job['customer_lat'] !== null && $job['customer_lng'] !== null) {
    $dist_km = haversine_km(
        (float)$ap_site['lat'], (float)$ap_site['lng'],
        (float)$job['customer_lat'], (float)$job['customer_lng']
    );
}

$staff = pdo()->query(
    "SELECT id, name, surname, username, role
       FROM users
      WHERE role IN ('super_admin','admin','technician','support')
      ORDER BY name, surname"
)->fetchAll();

function iv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function iv_dt($dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $t ? date('Y-m-d H:i', $t) : '—';
}

$is_open = in_array($job['status'], ['pending', 'in_progress'], true);
$gmaps   = ($job['customer_lat'] !== null && $job['customer_lng'] !== null)
    ? sprintf('https://www.google.com/maps?q=%F,%F', $job['customer_lat'], $job['customer_lng'])
    : null;
?>

<div class="portal-card" style="margin-bottom:14px;">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <h2 style="margin:0;flex:1;">
      Install · <?= iv_h($cust_label) ?>
      <small class="muted" style="font-size:13px;">(job #<?= (int)$job['id'] ?>)</small>
    </h2>
    <?php
      $statusCol = match ($job['status']) {
          'pending'     => '#08e',
          'in_progress' => '#e8a814',
          'completed'   => '#4ade80',
          'cancelled'   => '#6b7480',
          default       => '#888',
      };
    ?>
    <span class="lv-pill" style="background:<?= $statusCol ?>;color:#001218;">
      <?= iv_h(str_replace('_', ' ', $job['status'])) ?>
    </span>
    <a class="btn btn-ghost btn-sm" href="/admin/installs.php">← Queue</a>
    <a class="btn btn-ghost btn-sm" href="/admin/client-view.php?id=<?= $customer_id ?>">Customer ↗</a>
  </div>
</div>

<div class="lv-grid">
  <!-- Customer card -->
  <div class="portal-card">
    <h3 class="lv-label" style="font-size:11px;">Customer</h3>
    <div class="lv-row"><span><b>Name</b></span><span><?= iv_h($cust_label) ?></span></div>
    <div class="lv-row"><span><b>Account</b></span><span><?= iv_h($job['customer_account_no']) ?: '—' ?></span></div>
    <div class="lv-row"><span><b>Phone</b></span>
      <span><?php if (!empty($job['customer_phone'])): ?>
        <a href="tel:<?= iv_h($job['customer_phone']) ?>"><?= iv_h($job['customer_phone']) ?></a>
      <?php else: ?>—<?php endif; ?></span></div>
    <div class="lv-row"><span><b>Address</b></span><span><?= iv_h($job['customer_address']) ?: '—' ?></span></div>
    <div class="lv-row"><span><b>GPS</b></span>
      <span><?php if ($gmaps): ?>
        <a href="<?= iv_h($gmaps) ?>" target="_blank" rel="noopener">open in Maps ↗</a>
      <?php else: ?>—<?php endif; ?></span></div>
    <?php if ($sector): ?>
      <div class="lv-row"><span><b>Sector</b></span>
        <span><a href="/admin/sector-view.php?id=<?= (int)$sector['id'] ?>"><?= iv_h($sector['name']) ?></a></span></div>
    <?php endif; ?>
    <?php if ($dist_km !== null): ?>
      <div class="lv-row"><span><b>Distance to AP</b></span>
        <span><?= number_format((float)$dist_km, 2) ?> km</span></div>
    <?php endif; ?>
    <?php if (!empty($job['customer_equipment_mac'])): ?>
      <div class="lv-row"><span><b>Saved CPE MAC</b></span>
        <span><code><?= iv_h($job['customer_equipment_mac']) ?></code></span></div>
    <?php endif; ?>
  </div>

  <!-- Job meta -->
  <div class="portal-card">
    <h3 class="lv-label" style="font-size:11px;">Job</h3>
    <form method="post" class="form" style="display:flex;flex-direction:column;gap:8px;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="save_meta">
      <label>Scheduled
        <input type="datetime-local" name="scheduled_at"
               value="<?= $job['scheduled_at'] ? iv_h(date('Y-m-d\TH:i', strtotime($job['scheduled_at']))) : '' ?>"
               <?= $is_open ? '' : 'disabled' ?>>
      </label>
      <label>Assigned tech
        <select name="assigned_to" <?= $is_open ? '' : 'disabled' ?>>
          <option value="">— unassigned —</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$job['assigned_to'] === (int)$s['id'] ? 'selected':'' ?>>
              <?= iv_h(trim(($s['name']??'') . ' ' . ($s['surname']??''))) ?: iv_h($s['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Priority
        <select name="priority" <?= $is_open ? '' : 'disabled' ?>>
          <?php foreach ([1=>'P1 high', 2=>'P2', 3=>'P3', 4=>'P4', 5=>'P5 low'] as $v=>$lab): ?>
            <option value="<?= $v ?>" <?= (int)$job['priority']===$v ? 'selected':'' ?>><?= iv_h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Notes
        <textarea name="notes" rows="3" <?= $is_open ? '' : 'disabled' ?>><?= iv_h($job['notes']) ?></textarea>
      </label>
      <?php if ($is_open): ?>
        <button type="submit" class="btn btn-primary btn-sm">Save job</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="lv-grid" style="margin-top:14px;">
  <!-- Equipment card -->
  <div class="portal-card">
    <h3 class="lv-label" style="font-size:11px;">Equipment</h3>
    <form method="post" class="form" style="display:flex;flex-direction:column;gap:8px;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="save_equipment">
      <label>CPE model
        <input type="text" name="cpe_model" value="<?= iv_h($job['cpe_model']) ?>" placeholder="e.g. NanoStation 5AC Loco" <?= $is_open ? '' : 'disabled' ?>>
      </label>
      <label>CPE MAC
        <input type="text" name="cpe_mac" value="<?= iv_h($job['cpe_mac']) ?>" placeholder="AA:BB:CC:11:22:33" <?= $is_open ? '' : 'disabled' ?>>
      </label>
      <label>CPE serial
        <input type="text" name="cpe_serial" value="<?= iv_h($job['cpe_serial']) ?>" <?= $is_open ? '' : 'disabled' ?>>
      </label>
      <?php if ($is_open): ?>
        <button type="submit" class="btn btn-primary btn-sm">Save equipment</button>
      <?php endif; ?>
    </form>
  </div>

  <!-- Workflow / sign-off -->
  <div class="portal-card">
    <h3 class="lv-label" style="font-size:11px;">Workflow</h3>

    <div class="lv-row"><span><b>Created</b></span>   <span><?= iv_h(iv_dt($job['created_at'])) ?></span></div>
    <div class="lv-row"><span><b>Started</b></span>   <span><?= iv_h(iv_dt($job['started_at'])) ?></span></div>
    <div class="lv-row"><span><b>Completed</b></span> <span><?= iv_h(iv_dt($job['completed_at'])) ?></span></div>
    <?php if ($job['cancelled_at']): ?>
      <div class="lv-row"><span><b>Cancelled</b></span> <span><?= iv_h(iv_dt($job['cancelled_at'])) ?> · <?= iv_h($job['cancelled_reason']) ?></span></div>
    <?php endif; ?>
    <?php if ($job['signal_dbm'] !== null): ?>
      <div class="lv-row"><span><b>As-installed signal</b></span>
        <span><?= (int)$job['signal_dbm'] ?> dBm
          <?php if ($job['snr_db'] !== null): ?>· SNR <?= (int)$job['snr_db'] ?> dB<?php endif; ?>
        </span></div>
    <?php endif; ?>

    <?php if ($is_open): ?>
      <hr style="border:0;border-top:1px solid #1c2638;margin:12px 0;">
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php if ($job['status'] === 'pending'): ?>
          <form method="post" style="display:inline;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="start">
            <button type="submit" class="btn btn-primary btn-sm">Start install</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="/admin/align.php?customer_id=<?= $customer_id ?>" title="Open the live signal meter on this device">Align CPE ↗</a>
      </div>

      <hr style="border:0;border-top:1px solid #1c2638;margin:12px 0;">
      <details>
        <summary><strong>Sign off</strong> — record as-installed signal and complete the job</summary>
        <form method="post" class="form" style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="complete">
          <div class="row" style="display:flex;gap:8px;">
            <label style="flex:1;">Signal (dBm)
              <input type="number" name="signal_dbm" placeholder="-60" min="-110" max="0">
            </label>
            <label style="flex:1;">SNR (dB)
              <input type="number" name="snr_db" placeholder="28" min="0" max="80">
            </label>
          </div>
          <label>CPE MAC (confirm)
            <input type="text" name="cpe_mac" value="<?= iv_h($job['cpe_mac']) ?>" placeholder="AA:BB:CC:11:22:33">
          </label>
          <label>CPE serial (confirm)
            <input type="text" name="cpe_serial" value="<?= iv_h($job['cpe_serial']) ?>">
          </label>
          <button type="submit" class="btn btn-primary btn-sm">Mark installed</button>
          <small class="muted">Writes an <code>install_job.complete</code> audit log entry. Does not flip the user's billing status — handle that in the customer record.</small>
        </form>
      </details>

      <hr style="border:0;border-top:1px solid #1c2638;margin:12px 0;">
      <details>
        <summary>Cancel this install</summary>
        <form method="post" class="form" style="display:flex;flex-direction:column;gap:8px;margin-top:8px;"
              onsubmit="return confirm('Cancel this install job?');">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="cancel">
          <label>Reason
            <input type="text" name="reason" placeholder="customer rescheduled / no signal / equipment short">
          </label>
          <button type="submit" class="btn btn-danger btn-sm">Cancel install</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
