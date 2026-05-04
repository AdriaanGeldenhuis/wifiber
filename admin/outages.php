<?php
/**
 * Outages — auto-detected faults, scoped to sectors for now.
 *
 * Lists active outages first, then resolved history. Manual close
 * button on every active row in case the detector hasn't caught up
 * (or this is a known maintenance window). Detection itself is run
 * by bin/detect-outages.php on cron.
 */
$page_title = 'Outages';
$active_key = 'outages';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/outages.php';

$self = '/admin/outages.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'resolve') {
        acl_require('outages.close');
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($id) {
            outage_resolve($id, $note !== '' ? $note : 'manually resolved');
            audit_log('outage.resolve', ['target_type' => 'outage', 'target_id' => $id]);
            flash('success', 'Outage resolved.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'detect_now') {
        // Triggering the detector touches the network state and outage table;
        // keep this restricted to actual write-capable roles.
        require_admin_write();
        $r = outage_detect();
        audit_log('outage.detect', ['meta' => $r]);
        flash('success', sprintf('Detector ran: opened=%d closed=%d updated=%d', $r['opened'], $r['closed'], $r['updated']));
        header('Location: ' . $self);
        exit;
    }
}

$active   = outages_all(['status' => 'active']);
$resolved = outages_all(['status' => 'resolved'], 50);

$age_label = function (?string $when, ?string $until = null): string {
    if (!$when) return '—';
    $start = strtotime($when);
    $end   = $until ? strtotime($until) : time();
    $age   = max(0, $end - $start);
    if ($age < 60)    return $age . 's';
    if ($age < 3600)  return floor($age / 60)   . 'm ' . ($age % 60) . 's';
    if ($age < 86400) return floor($age / 3600) . 'h ' . floor(($age % 3600) / 60) . 'm';
    return floor($age / 86400) . 'd ' . floor(($age % 86400) / 3600) . 'h';
};
?>

<div class="portal-head">
  <h1>Outages</h1>
  <p class="portal-sub">
    Auto-detected by <code>bin/detect-outages.php</code> &mdash; a sector outage opens when its AP device flips to offline, and resolves when the AP comes back. Resolved history is kept indefinitely for reporting.
  </p>
</div>

<form method="post" class="inline-form" style="margin-bottom: 16px;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="detect_now">
  <button type="submit" class="btn btn-ghost btn-sm">Run detector now</button>
  <span class="muted small">Triggers the same sweep the cron does.</span>
</form>

<div class="portal-card" style="<?= $active ? 'border-left: 3px solid #d44;' : '' ?>">
  <h2>Active <span class="muted">(<?= count($active) ?>)</span></h2>
  <?php if (!$active): ?>
    <p class="muted" style="margin:0;">No active outages. Network is healthy.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Scope</th><th>Where</th><th>Cause</th>
          <th style="text-align:right;">Affected</th>
          <th>Started</th><th>Duration</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($active as $o): ?>
          <tr>
            <td><span style="background:#d44;color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;"><?= htmlspecialchars($o['scope']) ?></span></td>
            <td><strong><?= htmlspecialchars($o['scope_label']) ?></strong></td>
            <td><?= htmlspecialchars($o['cause'] ?? '—') ?></td>
            <td style="text-align:right;">
              <strong><?= $o['affected_count'] ?></strong>
              <?php if ($o['affected_count'] > 0): ?>
                <br><small class="muted">customer<?= $o['affected_count'] === 1 ? '' : 's' ?></small>
              <?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($o['started_at']) ?></small></td>
            <td><small style="color:#fa0;"><?= $age_label($o['started_at']) ?></small></td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm">Resolve</summary>
                <form method="post" class="form" style="margin-top:8px;display:flex;gap:6px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="resolve">
                  <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                  <input type="text" name="note" placeholder="Note (optional)" style="flex:1;">
                  <button type="submit" class="btn btn-primary btn-sm">Close</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Recently resolved <span class="muted">(<?= count($resolved) ?>)</span></h2>
  <?php if (!$resolved): ?>
    <p class="muted" style="margin:0;">No resolved outages on record yet.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Scope</th><th>Where</th><th>Cause</th>
          <th style="text-align:right;">Affected</th>
          <th>Started</th><th>Resolved</th><th>Duration</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resolved as $o): ?>
          <tr>
            <td><span style="background:#888;color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;"><?= htmlspecialchars($o['scope']) ?></span></td>
            <td><?= htmlspecialchars($o['scope_label']) ?></td>
            <td><small><?= htmlspecialchars($o['cause'] ?? '—') ?></small></td>
            <td style="text-align:right;"><?= $o['affected_count'] ?></td>
            <td><small class="muted"><?= htmlspecialchars($o['started_at']) ?></small></td>
            <td><small class="muted"><?= htmlspecialchars((string)($o['resolved_at'] ?? '')) ?></small></td>
            <td><small><?= $age_label($o['started_at'], $o['resolved_at']) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
