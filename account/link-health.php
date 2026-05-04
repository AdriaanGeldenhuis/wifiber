<?php
/**
 * Customer self-serve link diagnostics — Phase 15.
 *
 * Pulls the customer's own wireless_links rows + recent link_alerts,
 * shows signal / SNR / capacity pills + a simple history chart, and
 * lets them queue an iperf3 speed test (if the operator has set a
 * default target IP in data/site.json).
 */
$page_title = 'Link health';
$active_key = 'link-health';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/diagnostics.php';

$pdo = pdo();

// Self-serve speed test (rate-limited).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'speedtest') {
    require_csrf();
    if (!rate_limit_check('cust-speedtest:' . $user['id'], 3, 3600)) {
        flash('error', 'Speed-tests are limited to 3 per hour.');
        header('Location: /account/link-health.php');
        exit;
    }
    $link_id = (int)($_POST['link_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM wireless_links WHERE id = ? AND customer_id = ? LIMIT 1");
    $stmt->execute([$link_id, (int)$user['id']]);
    if (!$stmt->fetchColumn()) {
        flash('error', 'Link not found.');
        header('Location: /account/link-health.php');
        exit;
    }
    $site = load_site_settings();
    $target = (string)($site['speedtest_target_ip'] ?? '');
    if ($target === '') {
        flash('error', 'Self-serve speed tests are not configured. Please contact support.');
    } else {
        diagnostic_job_enqueue('iperf3', 'link', $link_id, (int)$user['id'], [
            'target_ip'  => $target,
            'duration_s' => 10,
        ]);
        audit_log('customer.speedtest', [
            'target_type' => 'wireless_link', 'target_id' => $link_id,
            'meta' => ['actor' => 'customer'],
        ]);
        flash('success', 'Speed test queued. Refresh in ~30 seconds.');
    }
    header('Location: /account/link-health.php');
    exit;
}

$links = $pdo->prepare(
    "SELECT wl.*, ap.name AS ap_name, ap.model AS ap_model
       FROM wireless_links wl
       JOIN devices ap ON ap.id = wl.ap_device_id
      WHERE wl.customer_id = ?
      ORDER BY wl.last_evaluated_at DESC"
);
$links->execute([(int)$user['id']]);
$links = $links->fetchAll();

$alerts = $pdo->prepare(
    "SELECT la.*, wl.id AS lid
       FROM link_alerts la
       JOIN wireless_links wl ON wl.id = la.link_id
      WHERE wl.customer_id = ? AND la.resolved_at IS NULL
      ORDER BY la.opened_at DESC"
);
$alerts->execute([(int)$user['id']]);
$alerts = $alerts->fetchAll();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$health_pill = function (?int $score): string {
    if ($score === null) return '<span style="display:inline-block;background:#888;color:#fff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;">no data</span>';
    [$bg, $label] = match (true) {
        $score >= 75 => ['#0c8', 'good'],
        $score >= 50 => ['#e8a814', 'fair'],
        default      => ['#d44', 'poor'],
    };
    return sprintf('<span style="display:inline-block;background:%s;color:#fff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;">%d · %s</span>', $bg, $score, $label);
};
?>

<div class="portal-head">
  <h1>Your link health</h1>
  <p class="portal-sub">Live signal, capacity and recent activity on your wireless connection.</p>
</div>

<?php if (!$links): ?>
  <div class="portal-card">
    <p class="muted">We don't have a wireless-link record for your account yet. If you've just been installed, please give it a few minutes for our network to register the connection.</p>
  </div>
<?php else: ?>
  <?php foreach ($links as $l):
    $tests = link_speedtests_recent((int)$l['id'], 6);
  ?>
    <div class="portal-card">
      <h2>
        Link to <?= $h($l['ap_name']) ?>
        &nbsp; <?= $health_pill($l['health_score']) ?>
      </h2>
      <table style="width:100%;font-size:.95rem;">
        <tr><td>Signal</td>
          <td><strong><?= $l['signal_dbm'] !== null ? (int)$l['signal_dbm'] . ' dBm' : '—' ?></strong></td>
          <td>SNR</td>
          <td><strong><?= $l['snr_db'] !== null ? (int)$l['snr_db'] . ' dB' : '—' ?></strong></td></tr>
        <tr><td>TX rate</td>
          <td><?= $l['tx_rate_mbps'] !== null ? number_format((float)$l['tx_rate_mbps'], 0) . ' Mbps' : '—' ?></td>
          <td>Distance</td>
          <td><?= $l['distance_km'] !== null ? number_format((float)$l['distance_km'], 2) . ' km' : '—' ?></td></tr>
        <tr><td>Last reading</td>
          <td colspan="3"><small><?= $h($l['last_evaluated_at'] ?? 'never') ?></small></td></tr>
      </table>

      <?php if ($tests): ?>
        <h3 class="lv-label" style="margin-top:14px;">Recent speed tests</h3>
        <small class="muted">
          <?php foreach ($tests as $t): ?>
            <code><?= number_format((float)($t['mbps_down'] ?? 0), 0) ?> Mbps</code>
          <?php endforeach; ?>
          <span style="margin-left:8px;">(latest first)</span>
        </small>
      <?php endif; ?>

      <form method="post" style="margin-top:14px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="speedtest">
        <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
        <button class="btn btn-primary btn-sm" type="submit">Run a speed test</button>
        <small class="muted">Limited to 3 per hour.</small>
      </form>
    </div>
  <?php endforeach; ?>

  <?php if ($alerts): ?>
    <div class="portal-card" style="border-left:3px solid #e8a814;">
      <h2>We noticed</h2>
      <p class="muted">Our system has flagged the following on your link. A technician has already been notified.</p>
      <ul>
        <?php foreach ($alerts as $a): ?>
          <li>
            <strong><?= $h(str_replace('_', ' ', $a['kind'])) ?></strong>
            &nbsp; <small class="muted">since <?= $h($a['opened_at']) ?></small>
            <br><small><?= $h($a['notes']) ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
