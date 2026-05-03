<?php
$page_title = 'Service status';
$active_key = 'incidents';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/incidents.php';

$status_filter = (string)($_GET['status'] ?? '');
if ($status_filter !== '' && !in_array($status_filter, INCIDENT_STATUSES, true)) {
    $status_filter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'delete' && $id) {
        if (incident_delete($id)) flash('success', 'Incident deleted.');
        else                       flash('error',   'Could not delete incident.');
    }
    header('Location: /admin/incidents.php' . ($status_filter ? '?status=' . urlencode($status_filter) : ''));
    exit;
}

$rows   = incidents_all($status_filter ?: null);
$active = incidents_active_all();
?>

<div class="portal-head">
  <h1>Service status</h1>
  <p class="portal-sub">Incidents you post here power the public <a href="/status">/status</a> page and the site-wide banner.</p>
</div>

<?php if ($active): ?>
  <div class="alert alert-error">
    <strong><?= count($active) ?></strong> incident<?= count($active) === 1 ? '' : 's' ?> currently active.
    The site-wide banner and the client dashboard alert are showing.
  </div>
<?php endif; ?>

<div class="portal-card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
    <p class="inline-form" style="margin:0;">
      <span class="muted small">Filter:</span>
      <a href="/admin/incidents.php" class="btn btn-ghost btn-sm" <?= $status_filter === '' ? 'aria-current="page"' : '' ?>>All</a>
      <?php foreach (INCIDENT_STATUSES as $s): ?>
        <a href="/admin/incidents.php?status=<?= htmlspecialchars($s) ?>" class="btn btn-ghost btn-sm" <?= $status_filter === $s ? 'aria-current="page"' : '' ?>>
          <?= htmlspecialchars(INCIDENT_STATUS_LABELS[$s]) ?>
        </a>
      <?php endforeach; ?>
    </p>
    <a href="/admin/incident-edit.php" class="btn btn-primary btn-sm">+ New incident</a>
  </div>
</div>

<div class="portal-card">
  <?php if (empty($rows)): ?>
    <p class="muted">No incidents <?= $status_filter ? 'with this status' : 'on record' ?>.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Title</th><th>Severity</th><th>Status</th><th>Affected</th><th>Started</th><th>Resolved</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i): ?>
          <tr>
            <td><a href="/admin/incident-edit.php?id=<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['title']) ?></a></td>
            <td><span class="status-pill <?= htmlspecialchars(incident_severity_class($i['severity'])) ?>"><?= htmlspecialchars(INCIDENT_SEVERITY_LABELS[$i['severity']] ?? $i['severity']) ?></span></td>
            <td><span class="status-pill status-<?= htmlspecialchars($i['status']) ?>"><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$i['status']] ?? $i['status']) ?></span></td>
            <td class="muted small"><?= htmlspecialchars($i['affected'] ?: '—') ?></td>
            <td class="muted small"><?= htmlspecialchars(substr((string)$i['started_at'], 0, 16)) ?></td>
            <td class="muted small"><?= $i['resolved_at'] ? htmlspecialchars(substr((string)$i['resolved_at'], 0, 16)) : '—' ?></td>
            <td class="row-actions">
              <form method="post" class="inline-form" onsubmit="return confirm('Delete incident &quot;<?= htmlspecialchars($i['title'], ENT_QUOTES) ?>&quot;? Updates will be removed too.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
