<?php
/**
 * Installer dashboard — queue of pending and in-progress install jobs.
 *
 * Default view shows every "open" job (pending + in_progress) ordered
 * by priority then scheduled date — that's the technician's working
 * stack. Filters narrow it to a single tech, status, or date range.
 *
 * Posts back to itself with action=create | assign | cancel for the
 * inline operations. The per-job workflow page is /admin/install-view.php.
 */
$page_title = 'Installs';
$active_key = 'installs';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/installs.php';
require_once __DIR__ . '/../auth/sectors.php';

$self = strtok($_SERVER['REQUEST_URI'], '?');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if ($cid <= 0) {
            flash('error', 'Pick a customer.');
        } else {
            try {
                $id = install_job_save([
                    'customer_id'  => $cid,
                    'assigned_to'  => $_POST['assigned_to']  ?? null,
                    'scheduled_at' => $_POST['scheduled_at'] ?? null,
                    'priority'     => (int)($_POST['priority'] ?? 3),
                    'notes'        => $_POST['notes'] ?? '',
                    'cpe_mac'      => $_POST['cpe_mac']    ?? '',
                    'cpe_serial'   => $_POST['cpe_serial'] ?? '',
                    'cpe_model'    => $_POST['cpe_model']  ?? '',
                ]);
                flash('success', 'Install scheduled.');
                header('Location: /admin/install-view.php?id=' . $id);
                exit;
            } catch (Throwable $e) {
                flash('error', 'Could not schedule install: ' . $e->getMessage());
            }
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? 'cancelled from dashboard'));
        if ($id > 0) install_job_cancel($id, $reason);
        flash('success', 'Install cancelled.');
        header('Location: ' . $self);
        exit;
    }
}

$filters = [
    'status'      => $_GET['status']      ?? '',  // '' → defaults to "open" in helper
    'assigned_to' => (int)($_GET['assigned_to'] ?? 0),
    'search'      => trim((string)($_GET['search'] ?? '')),
];
$jobs = install_jobs_all($filters);

/* Staff list for the "assigned tech" dropdowns. We pull every staff role
   so super_admin can assign whoever — the field is informational, not a
   permission gate. */
$staff = pdo()->query(
    "SELECT id, name, surname, username, role
       FROM users
      WHERE role IN ('super_admin','admin','technician','support')
      ORDER BY name, surname"
)->fetchAll();

/* Customer list for the new-install form. We could paginate but the
   datalist below caps the rendered options at the most recent 500 leads
   to keep the HTML small. */
$lead_customers = pdo()->query(
    "SELECT id, account_no, username, name, surname, address, status
       FROM users
      WHERE role = 'client'
      ORDER BY (status = 'lead') DESC, id DESC
      LIMIT 500"
)->fetchAll();

/* "Leads needing install" panel — every client whose status is 'lead'
   AND who doesn't already have an open install_job. This is the
   admin's working list when they sit down to schedule the day's
   installs. Cap at 50 so the page stays snappy. */
$leads_to_schedule = pdo()->query(
    "SELECT u.id, u.account_no, u.username, u.name, u.surname,
            u.address, u.phone, u.created_at
       FROM users u
       LEFT JOIN install_jobs j
              ON j.customer_id = u.id
             AND j.status IN ('pending','in_progress')
      WHERE u.role = 'client'
        AND u.status = 'lead'
        AND j.id IS NULL
      ORDER BY u.id DESC
      LIMIT 50"
)->fetchAll();

/* If the page was opened with ?prefill_customer_id=X (from the
   client-view "Schedule install" CTA, or from a leads-row "Schedule"
   button) we pre-select that customer in the form and scroll the form
   into view. */
$prefill_customer_id = (int)($_GET['prefill_customer_id'] ?? 0);

function inst_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

function inst_status_pill(string $s): string {
    $colors = [
        'pending'     => '#08e',
        'in_progress' => '#e8a814',
        'completed'   => '#4ade80',
        'cancelled'   => '#6b7480',
    ];
    $c = $colors[$s] ?? '#888';
    return '<span class="lv-pill" style="background:' . $c . ';color:#001218;">'
         . inst_h(str_replace('_', ' ', $s)) . '</span>';
}

