<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/auth/incidents.php';
require_once __DIR__ . '/auth/outages.php';

$page_title = 'Service status';
$page_desc  = 'Live status of the WiFIBER network — current incidents and recent history.';
$page_slug  = '/status';

$active   = incidents_active_all();
$history  = incidents_recent_resolved(20);

// Auto-detected outages — roll up to the tower level so the public
// page reads as "Tower X is affected" rather than leaking sector-by-
// sector internals. One row per affected tower with the earliest
// start time of any of its outages.
$active_outages_raw = outages_all(['status' => 'active'], 500);
$tower_outages = []; // tower_label => ['started_at' => ..., 'count' => N]
foreach ($active_outages_raw as $o) {
    $label   = $o['scope_label'] ?: 'Network';
    $tower   = trim((string)preg_replace('/^.*?·\s*/', '', $label)); // strip "Sector X · " prefix if present
    if ($tower === '') $tower = $label;
    if (!isset($tower_outages[$tower])) {
        $tower_outages[$tower] = ['started_at' => $o['started_at'], 'count' => 0];
    }
    $tower_outages[$tower]['count']++;
    if (strtotime((string)$o['started_at']) < strtotime((string)$tower_outages[$tower]['started_at'])) {
        $tower_outages[$tower]['started_at'] = $o['started_at'];
    }
}
ksort($tower_outages);

$has_any = !empty($active) || !empty($tower_outages);

require __DIR__ . '/includes/header.php';
?>

<section class="container status-page">
  <div class="status-hero">
    <h1>Service status</h1>
    <?php if ($has_any):
      $bits = [];
      if ($active)         $bits[] = count($active) . ' incident' . (count($active) === 1 ? '' : 's');
      if ($tower_outages)  $bits[] = count($tower_outages) . ' tower' . (count($tower_outages) === 1 ? '' : 's') . ' affected';
    ?>
      <p class="status-summary status-summary-bad">
        <span class="status-dot"></span>
        <?= htmlspecialchars(implode(' · ', $bits)) ?> currently.
      </p>
    <?php else: ?>
      <p class="status-summary status-summary-ok">
        <span class="status-dot"></span>
        All systems operational.
      </p>
    <?php endif; ?>
  </div>

  <?php if ($tower_outages): ?>
    <h2>Active network outages</h2>
    <p class="muted small">Auto-detected from our network monitoring. Our engineers are already working on these &mdash; you don't need to log a ticket.</p>
    <ul class="incident-history">
      <?php foreach ($tower_outages as $tower => $info): ?>
        <li>
          <span class="incident-history-date"><?= htmlspecialchars(substr((string)$info['started_at'], 0, 16)) ?></span>
          <span class="status-pill major">Outage</span>
          <span class="incident-history-title"><?= htmlspecialchars($tower) ?></span>
          <?php if ($info['count'] > 1): ?>
            <span class="muted small">— <?= (int)$info['count'] ?> sectors affected</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

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
