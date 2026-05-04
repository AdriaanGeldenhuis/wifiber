<?php
/**
 * Single-sector edit + push-to-radio queue.
 *
 * Two forms:
 *   1. Sector record (DB-only) — name, azimuth/beam, band, frequency,
 *      channel width, TX power, SSID, security, wireless mode, notes.
 *   2. "Apply to radio" — queues a wireless_change_jobs row that the
 *      bin/apply-wireless-changes.php worker will execute. The worker
 *      snapshots the live config first, applies CPE then AP, and rolls
 *      back automatically if the link doesn't reconverge.
 *
 * Recent change jobs for this sector are listed at the bottom so
 * operators can see queued / applying / failed without leaving the
 * page.
 */
$page_title = 'Edit sector';
$active_key = 'sectors';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sectors.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';
require_once __DIR__ . '/../auth/wireless.php';
require_once __DIR__ . '/../auth/totp.php';

$id = (int)($_GET['id'] ?? 0);
$self = '/admin/sector-edit.php?id=' . $id;

$pdo = pdo();
$sector = $id ? $pdo->prepare("SELECT * FROM sectors WHERE id = ? LIMIT 1") : null;
if ($sector) { $sector->execute([$id]); $sector = $sector->fetch() ?: null; }
if (!$sector) {
    echo '<div class="portal-card"><h2>Sector not found</h2><p>Pick one from <a href="/admin/sectors.php">/admin/sectors.php</a>.</p></div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_basic') {
        try {
            sector_save([
                'tower_id'          => (int)$sector['tower_id'],
                'ap_device_id'      => $_POST['ap_device_id'] ?? null,
                'name'              => $_POST['name'] ?? $sector['name'],
                'azimuth_deg'       => $_POST['azimuth_deg']       ?? null,
                'beamwidth_deg'     => $_POST['beamwidth_deg']     ?? null,
                'band'              => $_POST['band']              ?? $sector['band'],
                'frequency_mhz'     => $_POST['frequency_mhz']     ?? null,
                'channel_width_mhz' => $_POST['channel_width_mhz'] ?? null,
                'tx_power_dbm'      => $_POST['tx_power_dbm']      ?? null,
                'max_clients'       => $_POST['max_clients']       ?? null,
                'notes'             => $_POST['notes']             ?? '',
            ], $id);
            // Extra columns added in Phase 10 — sector_save doesn't know them yet.
            $ssid     = trim((string)($_POST['ssid'] ?? ''));
            $security = in_array($_POST['security'] ?? '', WL_SECURITIES, true) ? $_POST['security'] : 'wpa2';
            $mode     = in_array($_POST['wireless_mode'] ?? '', WL_MODES, true) ? $_POST['wireless_mode'] : 'airmax_ac';
            $tdd      = trim((string)($_POST['tdd_framing'] ?? ''));
            $wpa_key  = (string)($_POST['wpa_key'] ?? '');
            $wpa_enc  = $wpa_key !== '' ? encrypt_secret($wpa_key) : null;
            $sql = "UPDATE sectors
                       SET ssid = ?, security = ?, wireless_mode = ?, tdd_framing = ?";
            $args = [$ssid, $security, $mode, $tdd];
            if ($wpa_enc !== null) { $sql .= ', wpa_key_enc = ?'; $args[] = $wpa_enc; }
            $sql .= ' WHERE id = ?';
            $args[] = $id;
            $pdo->prepare($sql)->execute($args);
            audit_log('sector.save', ['target_type' => 'sector', 'target_id' => $id]);
            flash('success', 'Sector updated.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'queue_apply') {
        $payload = [];
        foreach (['frequency_mhz', 'channel_width_mhz', 'tx_power_dbm'] as $k) {
            if (isset($_POST[$k]) && $_POST[$k] !== '') $payload[$k] = (int)$_POST[$k];
        }
        if (!empty($_POST['ssid']))     $payload['ssid']     = (string)$_POST['ssid'];
        if (!empty($_POST['security'])) $payload['security'] = (string)$_POST['security'];
        if (!empty($_POST['wpa_key']))  $payload['wpa_key']  = (string)$_POST['wpa_key'];
        if (!totp_require_step_up($user, (string)($_POST['totp_code'] ?? ''))) {
            flash('error', 'Two-factor code is required for push-to-radio actions.');
            header('Location: ' . $self);
            exit;
        }
        $sched = trim((string)($_POST['scheduled_for'] ?? ''));
        if ($sched !== '') $sched = str_replace('T', ' ', $sched) . ':00';
        try {
            $job_id = wireless_change_job_enqueue('sector', $id, (int)$user['id'], $payload, $sched ?: null);
            audit_log('sector.config_queued', [
                'target_type' => 'sector', 'target_id' => $id,
                'meta' => ['job_id' => $job_id, 'payload_keys' => array_keys($payload)],
            ]);
            flash('success', "Queued change job #$job_id. The worker will pick it up within 60s.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'cancel_job') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        $job = wireless_change_job_find($job_id);
        if ($job && $job['status'] === 'queued' && (int)$job['scope_id'] === $id) {
            wireless_change_job_mark($job_id, 'cancelled');
            audit_log('sector.config_cancelled', ['target_type' => 'sector', 'target_id' => $id]);
            flash('success', "Job #$job_id cancelled.");
        }
        header('Location: ' . $self);
        exit;
    }
}

$tower    = site_find((int)$sector['tower_id']);
$devices  = devices_all();
$ap_devs  = array_values(array_filter($devices, fn ($d) => in_array($d['role'], ['ap', 'backhaul'], true)));
$jobs     = wireless_change_jobs_recent(['scope' => 'sector', 'scope_id' => $id], 20);

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$status_pill = function (string $s): string {
    $colours = [
        'queued' => '#888', 'applying' => '#4477ff',
        'applied' => '#0c8', 'failed' => '#d44',
        'rolled_back' => '#e8a814', 'cancelled' => '#aaa',
    ];
    $c = $colours[$s] ?? '#888';
    return '<span style="display:inline-block;background:' . $c
        . ';color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;">'
        . htmlspecialchars($s) . '</span>';
};
?>

<div class="portal-head">
  <h1>Sector — <?= $h($sector['name']) ?></h1>
  <p class="portal-sub">
    Tower: <strong><?= $h($tower['name'] ?? '#' . $sector['tower_id']) ?></strong>
    &nbsp;·&nbsp;
    Band: <strong><?= $h($sector['band']) ?></strong>
    &nbsp;·&nbsp;
    Customers: <strong><?= isset($sector['customer_count']) ? (int)$sector['customer_count'] : '?' ?></strong>
  </p>
</div>

<div class="portal-card">
  <h2>Sector record</h2>
  <p class="muted">Edits here only update the database. To push changes to the actual radio, use the <strong>Apply to radio</strong> form below.</p>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_basic">

    <div class="field"><label>Name *</label>
      <input type="text" name="name" required value="<?= $h($sector['name']) ?>"></div>

    <div class="field"><label>AP device</label>
      <select name="ap_device_id">
        <option value="">— none —</option>
        <?php foreach ($ap_devs as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)$sector['ap_device_id'] === (int)$d['id'] ? 'selected' : '' ?>>
            <?= $h($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field"><label>Azimuth (°)</label>
      <input type="number" name="azimuth_deg" min="0" max="360" value="<?= $h($sector['azimuth_deg']) ?>"></div>
    <div class="field"><label>Beamwidth (°)</label>
      <input type="number" name="beamwidth_deg" min="0" max="360" value="<?= $h($sector['beamwidth_deg']) ?>"></div>

    <div class="field"><label>Band</label>
      <select name="band">
        <?php foreach (SECTOR_BANDS as $b): ?>
          <option value="<?= $b ?>" <?= $sector['band'] === $b ? 'selected' : '' ?>><?= $b ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Frequency (MHz)</label>
      <input type="number" name="frequency_mhz" value="<?= $h($sector['frequency_mhz']) ?>"></div>
    <div class="field"><label>Channel width (MHz)</label>
      <select name="channel_width_mhz">
        <option value="">—</option>
        <?php foreach ([5, 8, 10, 20, 30, 40, 60, 80, 160] as $w): ?>
          <option value="<?= $w ?>" <?= (int)$sector['channel_width_mhz'] === $w ? 'selected' : '' ?>><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>TX power (dBm)</label>
      <input type="number" name="tx_power_dbm" min="-10" max="40" value="<?= $h($sector['tx_power_dbm']) ?>"></div>

    <div class="field"><label>SSID</label>
      <input type="text" name="ssid" maxlength="64" value="<?= $h($sector['ssid'] ?? '') ?>"></div>
    <div class="field"><label>Security</label>
      <select name="security">
        <?php foreach (WL_SECURITIES as $s): ?>
          <option value="<?= $s ?>" <?= ($sector['security'] ?? 'wpa2') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>WPA key (leave blank to keep)</label>
      <input type="password" name="wpa_key" autocomplete="new-password"></div>
    <div class="field"><label>Wireless mode</label>
      <select name="wireless_mode">
        <?php foreach (WL_MODES as $m): ?>
          <option value="<?= $m ?>" <?= ($sector['wireless_mode'] ?? 'airmax_ac') === $m ? 'selected' : '' ?>><?= $m ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>TDD framing</label>
      <input type="text" name="tdd_framing" value="<?= $h($sector['tdd_framing'] ?? '') ?>"></div>

    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2"><?= $h($sector['notes'] ?? '') ?></textarea></div>

    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Save record</button>
      <a class="btn btn-ghost btn-sm" href="/admin/sectors.php">Back</a>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Apply to radio</h2>
  <p class="muted">Queues a job for <code>bin/apply-wireless-changes.php</code>. The worker snapshots the live radio config, applies CPE-side first then AP-side, and rolls back automatically if the link fails to reconverge in 60s. Customers are notified via the existing outage flow if rollback fails.</p>

  <form method="post" class="form form-grid"
        onsubmit="return confirm('This will reconfigure the live radio. Customers may briefly disconnect. Continue?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="queue_apply">
    <div class="field"><label>Frequency (MHz)</label>
      <input type="number" name="frequency_mhz" placeholder="<?= $h($sector['frequency_mhz']) ?>"></div>
    <div class="field"><label>Channel width (MHz)</label>
      <select name="channel_width_mhz">
        <option value="">unchanged</option>
        <?php foreach ([5, 8, 10, 20, 30, 40, 60, 80, 160] as $w): ?>
          <option value="<?= $w ?>"><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>TX power (dBm)</label>
      <input type="number" name="tx_power_dbm" placeholder="<?= $h($sector['tx_power_dbm']) ?>"></div>
    <div class="field"><label>SSID</label>
      <input type="text" name="ssid" placeholder="<?= $h($sector['ssid'] ?? '') ?>"></div>
    <div class="field"><label>Security</label>
      <select name="security">
        <option value="">unchanged</option>
        <?php foreach (WL_SECURITIES as $s): ?>
          <option value="<?= $s ?>"><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>New WPA key</label>
      <input type="password" name="wpa_key" autocomplete="new-password"></div>
    <div class="field"><label>Schedule for (optional)</label>
      <input type="datetime-local" name="scheduled_for">
      <small class="muted">Empty = run as soon as the worker picks it up.</small>
    </div>
    <?php if (!empty($user['totp_enabled'])): ?>
      <div class="field"><label>Two-factor code *</label>
        <input type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" name="totp_code" required autocomplete="one-time-code">
      </div>
    <?php endif; ?>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Queue change</button>
      <small class="muted">Only filled fields are queued. Empty fields don't change the radio.</small>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Recent change jobs</h2>
  <?php if (!$jobs): ?>
    <small class="muted">No jobs queued for this sector yet.</small>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>#</th><th>Status</th><th>Requested by</th><th>Created</th><th>Started</th><th>Finished</th><th>Payload</th><th>Error</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td>#<?= (int)$j['id'] ?></td>
            <td><?= $status_pill($j['status']) ?></td>
            <td><?= $h($j['requester_name'] ?? '—') ?></td>
            <td><small><?= $h($j['created_at']) ?></small></td>
            <td><small><?= $h($j['started_at'] ?? '—') ?></small></td>
            <td><small><?= $h($j['finished_at'] ?? '—') ?></small></td>
            <td><small><code><?= $h($j['payload_json']) ?></code></small></td>
            <td><small style="color:#d44;"><?= $h($j['error']) ?></small></td>
            <td>
              <?php if ($j['status'] === 'queued'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cancel_job">
                  <input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Cancel</button>
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
