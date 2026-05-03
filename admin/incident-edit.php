<?php
$page_title = 'Incident editor';
$active_key = 'incidents';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/incidents.php';

$id       = (int)($_GET['id'] ?? 0);
$incident = $id > 0 ? incident_find($id) : null;
$is_new   = !$incident;

$errors = [];
$form   = $is_new ? [
    'title'      => '',
    'body'       => '',
    'affected'   => '',
    'severity'   => 'minor',
    'status'     => 'investigating',
    'started_at' => date('Y-m-d\TH:i'),
] : [
    'title'      => (string)$incident['title'],
    'body'       => (string)$incident['body'],
    'affected'   => (string)$incident['affected'],
    'severity'   => (string)$incident['severity'],
    'status'     => (string)$incident['status'],
    'started_at' => date('Y-m-d\TH:i', strtotime((string)$incident['started_at'])),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'save';

    $form = [
        'title'      => trim((string)($_POST['title']      ?? '')),
        'body'       => trim((string)($_POST['body']       ?? '')),
        'affected'   => trim((string)($_POST['affected']   ?? '')),
        'severity'   => (string)($_POST['severity']        ?? 'minor'),
        'status'     => (string)($_POST['status']          ?? 'investigating'),
        'started_at' => trim((string)($_POST['started_at'] ?? '')),
    ];

    if ($action === 'create') {
        try {
            $new_id = incident_create($form, (int)$user['id']);
            flash('success', 'Incident posted.');
            header('Location: /admin/incident-edit.php?id=' . $new_id);
            exit;
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }

    if ($action === 'save_meta' && $incident) {
        try {
            incident_update_meta((int)$incident['id'], $form);
            flash('success', 'Incident details updated.');
            header('Location: /admin/incident-edit.php?id=' . (int)$incident['id']);
            exit;
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }

    if ($action === 'add_update' && $incident) {
        try {
            $body   = trim((string)($_POST['update_body']   ?? ''));
            $status = (string)($_POST['update_status'] ?? 'investigating');
            incident_add_update((int)$incident['id'], $status, $body, (int)$user['id']);
            flash('success', 'Update posted. Status is now ' . INCIDENT_STATUS_LABELS[$status] . '.');
            header('Location: /admin/incident-edit.php?id=' . (int)$incident['id']);
            exit;
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
}

$updates = $incident ? incident_updates_for((int)$incident['id']) : [];
?>

<div class="portal-head">
  <h1><?= $is_new ? 'New incident' : htmlspecialchars($incident['title']) ?></h1>
  <p class="portal-sub">
    <?php if (!$is_new): ?>
      <span class="status-pill <?= htmlspecialchars(incident_severity_class($incident['severity'])) ?>"><?= htmlspecialchars(INCIDENT_SEVERITY_LABELS[$incident['severity']]) ?></span>
      <span class="status-pill status-<?= htmlspecialchars($incident['status']) ?>"><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$incident['status']]) ?></span>
      &middot; started <?= htmlspecialchars(substr((string)$incident['started_at'], 0, 16)) ?>
      <?php if ($incident['resolved_at']): ?>
        &middot; resolved <?= htmlspecialchars(substr((string)$incident['resolved_at'], 0, 16)) ?>
      <?php endif; ?>
    <?php else: ?>
      Posting an incident shows a banner site-wide and an alert on the client dashboard until you resolve it.
    <?php endif; ?>
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul style="margin:0;padding-left:18px;">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
  </ul></div>
<?php endif; ?>

<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= $is_new ? 'create' : 'save_meta' ?>">

  <div class="portal-card">
    <h2><?= $is_new ? 'New incident' : 'Edit details' ?></h2>
    <div class="form form-grid">
      <div class="field" style="grid-column:1/-1;">
        <label>Title</label>
        <input type="text" name="title" required maxlength="200" value="<?= htmlspecialchars($form['title'], ENT_QUOTES) ?>" placeholder="e.g. Outage in Vanderbijlpark">
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label><?= $is_new ? 'First update / description' : 'Original description' ?></label>
        <textarea name="body" required rows="4" maxlength="4000"><?= htmlspecialchars($form['body']) ?></textarea>
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label>Affected areas <span class="muted">(comma-separated)</span></label>
        <input type="text" name="affected" maxlength="255" value="<?= htmlspecialchars($form['affected'], ENT_QUOTES) ?>" placeholder="Vanderbijlpark, Vereeniging">
      </div>
      <div class="field">
        <label>Severity</label>
        <select name="severity">
          <?php foreach (INCIDENT_SEVERITIES as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $form['severity'] === $s ? 'selected' : '' ?>>
              <?= htmlspecialchars(INCIDENT_SEVERITY_LABELS[$s]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($is_new): ?>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <?php foreach (INCIDENT_STATUSES as $s): ?>
              <option value="<?= htmlspecialchars($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>>
                <?= htmlspecialchars(INCIDENT_STATUS_LABELS[$s]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="field">
        <label>Started at</label>
        <input type="datetime-local" name="started_at" required value="<?= htmlspecialchars($form['started_at'], ENT_QUOTES) ?>">
      </div>
    </div>
    <div class="form-actions" style="margin-top:14px;">
      <button type="submit" class="btn btn-primary"><?= $is_new ? 'Post incident' : 'Save details' ?></button>
      <?php if (!$is_new): ?>
        <a href="/admin/incidents.php" class="btn btn-ghost btn-sm">Back to list</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$is_new): ?>
  <div class="portal-card">
    <h2>Updates timeline</h2>
    <div class="ticket-thread">
      <?php foreach ($updates as $up): ?>
        <article class="ticket-msg ticket-msg-admin">
          <header class="ticket-msg-head">
            <strong><?= htmlspecialchars($up['author_name'] ?: $up['author_username'] ?: 'staff') ?></strong>
            <span class="status-pill status-<?= htmlspecialchars($up['status']) ?>"><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$up['status']] ?? $up['status']) ?></span>
            <span class="muted small"><?= htmlspecialchars(substr((string)$up['created_at'], 0, 16)) ?></span>
          </header>
          <div class="ticket-msg-body"><?= nl2br(htmlspecialchars($up['body'])) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="portal-card">
    <h2>Post an update</h2>
    <p class="muted">Picking <strong>Resolved</strong> closes the incident, hides the banner and stamps the resolved time.</p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_update">
      <div class="field">
        <label>New status</label>
        <select name="update_status">
          <?php foreach (INCIDENT_STATUSES as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $incident['status'] === $s ? 'selected' : '' ?>>
              <?= htmlspecialchars(INCIDENT_STATUS_LABELS[$s]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Update message</label>
        <textarea name="update_body" required rows="3" maxlength="4000" placeholder="What did you find? What's the next step?"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Post update</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
