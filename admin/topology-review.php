<?php
/**
 * Topology review — approve/ignore site_link_candidates that
 * bin/discover-topology.php emits from LLDP/CDP scans. Approved
 * candidates become regular site_links rows.
 */
$page_title = 'Topology review';
$active_key = 'topology';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';

$self = '/admin/topology-review.php';
$pdo  = pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'approve' && $id > 0) {
        $cand = $pdo->prepare("SELECT * FROM site_link_candidates WHERE id = ?");
        $cand->execute([$id]);
        $c = $cand->fetch();
        if ($c && !empty($c['to_device_id'])) {
            // Pull both devices' site_id and create a backhaul link between them.
            $a = device_find((int)$c['from_device_id']);
            $b = device_find((int)$c['to_device_id']);
            if ($a && $b && $a['site_id'] && $b['site_id'] && $a['site_id'] !== $b['site_id']) {
                site_link_save([
                    'from_site_id'  => $a['site_id'],
                    'to_site_id'    => $b['site_id'],
                    'type'          => 'backhaul',
                    'label'         => 'auto: ' . ($c['source'] ?? 'lldp'),
                ]);
            }
        }
        $pdo->prepare(
            "UPDATE site_link_candidates SET decision = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?"
        )->execute([$user['id'], $id]);
        audit_log('topology.approve', ['target_type' => 'site_link_candidate', 'target_id' => $id]);
        flash('success', 'Candidate approved → site_links row created.');
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'ignore' && $id > 0) {
        $pdo->prepare(
            "UPDATE site_link_candidates SET decision = 'ignored', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?"
        )->execute([$user['id'], $id]);
        audit_log('topology.ignore', ['target_type' => 'site_link_candidate', 'target_id' => $id]);
        flash('success', 'Candidate ignored.');
        header('Location: ' . $self);
        exit;
    }
}

$candidates = $pdo->query(
    "SELECT c.*, df.name AS from_name, dt.name AS to_db_name, u.name AS reviewer
       FROM site_link_candidates c
       LEFT JOIN devices df ON df.id = c.from_device_id
       LEFT JOIN devices dt ON dt.id = c.to_device_id
       LEFT JOIN users   u  ON u.id  = c.reviewed_by
      ORDER BY (decision = 'pending') DESC, observed_at DESC
      LIMIT 200"
)->fetchAll();

$drift = $pdo->query(
    "SELECT a.*, d.name AS device_name, s.name AS sector_name
       FROM config_drift_alerts a
       JOIN devices d ON d.id = a.device_id
       LEFT JOIN sectors s ON s.id = a.sector_id
      WHERE a.resolved_at IS NULL
      ORDER BY a.detected_at DESC"
)->fetchAll();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="portal-head">
  <h1>Topology &amp; drift review</h1>
  <p class="portal-sub">LLDP / CDP discovery candidates and config-drift alerts. Approve a candidate to create a <code>site_links</code> row; resolve drift by either pushing the DB value to the radio (queue a job in <a href="/admin/sector-edit.php">/admin/sector-edit.php</a>) or updating the DB to match what's live.</p>
</div>

<div class="portal-card">
  <h2>Configuration drift <span class="muted">(<?= count($drift) ?> open)</span></h2>
  <?php if (!$drift): ?>
    <small class="muted">No drift detected. Run <code>bin/check-config-drift.php</code> nightly via cron.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Detected</th><th>Device</th><th>Sector</th><th>Field</th><th>Expected (DB)</th><th>Observed (radio)</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($drift as $d): ?>
          <tr>
            <td><small><?= $h($d['detected_at']) ?></small></td>
            <td><strong><?= $h($d['device_name']) ?></strong></td>
            <td><small><?= $h($d['sector_name'] ?? '—') ?></small></td>
            <td><code><?= $h($d['field']) ?></code></td>
            <td><?= $h($d['expected']) ?></td>
            <td style="color:#d44;"><?= $h($d['observed']) ?></td>
            <td>
              <?php if ($d['sector_id']): ?>
                <a class="btn btn-ghost btn-sm" href="/admin/sector-edit.php?id=<?= (int)$d['sector_id'] ?>">Open sector</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Topology candidates <span class="muted">(<?= count($candidates) ?>)</span></h2>
  <?php if (!$candidates): ?>
    <small class="muted">No discovery data yet. Run <code>bin/discover-topology.php</code> against devices with SNMP credentials.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>From</th><th>Sees</th><th>Source</th><th>Confidence</th><th>Observed</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($candidates as $c):
          $known = !empty($c['to_device_id']);
        ?>
          <tr<?= $c['decision'] !== 'pending' ? ' style="opacity:.55;"' : '' ?>>
            <td><strong><?= $h($c['from_name'] ?? '#' . $c['from_device_id']) ?></strong></td>
            <td>
              <?php if ($known): ?>
                <strong><?= $h($c['to_db_name']) ?></strong>
              <?php else: ?>
                <small class="muted">unknown — </small>
              <?php endif; ?>
              <br><small><code><?= $h($c['to_mac']) ?></code></small>
              <?php if ($c['to_name']): ?> <small class="muted">(<?= $h($c['to_name']) ?>)</small><?php endif; ?>
            </td>
            <td><code><?= $h($c['source']) ?></code></td>
            <td><?= number_format((float)$c['confidence'] * 100, 0) ?>%</td>
            <td><small><?= $h($c['observed_at']) ?></small></td>
            <td>
              <?php if ($c['decision'] === 'pending'): ?>
                <span class="link-pill" style="background:#888;color:#fff;">pending</span>
              <?php elseif ($c['decision'] === 'approved'): ?>
                <span class="link-pill" style="background:#0c8;color:#fff;">approved</span>
              <?php else: ?>
                <span class="link-pill" style="background:#d44;color:#fff;">ignored</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['decision'] === 'pending'): ?>
                <?php if ($known): ?>
                  <form method="post" class="inline-form" style="display:inline-block;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn btn-primary btn-sm">Approve</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="inline-form" style="display:inline-block;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="ignore">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-ghost btn-sm">Ignore</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
