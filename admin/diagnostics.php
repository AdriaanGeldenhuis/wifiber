<?php
/**
 * Polling status — operator answer to "is the data on every dashboard
 * actually live?"
 *
 * Pulls the freshness of every telemetry table, the lock-file age of
 * the cron jobs, the per-vendor poll counts, and the credentials that
 * are blocking polling. If something is amber/red on a dashboard, this
 * is where the operator goes to find out what's broken.
 *
 *   • Top-line summary cards (one per telemetry stream)
 *   • Cron lock files (poll-wireless / poll-devices: present? mtime?)
 *   • Per-vendor poll counts in the last 60 minutes
 *   • Devices over the consecutive-fail threshold (auth blocked)
 *   • Devices with credentials that haven't produced telemetry recently
 *   • Crontab snippet to copy/paste
 */
$page_title = 'Polling status';
$active_key = 'diagnostics';
$auto_refresh_seconds = 30;
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/poll_status.php';
require_once __DIR__ . '/../auth/devices.php';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$summary = [
    'device_health'         => poll_classify(poll_latest_device_health_at()),
    'link_health_samples'   => poll_classify(poll_latest_link_sample_at()),
    'rf_environment_samples'=> poll_classify(poll_latest_rf_sample_at(), 600, 1800),
    'ethernet_health'       => poll_classify(poll_latest_ethernet_sample_at(), 600, 3600),
];

$labels = [
    'device_health'          => ['Device health', 'CPU, memory, RTT, status — populated by bin/poll-devices.php every 1–5 min.'],
    'link_health_samples'    => ['Link telemetry', 'Per-link signal, noise, SNR, CCQ, throughput — populated by bin/poll-wireless.php.'],
    'rf_environment_samples' => ['RF scan',       'Per-frequency RSSI scan from the radio — best-effort, only on radios that support non-disruptive scans.'],
    'ethernet_health'        => ['Ethernet diag', 'Cable SNR, length, duplex, TDR — opportunistic, may be hourly only.'],
];

$lock_w = poll_lockfile_state(POLL_WIRELESS_LOCKFILE);
$lock_d = poll_lockfile_state(POLL_DEVICES_LOCKFILE);

$vendors  = poll_vendor_breakdown(60);
$failing  = poll_failing_credentials(3);
$silent   = poll_silent_devices(60);

$abs_poll_w = realpath(__DIR__ . '/../bin/poll-wireless.php') ?: '/path/to/bin/poll-wireless.php';
$abs_poll_d = realpath(__DIR__ . '/../bin/poll-devices.php')  ?: '/path/to/bin/poll-devices.php';
?>

<div class="portal-head">
  <h1>Polling status</h1>
  <p class="portal-sub">Live picture of the cron jobs that fill the link / sector / device / client dashboards. Page refreshes every 30 s — <a href="/admin/devices.php">Devices</a> · <a href="/admin/links.php">Links</a>.</p>
</div>

