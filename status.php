<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/auth/incidents.php';

$page_title = 'Service status';
$page_desc  = 'Live status of the WiFIBER network — current incidents and recent history.';
$page_slug  = '/status';

$active   = incidents_active_all();
$history  = incidents_recent_resolved(20);
$has_any  = !empty($active);

require __DIR__ . '/includes/header.php';
?>

<section class="container status-page">
  <div class="status-hero">
    <h1>Service status</h1>
    <?php if ($has_any): ?>
      <p class="status-summary status-summary-bad">
        <span class="status-dot"></span>
        <?= count($active) ?> incident<?= count($active) === 1 ? '' : 's' ?> currently affecting the network.
      </p>
    <?php else: ?>
      <p class="status-summary status-summary-ok">
        <span class="status-dot"></span>
        All systems operational.
      </p>
    <?php endif; ?>
  </div>

  <?php if ($has_any): ?>
    <h2>Active incidents</h2>
    <?php foreach ($active as $i):
      $updates = incident_updates_for((int)$i['id']);
    ?>
      <article class="incident-card incident-<?= htmlspecialchars(incident_severity_class($i['severity'])) ?>">
        <header class="incident-head">
          <h3><?= htmlspecialchars($i['title']) ?></h3>
          <span class="status-pill <?= htmlspecialchars(incident_severity_class($i['severity'])) ?>"><?= htmlspecialchars(INCIDENT_SEVERITY_LABELS[$i['severity']]) ?></span>
          <span class="status-pill status-<?= htmlspecialchars($i['status']) ?>"><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$i['status']]) ?></span>
        </header>
        <p class="incident-meta">
          Started <?= htmlspecialchars(substr((string)$i['started_at'], 0, 16)) ?>
          <?php if (!empty($i['affected'])): ?>
            &middot; <strong>Affected:</strong> <?= htmlspecialchars($i['affected']) ?>
          <?php endif; ?>
        </p>
        <div class="incident-timeline">
          <?php foreach (array_reverse($updates) as $up): ?>
            <div class="incident-update">
              <p class="incident-update-meta">
                <span class="status-pill status-<?= htmlspecialchars($up['status']) ?>"><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$up['status']] ?? $up['status']) ?></span>
                <span class="muted small"><?= htmlspecialchars(substr((string)$up['created_at'], 0, 16)) ?></span>
              </p>
              <p class="incident-update-body"><?= nl2br(htmlspecialchars($up['body'])) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2><?= $has_any ? 'Past incidents' : 'Recent incidents' ?></h2>
  <?php if (empty($history)): ?>
    <p class="muted">No resolved incidents on record yet.</p>
  <?php else: ?>
    <ul class="incident-history">
      <?php foreach ($history as $i): ?>
        <li>
          <span class="incident-history-date"><?= htmlspecialchars(substr((string)$i['resolved_at'], 0, 10)) ?></span>
          <span class="status-pill <?= htmlspecialchars(incident_severity_class($i['severity'])) ?>"><?= htmlspecialchars(INCIDENT_SEVERITY_LABELS[$i['severity']]) ?></span>
          <span class="incident-history-title"><?= htmlspecialchars($i['title']) ?></span>
          <?php if (!empty($i['affected'])): ?>
            <span class="muted small">— <?= htmlspecialchars($i['affected']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
