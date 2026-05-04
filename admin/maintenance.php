<?php
/**
 * Maintenance windows — schedule planned outages so the existing
 * outage detector and customer notifications stand down for the
 * duration. outage_create() consults this table on insert; matching
 * outages get suppressed=1 and skip the notify_send() fan-out.
 */
$page_title = 'Maintenance windows';
$active_key = 'maintenance';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';

$self = '/admin/maintenance.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    acl_require('maintenance.write');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            maintenance_window_save([
                'scope'            => $_POST['scope']     ?? 'sector',
                'scope_id'         => $_POST['scope_id']  ?? 0,
                'starts_at'        => $_POST['starts_at'] ?? '',
                'ends_at'          => $_POST['ends_at']   ?? '',
                'reason'           => $_POST['reason']    ?? '',
                'notify_customers' => !empty($_POST['notify_customers']),
                'created_by'       => $user['id'],
            ], $id ?: null);
            audit_log('maintenance.save', ['target_type' => 'maintenance_window', 'target_id' => $id ?: 0]);
            flash('success', 'Window saved.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            maintenance_window_delete($id);
            audit_log('maintenance.delete', ['target_type' => 'maintenance_window', 'target_id' => $id]);
            flash('success', 'Window deleted.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$windows = maintenance_windows_all(100);
$sectors = sectors_all(null);
$sites   = sites_all(false);
$devices = devices_all();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$scope_label = function (string $scope, int $id) use ($sectors, $sites, $devices): string {
    if ($scope === 'sector') foreach ($sectors as $s) if ((int)$s['id'] === $id) return $s['name'];
    if ($scope === 'device') foreach ($devices as $d) if ((int)$d['id'] === $id) return $d['name'];
    if ($scope === 'site' || $scope === 'tower') foreach ($sites as $s) if ((int)$s['id'] === $id) return $s['name'];
    return $scope . '#' . $id;
};
?>
<div class="portal-head">
  <h1>Maintenance windows</h1>
  <p class="portal-sub">Outages that fall inside an active window are flagged <code>suppressed=1</code>; customer notifications stand down for the duration. Use for pole-swaps, scheduled freq moves, planned PoP work.</p>
</div>

<div class="portal-card">
  <h2>Active &amp; scheduled <span class="muted">(<?= count($windows) ?>)</span></h2>
  <?php if (!$windows): ?>
    <small class="muted">No windows. Add one below.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Scope</th><th>Where</th><th>Starts</th><th>Ends</th><th>Notify customers</th><th>Reason</th><th>Created by</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($windows as $w):
          $now = time();
          $st  = strtotime((string)$w['starts_at']);
          $en  = strtotime((string)$w['ends_at']);
          $state = $now < $st ? 'pending' : ($now <= $en ? 'active' : 'past');
          $colour = $state === 'active' ? '#e8a814' : ($state === 'pending' ? '#4477ff' : '#888');
        ?>
          <tr<?= $state === 'past' ? ' style="opacity:.55;"' : '' ?>>
            <td><?= $h($w['scope']) ?></td>
            <td><strong><?= $h($scope_label((string)$w['scope'], (int)$w['scope_id'])) ?></strong>
              <span style="background:<?= $colour ?>;color:#fff;padding:1px 6px;border-radius:6px;font-size:10px;text-transform:uppercase;margin-left:6px;"><?= $state ?></span>
            </td>
            <td><small><?= $h($w['starts_at']) ?></small></td>
            <td><small><?= $h($w['ends_at']) ?></small></td>
            <td><?= $w['notify_customers'] ? '✓' : '<small class="muted">no</small>' ?></td>
            <td><small><?= $h($w['reason']) ?></small></td>
            <td><small><?= $h($w['creator_name'] ?? '—') ?></small></td>
            <td>
              <form method="post" class="inline-form" data-confirm="Delete this maintenance window?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-danger btn-sm">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Schedule a new window</h2>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Scope</label>
      <select name="scope" id="mw-scope" onchange="document.querySelectorAll('[data-mw-target]').forEach(e => e.style.display = e.dataset.mwTarget === this.value ? '' : 'none')">
        <?php foreach (MAINTENANCE_SCOPES as $s): ?>
          <option value="<?= $s ?>" <?= $s === 'sector' ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-mw-target="sector"><label>Sector</label>
      <select name="scope_id">
        <?php foreach ($sectors as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= $h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-mw-target="device" style="display:none;"><label>Device</label>
      <select name="scope_id">
        <?php foreach ($devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= $h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-mw-target="site" style="display:none;"><label>Site</label>
      <select name="scope_id">
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= $h($s['name']) ?> (<?= $h($s['type']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-mw-target="tower" style="display:none;"><label>Tower</label>
      <select name="scope_id">
        <?php foreach (array_filter($sites, fn ($s) => $s['type'] === 'tower') as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= $h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-mw-target="core" style="display:none;"><label>Core</label>
      <input type="number" name="scope_id" value="0" placeholder="0 = entire network">
    </div>
    <div class="field"><label>Starts at</label>
      <input type="datetime-local" name="starts_at" required></div>
    <div class="field"><label>Ends at</label>
      <input type="datetime-local" name="ends_at" required></div>
    <div class="field"><label><input type="checkbox" name="notify_customers" value="1"> Notify customers in advance</label></div>
    <div class="field" style="grid-column:1/-1;"><label>Reason</label>
      <input type="text" name="reason" maxlength="255" placeholder="Pole replacement / freq move / planned reboot"></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Schedule window</button>
    </div>
  </form>
</div>