<style>
  .ps-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:18px; }
  .ps-card { padding:14px 16px; }
  .ps-card h4 { margin:0 0 4px; font-size:11px; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); font-weight:600; }
  .ps-card .ps-when { font-size:18px; font-weight:500; color:var(--text); margin-top:8px; font-variant-numeric:tabular-nums; }
  .ps-card .ps-iso  { font-size:11px; color:var(--text-muted); margin-top:2px; font-family:monospace; }
  .ps-card p { margin:6px 0 0; font-size:11.5px; color:var(--text-muted); }
  .ps-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04); font-size:13px; gap:12px; }
  .ps-row:last-child { border-bottom:none; }
  .ps-tag { display:inline-block;padding:1px 7px;border-radius:6px;font-size:10.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase; }
  .ps-pre { background:#000;border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-family:monospace;font-size:12px;color:#9ad9ff;white-space:pre;overflow-x:auto; }
</style>

<div class="ps-grid">
  <?php foreach ($summary as $key => $st):
    [$title, $desc] = $labels[$key];
  ?>
    <div class="portal-card ps-card">
      <h4><?= $h($title) ?></h4>
      <?= poll_badge_html($st, $title . ' newest sample') ?>
      <div class="ps-when"><?= $h($st['human']) ?></div>
      <div class="ps-iso"><?= $st['iso'] ? $h($st['iso']) : 'never' ?></div>
      <p><?= $h($desc) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="portal-card">
  <h2>Cron lock files</h2>
  <p class="muted">Poll cron grabs a flock on these files while it runs. Recent mtime = a cron tick happened recently. No file = cron has never run on this host.</p>
  <table class="data-table">
    <thead><tr><th>Cron</th><th>Lockfile</th><th>State</th><th>Last touch</th><th>Age</th></tr></thead>
    <tbody>
      <?php
      foreach ([
          ['Wireless poll', POLL_WIRELESS_LOCKFILE, $lock_w],
          ['Device poll',   POLL_DEVICES_LOCKFILE,  $lock_d],
      ] as [$lbl, $path, $st]):
        if (!$st['exists']) {
            $tag_bg = '#6b7480'; $tag_label = 'never run';
        } elseif ($st['age_s'] !== null && $st['age_s'] <= 120) {
            $tag_bg = '#4ade80'; $tag_label = 'fresh';
        } elseif ($st['age_s'] !== null && $st['age_s'] <= 600) {
            $tag_bg = '#e8a814'; $tag_label = 'stale';
        } else {
            $tag_bg = '#ff5470'; $tag_label = 'cron stuck?';
        }
      ?>
        <tr>
          <td><strong><?= $h($lbl) ?></strong></td>
          <td><code><?= $h($path) ?></code></td>
          <td><span class="ps-tag" style="background:<?= $tag_bg ?>;color:#001218;"><?= $h($tag_label) ?></span></td>
          <td><small><?= $st['mtime'] ? $h($st['mtime']) : '—' ?></small></td>
          <td><small><?= $h(poll_age_human($st['age_s'])) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="portal-card">
  <h2>Per-vendor polls in the last 60 minutes</h2>
  <?php if (!$vendors): ?>
    <p class="muted">No devices on file yet. Add some in <a href="/admin/devices.php">/admin/devices.php</a> and attach credentials.</p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Vendor</th><th>Polls</th><th>Online</th><th>Offline</th><th>Last polled</th></tr></thead>
      <tbody>
        <?php foreach ($vendors as $v):
          $age = poll_age_seconds((string)($v['last_polled'] ?? null));
          $age_label = poll_age_human($age);
          $polls = (int)$v['polls'];
        ?>
          <tr>
            <td><strong><?= $h($v['vendor'] ?: '(unknown)') ?></strong></td>
            <td><?= $polls ?></td>
            <td><?= (int)$v['online_polls'] ?></td>
            <td><?= (int)$v['offline_polls'] ?></td>
            <td><small><?= $h($age_label) ?>
              <?php if ($v['last_polled']): ?> · <span class="muted"><?= $h($v['last_polled']) ?></span><?php endif; ?>
            </small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <small class="muted">Counts include every device_health row in the last hour, so a device polled every minute contributes ~60 rows.</small>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Credentials over the fail threshold <span class="muted">(<?= count($failing) ?>)</span></h2>
  <p class="muted">Each row blocks polling for that device until you fix the credentials in <a href="/admin/devices.php">/admin/devices.php</a> (Creds drawer → Test).</p>
  <?php if (!$failing): ?>
    <p class="muted"><strong>Healthy.</strong> No credential row has hit the consecutive-fail threshold.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Device</th><th>Vendor / model</th><th>Mgmt IP</th><th>Scheme</th><th>Fails</th><th>Last OK</th><th>Last error</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($failing as $f): ?>
          <tr>
            <td><a href="/admin/device-view.php?id=<?= (int)$f['device_id'] ?>"><?= $h($f['name']) ?></a></td>
            <td><?= $h($f['vendor']) ?><?= $f['model'] ? '<br><small class="muted">' . $h($f['model']) . '</small>' : '' ?></td>
            <td><code><?= $h($f['mgmt_ip']) ?></code></td>
            <td><code><?= $h($f['scheme']) ?></code></td>
            <td><span class="ps-tag" style="background:#ff5470;color:#001218;"><?= (int)$f['consecutive_fails'] ?></span></td>
            <td><small><?= $h($f['last_auth_ok_at'] ?: 'never') ?></small></td>
            <td><small class="muted" style="word-break:break-word;"><?= $h(mb_substr((string)$f['last_auth_err'], 0, 160)) ?></small></td>
            <td><a class="btn btn-ghost btn-sm" href="/admin/devices.php#discover">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Pollable devices with no recent telemetry <span class="muted">(<?= count($silent) ?>)</span></h2>
  <p class="muted">Has credentials, has a management IP, is on a supported vendor — but hasn't produced a <code>device_health</code> row in the last hour. Either the cron isn't running, the network can't reach the device, or the adapter is hitting an error.</p>
  <?php if (!$silent): ?>
    <p class="muted"><strong>Healthy.</strong> Every pollable device has produced telemetry in the last hour.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Device</th><th>Vendor / model</th><th>Mgmt IP</th><th>Last polled</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($silent as $d): ?>
          <tr>
            <td><a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>"><?= $h($d['name']) ?></a></td>
            <td><?= $h($d['vendor']) ?><?= $d['model'] ? '<br><small class="muted">' . $h($d['model']) . '</small>' : '' ?></td>
            <td><code><?= $h($d['mgmt_ip']) ?></code></td>
            <td><small><?= $d['last_polled'] ? $h($d['last_polled']) . ' · ' . $h(poll_age_human(poll_age_seconds($d['last_polled']))) : 'never' ?></small></td>
            <td>
              <button type="button" class="btn btn-ghost btn-sm" data-poll-device-now="<?= (int)$d['id'] ?>" data-poll-device-name="<?= $h($d['name']) ?>" title="Run the vendor adapter against this device">Poll now</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Cron snippet</h2>
  <p class="muted">Drop these two lines into your hosting account's crontab. <code>poll-wireless.php</code> handles the heavy radio adapters (Ubiquiti / MikroTik / Cambium / Mimosa); <code>poll-devices.php</code> handles ICMP + simple device health for everything else.</p>
  <pre class="ps-pre"># Every minute — vendor-specific telemetry (signal, noise, SNR, CCQ, rates, throughput)
* * * * * /usr/bin/php <?= $h($abs_poll_w) ?> --quiet >> ~/poll-wireless.log 2>&1

# Every minute — generic device health (status, RTT, CPU, mem, uptime)
* * * * * /usr/bin/php <?= $h($abs_poll_d) ?> --quiet >> ~/poll-devices.log 2>&1
</pre>
  <small class="muted">Tail those log files if a dashboard goes amber/red — most issues surface there first.</small>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