function inst_priority_pill(int $p): string {
    $labels = [1 => 'P1 high', 2 => 'P2', 3 => 'P3', 4 => 'P4', 5 => 'P5 low'];
    $col    = $p <= 1 ? '#ff5470' : ($p === 2 ? '#e8a814' : '#6b7480');
    return '<span class="lv-pill" style="background:' . $col . ';color:#001218;">'
         . inst_h($labels[$p] ?? ('P' . $p)) . '</span>';
}

function inst_fmt_dt(?string $dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $t ? date('Y-m-d H:i', $t) : '—';
}
?>

<div class="portal-card" style="margin-bottom:14px;">
  <h2>Filter</h2>
  <form method="get" class="form form-grid">
    <div class="field"><label>Status</label>
      <select name="status">
        <option value=""            <?= $filters['status']==='' ? 'selected':'' ?>>open (pending + in progress)</option>
        <option value="pending"     <?= $filters['status']==='pending'     ? 'selected':'' ?>>pending</option>
        <option value="in_progress" <?= $filters['status']==='in_progress' ? 'selected':'' ?>>in progress</option>
        <option value="completed"   <?= $filters['status']==='completed'   ? 'selected':'' ?>>completed</option>
        <option value="cancelled"   <?= $filters['status']==='cancelled'   ? 'selected':'' ?>>cancelled</option>
      </select>
    </div>
    <div class="field"><label>Assigned tech</label>
      <select name="assigned_to">
        <option value="0">— anyone —</option>
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $filters['assigned_to'] === (int)$s['id'] ? 'selected':'' ?>>
            <?= inst_h(trim(($s['name'] ?? '') . ' ' . ($s['surname'] ?? ''))) ?: inst_h($s['username']) ?>
            (<?= inst_h($s['role']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Search</label>
      <input type="text" name="search" value="<?= inst_h($filters['search']) ?>" placeholder="customer / address / account">
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="<?= $self ?>" class="btn btn-ghost btn-sm">Reset</a>
      <a href="<?= $self ?>?assigned_to=<?= (int)($user['id'] ?? 0) ?>" class="btn btn-ghost btn-sm">My queue</a>
    </div>
  </form>
</div>

<div class="portal-card" style="margin-bottom:14px;">
  <h2>Install queue <span class="muted">(<?= count($jobs) ?>)</span></h2>
  <?php if (!$jobs): ?>
    <div class="empty-state">
      <div class="empty-icon">🪜</div>
      <h3>No installs in this view</h3>
      <p>Schedule a new install below, or change the filters above.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Status</th>
          <th>Pri</th>
          <th>Customer</th>
          <th>Address</th>
          <th>Phone</th>
          <th>Scheduled</th>
          <th>Tech</th>
          <th>Notes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j):
          $cust = trim(($j['customer_name'] ?? '') . ' ' . ($j['customer_surname'] ?? ''))
                 ?: ($j['customer_username'] ?? ('client #' . $j['customer_id']));
        ?>
          <tr>
            <td><?= inst_status_pill((string)$j['status']) ?></td>
            <td><?= inst_priority_pill((int)$j['priority']) ?></td>
            <td>
              <a href="/admin/client-view.php?id=<?= (int)$j['customer_id'] ?>"><strong><?= inst_h($cust) ?></strong></a>
              <?php if (!empty($j['customer_account_no'])): ?>
                <br><small class="muted"><?= inst_h($j['customer_account_no']) ?></small>
              <?php endif; ?>
            </td>
            <td><small><?= inst_h($j['customer_address']) ?: '—' ?></small></td>
            <td>
              <?php if (!empty($j['customer_phone'])): ?>
                <a href="tel:<?= inst_h($j['customer_phone']) ?>"><?= inst_h($j['customer_phone']) ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= inst_h(inst_fmt_dt($j['scheduled_at'])) ?></td>
            <td>
              <?php if (!empty($j['assigned_name']) || !empty($j['assigned_username'])): ?>
                <?= inst_h($j['assigned_name'] ?: $j['assigned_username']) ?>
              <?php else: ?>
                <span class="muted">unassigned</span>
              <?php endif; ?>
            </td>
            <td><small class="muted"><?= inst_h(mb_substr((string)$j['notes'], 0, 60)) ?></small></td>
            <td style="white-space:nowrap;">
              <a class="btn btn-ghost btn-sm" href="/admin/install-view.php?id=<?= (int)$j['id'] ?>">Open ↗</a>
              <?php if (in_array($j['status'], ['pending','in_progress'], true)): ?>
                <a class="btn btn-ghost btn-sm" href="/admin/align.php?customer_id=<?= (int)$j['customer_id'] ?>" title="Live signal meter">Align</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($leads_to_schedule): ?>
<div class="portal-card" style="margin-bottom:14px;">
  <h2>Leads needing install <span class="muted">(<?= count($leads_to_schedule) ?>)</span></h2>
  <p class="muted" style="margin-top:0;">Customers signed up but with no scheduled install yet. Click <strong>Schedule</strong> to load one for the techs.</p>
  <div class="table-scroll">
    <table class="data-table compact">
      <thead><tr><th>Customer</th><th>Account</th><th>Address</th><th>Phone</th><th>Signed up</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($leads_to_schedule as $c):
          $label = trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? '')) ?: ($c['username'] ?? ('client #' . $c['id']));
        ?>
          <tr>
            <td><a href="/admin/client-view.php?id=<?= (int)$c['id'] ?>"><strong><?= inst_h($label) ?></strong></a></td>
            <td><small><?= inst_h($c['account_no']) ?: '—' ?></small></td>
            <td><small><?= inst_h(mb_substr((string)$c['address'], 0, 60)) ?: '—' ?></small></td>
            <td><small><?php if (!empty($c['phone'])): ?><a href="tel:<?= inst_h($c['phone']) ?>"><?= inst_h($c['phone']) ?></a><?php else: ?>—<?php endif; ?></small></td>
            <td><small><?= inst_h(inst_fmt_dt($c['created_at'])) ?></small></td>
            <td><a class="btn btn-primary btn-sm" href="?prefill_customer_id=<?= (int)$c['id'] ?>#schedule-install">Schedule ↓</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="portal-card" id="schedule-install">
  <h2>Schedule a new install</h2>
  <?php if ($prefill_customer_id): ?>
    <p style="background:#0a1f33;border-left:3px solid #05DAFD;padding:8px 10px;border-radius:4px;">
      Pre-selecting customer #<?= (int)$prefill_customer_id ?>. Add the tech and date below, then save.
    </p>
  <?php endif; ?>
  <form method="post" class="form form-grid">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create">
    <div class="field" style="grid-column:1/-1;">
      <label>Customer</label>
      <select name="customer_id" required>
        <option value="">— pick a customer —</option>
        <?php foreach ($lead_customers as $c):
          $label = trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? '')) ?: $c['username'];
          $tag   = $c['status'] === 'lead' ? ' · LEAD' : '';
          $sel   = $prefill_customer_id === (int)$c['id'] ? ' selected' : '';
        ?>
          <option value="<?= (int)$c['id'] ?>"<?= $sel ?>>
            <?= inst_h($label) ?>
            <?php if (!empty($c['account_no'])): ?> · <?= inst_h($c['account_no']) ?><?php endif; ?>
            <?php if (!empty($c['address'])): ?> · <?= inst_h(mb_substr($c['address'], 0, 60)) ?><?php endif; ?>
            <?= $tag ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="muted">Showing the most recent 500 customers, leads first.</small>
    </div>
    <div class="field"><label>Scheduled at</label>
      <input type="datetime-local" name="scheduled_at">
    </div>
    <div class="field"><label>Assigned tech</label>
      <select name="assigned_to">
        <option value="">— unassigned —</option>
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['id'] ?>">
            <?= inst_h(trim(($s['name'] ?? '') . ' ' . ($s['surname'] ?? ''))) ?: inst_h($s['username']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Priority</label>
      <select name="priority">
        <option value="1">P1 — high</option>
        <option value="2">P2</option>
        <option value="3" selected>P3 — normal</option>
        <option value="4">P4</option>
        <option value="5">P5 — low</option>
      </select>
    </div>
    <div class="field"><label>CPE model</label>
      <input type="text" name="cpe_model" placeholder="e.g. NanoStation 5AC Loco">
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2" placeholder="access details, gate code, what's expected on site"></textarea>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Schedule install</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
