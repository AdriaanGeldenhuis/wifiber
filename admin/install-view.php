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
// While an install is open, refresh the page periodically so an admin
// watching the workflow sees live alignment readings update.
$auto_refresh_seconds = 8;
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/installs.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
if (is_file(__DIR__ . '/../auth/radius.php')) {
    require_once __DIR__ . '/../auth/radius.php';
}

$id  = (int)($_GET['id'] ?? 0);
$job = $id ? install_job_find($id) : null;
if (!$job) {
    flash('error', 'Install job not found.');
    header('Location: /admin/installs.php');
    exit;
}

$self = $_SERVER['REQUEST_URI'];
$back = '/admin/install-view.php?id=' . $id;

/* Serve the install photo through PHP so the file lives outside the
   web root. Path is stored relative to DATA_DIR; we sanitise it before
   touching the filesystem. */
if (($_GET['photo'] ?? '') === '1' && !empty($job['photo_path'])) {
    $rel = (string)$job['photo_path'];
    if (preg_match('#^install-photos/[A-Za-z0-9._-]+$#', $rel)) {
        $abs = DATA_DIR . '/' . $rel;
        if (is_file($abs)) {
            $ext = strtolower((string)pathinfo($abs, PATHINFO_EXTENSION));
            $mime = INSTALL_PHOTO_TYPES[$ext] ?? 'application/octet-stream';
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($abs));
            header('Cache-Control: private, max-age=300');
            readfile($abs);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

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
        try {
            $photo_path = install_photo_save($_FILES['photo'] ?? null);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: ' . $back);
            exit;
        }
        install_job_complete($id, [
            'signal_dbm' => $_POST['signal_dbm'] ?? null,
            'snr_db'     => $_POST['snr_db']     ?? null,
            'cpe_mac'    => $_POST['cpe_mac']    ?? null,
            'cpe_serial' => $_POST['cpe_serial'] ?? null,
            'photo_path' => $photo_path !== '' ? $photo_path : null,
        ]);
        $grade = install_signal_grade(
            isset($_POST['signal_dbm']) && $_POST['signal_dbm'] !== '' ? (int)$_POST['signal_dbm'] : null,
            isset($_POST['snr_db'])     && $_POST['snr_db']     !== '' ? (int)$_POST['snr_db']     : null,
        );
        $note = $grade === 'bad'  ? ' (signal/SNR is below the warn threshold — flagged in audit log)'
              : ($grade === 'warn' ? ' (signal/SNR is marginal — flagged in audit log)' : '');
        flash('success', 'Install signed off — audit log entry written.' . $note);
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

    <?php
      // "Live alignment" badge — the alignment endpoint stamps
      // last_alignment_at every time it polls, so a recent timestamp
      // means the tech is on the roof aiming right now. Refresh the
      // page to see updated signal/SNR (auto-refresh below kicks in
      // every 8s while the job is in progress).
      $align_age = $job['last_alignment_at'] ? max(0, time() - strtotime($job['last_alignment_at'])) : null;
      $align_live = ($align_age !== null && $align_age <= 30);
    ?>
    <?php if ($align_live): ?>
      <div class="lv-row" style="background:#0a1f12;border-left:3px solid #4ade80;padding-left:8px;">
        <span><b>Live alignment</b></span>
        <span style="color:#4ade80;font-weight:600;">
          <?= $job['signal_dbm'] !== null ? (int)$job['signal_dbm'] . ' dBm' : '—' ?>
          <?= $job['snr_db']     !== null ? ' · SNR ' . (int)$job['snr_db'] . ' dB' : '' ?>
          <small class="muted" style="margin-left:6px;"><?= (int)$align_age ?>s ago</small>
        </span>
      </div>
    <?php elseif ($job['last_alignment_at']): ?>
      <div class="lv-row"><span><b>Last alignment</b></span>
        <span><?= iv_h(iv_dt($job['last_alignment_at'])) ?>
          <?= $job['signal_dbm'] !== null ? '· ' . (int)$job['signal_dbm'] . ' dBm' : '' ?>
          <?= $job['snr_db']     !== null ? '· SNR ' . (int)$job['snr_db'] . ' dB' : '' ?>
        </span></div>
    <?php endif; ?>

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
    <?php if (!empty($job['photo_path'])): ?>
      <div class="lv-row"><span><b>Site photo</b></span>
        <span><a href="?id=<?= (int)$job['id'] ?>&amp;photo=1" target="_blank" rel="noopener">view ↗</a></span></div>
      <a href="?id=<?= (int)$job['id'] ?>&amp;photo=1" target="_blank" rel="noopener" style="display:block;margin-top:8px;">
        <img src="?id=<?= (int)$job['id'] ?>&amp;photo=1" alt="Install photo" style="max-width:100%;max-height:240px;border-radius:8px;border:1px solid #1c2638;">
      </a>
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
      <?php
        $live_grade = install_signal_grade(
            $job['signal_dbm'] !== null ? (int)$job['signal_dbm'] : null,
            $job['snr_db']     !== null ? (int)$job['snr_db']     : null,
        );
        $grade_pill = match ($live_grade) {
            'ok'   => '<span class="lv-pill" style="background:#4ade80;color:#001218;">acceptable</span>',
            'warn' => '<span class="lv-pill" style="background:#e8a814;color:#001218;">marginal</span>',
            'bad'  => '<span class="lv-pill" style="background:#ff5470;color:#001218;">below threshold</span>',
            default => '',
        };
        $radius = function_exists('radius_user_provisioned')
            ? radius_user_provisioned($customer_id)
            : ['ready' => true, 'reason' => 'RADIUS module not loaded — skipping check'];
        $radius_pill = $radius['ready']
            ? '<span class="lv-pill" style="background:#4ade80;color:#001218;">RADIUS ready</span>'
            : '<span class="lv-pill" style="background:#ff5470;color:#001218;" title="' . iv_h($radius['reason']) . '">RADIUS not ready</span>';
      ?>
      <div class="lv-row"><span><b>RADIUS</b></span>
        <span><?= $radius_pill ?>
          <?php if (!$radius['ready']): ?>
            <small class="muted" style="margin-left:6px;"><?= iv_h($radius['reason']) ?></small>
          <?php endif; ?>
        </span></div>
        $thresholds_text = sprintf(
            'Targets: signal ≥ %d dBm, SNR ≥ %d dB. Marginal at signal %d / SNR %d.',
            INSTALL_SIGNAL_DBM_OK, INSTALL_SNR_DB_OK,
            INSTALL_SIGNAL_DBM_WARN, INSTALL_SNR_DB_WARN,
        );
      ?>
      <details <?= $live_grade === 'bad' ? 'open' : '' ?>>
        <summary><strong>Sign off</strong> — record as-installed signal and complete the job <?= $grade_pill ?> <?= $radius_pill ?></summary>
        <form method="post" class="form" enctype="multipart/form-data"
              style="display:flex;flex-direction:column;gap:8px;margin-top:8px;"
              onsubmit="return iv_confirm_signoff(this);">
          <?php if (!$radius['ready']): ?>
            <p style="background:#220a14;border-left:3px solid #ff5470;padding:8px 10px;border-radius:4px;font-size:13px;">
              <strong>Heads-up:</strong> this customer's RADIUS attributes are not provisioned (<?= iv_h($radius['reason']) ?>). Sign-off will still record, but the customer won't be able to authenticate until <code>bin/radius-sync.php</code> runs or you visit <a href="/admin/client-edit.php?id=<?= $customer_id ?>">client-edit</a>.
            </p>
          <?php endif; ?>
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="complete">
          <small class="muted"><?= iv_h($thresholds_text) ?></small>
          <div class="row" style="display:flex;gap:8px;">
            <label style="flex:1;">Signal (dBm)
              <input type="number" name="signal_dbm" placeholder="<?= $job['signal_dbm'] !== null ? (int)$job['signal_dbm'] : -60 ?>" min="-110" max="0"
                     data-warn="<?= INSTALL_SIGNAL_DBM_OK ?>" data-bad="<?= INSTALL_SIGNAL_DBM_WARN ?>">
            </label>
            <label style="flex:1;">SNR (dB)
              <input type="number" name="snr_db" placeholder="<?= $job['snr_db'] !== null ? (int)$job['snr_db'] : 28 ?>" min="0" max="80"
                     data-warn="<?= INSTALL_SNR_DB_OK ?>" data-bad="<?= INSTALL_SNR_DB_WARN ?>">
            </label>
          </div>
          <small class="muted">Leave blank to lock in the live alignment reading <?php if ($job['signal_dbm'] !== null): ?>(<?= (int)$job['signal_dbm'] ?> dBm<?= $job['snr_db'] !== null ? ' / ' . (int)$job['snr_db'] . ' dB' : '' ?>)<?php endif; ?>.</small>
          <label>CPE MAC (confirm)
            <input type="text" name="cpe_mac" value="<?= iv_h($job['cpe_mac']) ?>" placeholder="AA:BB:CC:11:22:33">
          </label>
          <label>CPE serial (confirm)
            <input type="text" name="cpe_serial" value="<?= iv_h($job['cpe_serial']) ?>">
          </label>
          <label>Site photo (mounted dish, alignment, customer sign-off)
            <input type="file" name="photo" accept="image/*" capture="environment">
          </label>
          <button type="submit" class="btn btn-primary btn-sm">Mark installed</button>
          <small class="muted">Writes an <code>install_job.complete</code> audit log entry with the signal grade. Does not flip the user's billing status — handle that in the customer record.</small>
        </form>
      </details>
      <script>
        // Confirm before submitting a sub-threshold sign-off so admins
        // know they're about to record a marginal install.
        function iv_confirm_signoff(form) {
          function check(name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (!el || el.value === '') return 'unknown';
            var v = parseFloat(el.value);
            if (isNaN(v)) return 'unknown';
            var bad  = parseFloat(el.dataset.bad);
            var warn = parseFloat(el.dataset.warn);
            // Signal: bad/warn are negative, "below" means smaller. SNR:
            // positive, "below" means smaller. Same comparison either way.
            if (v < bad)  return 'bad';
            if (v < warn) return 'warn';
            return 'ok';
          }
          var sig = check('signal_dbm');
          var snr = check('snr_db');
          if (sig === 'bad' || snr === 'bad') {
            return confirm('Signal/SNR is below the install threshold. Sign off anyway?');
          }
          return true;
        }
      </script>

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

<?php
/* Reschedule + state-change history. Pulled from audit_log so we don't
   need a separate table — every install_job_save / start / complete /
   cancel / alignment-sample helper already writes one row. */
try {
    $hstmt = pdo()->prepare(
        "SELECT a.created_at, a.action, a.username, a.meta
           FROM audit_log a
          WHERE a.target_type = 'install_job' AND a.target_id = ?
          ORDER BY a.id DESC
          LIMIT 50"
    );
    $hstmt->execute([$id]);
    $history = $hstmt->fetchAll();
} catch (Throwable $e) {
    $history = [];
}
?>
<?php if ($history): ?>
<div class="portal-card" style="margin-top:14px;">
  <h3 class="lv-label" style="font-size:11px;">Timeline</h3>
  <table class="data-table compact">
    <thead><tr><th>When</th><th>Action</th><th>By</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($history as $h):
        $action = (string)$h['action'];
        $col = match (true) {
            str_ends_with($action, '.complete') => '#4ade80',
            str_ends_with($action, '.cancel')   => '#ff5470',
            str_ends_with($action, '.start')    => '#e8a814',
            str_ends_with($action, '.create')   => '#08e',
            default                             => '#6b7480',
        };
        $meta = $h['meta'] ? json_decode($h['meta'], true) : null;
        $meta_text = is_array($meta)
            ? implode(' · ', array_map(fn($k, $v) => $k . '=' . (is_scalar($v) ? $v : json_encode($v)),
                                       array_keys($meta), $meta))
            : '';
      ?>
        <tr>
          <td><small><?= iv_h(iv_dt($h['created_at'])) ?></small></td>
          <td><span class="lv-pill" style="background:<?= $col ?>;color:#001218;"><?= iv_h(str_replace('install_job.', '', $action)) ?></span></td>
          <td><small><?= iv_h($h['username']) ?: '—' ?></small></td>
          <td><small class="muted"><?= iv_h(mb_substr($meta_text, 0, 120)) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
