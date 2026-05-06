<?php
/**
 * Read-only notification log for clients.
 *
 * Lets a customer see exactly what we've emailed / SMSed / WhatsApped
 * them and when — handy when they say "I never got the outage SMS"
 * and the operator wants the customer to verify themselves.
 *
 * Per-channel opt-ins live on /account/profile.php; the actual sending
 * is done by auth/notifications.php → notification_log.
 */

declare(strict_types=1);

$page_title = 'Notifications';
$active_key = 'notifications';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/notifications.php';

$h = fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

$rows = [];
try {
    $rows = notify_recent((int)$user['id'], 200);
} catch (Throwable $e) {
    // notification_log may not exist on older deployments — fail silent.
    $rows = [];
}

// Summarise the prefs JSON so the client can see what they've ticked
// without bouncing to /account/profile.php.
$prefs = $user['notify_prefs'] ?? null;
if (is_string($prefs)) $prefs = json_decode($prefs, true);
if (!is_array($prefs)) $prefs = [];

$pref_groups = [
    'outage'      => 'Outage alerts',
    'maintenance' => 'Planned maintenance',
    'link'        => 'Link health',
];
$channels = ['email', 'sms', 'whatsapp', 'push'];

// Active push registrations for this customer — gives them a quick
// "yes my phone is registered" check.
$push_active = function_exists('device_tokens_count')
    ? device_tokens_count((int)$user['id'])
    : 0;

$status_pill = [
    'queued'  => 'status-open',
    'sent'    => 'status-paid',
    'failed'  => 'status-overdue',
    'skipped' => 'status-cancelled',
];

// Channel emoji-free icons (inline SVG via unicode symbol).
$channel_label = [
    'email'    => 'Email',
    'sms'      => 'SMS',
    'whatsapp' => 'WhatsApp',
    'push'     => 'App push',
    'webhook'  => 'Webhook',
];
?>

<div class="portal-head">
  <h1>Notifications</h1>
  <p class="portal-sub">A read-only log of every alert we've sent you. Want to change which alerts you receive? <a href="/account/profile.php#prefs">Update your preferences</a>.</p>
</div>

<div class="portal-card">
  <h2>Your alert preferences</h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead>
      <tr>
        <th>&nbsp;</th>
        <?php foreach ($channels as $c): ?>
          <th style="text-align:center;"><?= $h($channel_label[$c] ?? ucfirst($c)) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pref_groups as $g => $label):
        $default = $g === 'outage';
      ?>
        <tr>
          <td><?= $h($label) ?></td>
          <?php foreach ($channels as $c):
            $key = "{$c}_{$g}";
            $on = array_key_exists($key, $prefs) ? !empty($prefs[$key]) : $default;
          ?>
            <td style="text-align:center;">
              <?php if ($on): ?>
                <span class="status-pill status-paid">on</span>
              <?php else: ?>
                <span class="muted small">off</span>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="margin-top:10px;">
    SMS and WhatsApp may need to be enabled by us before they start sending — we'll confirm by email when they're live.
    <?php if ($push_active > 0): ?>
      <br>App push: <strong><?= (int)$push_active ?> device<?= $push_active === 1 ? '' : 's' ?> registered</strong>.
    <?php else: ?>
      <br>App push activates the moment you sign in on our native app and grant notifications.
    <?php endif; ?>
  </p>
</div>

<div class="portal-card">
  <h2>Recent notifications <span class="muted small">(last <?= count($rows) ?>)</span></h2>
  <?php if (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-icon">✉</div>
      <h3>Nothing sent yet</h3>
      <p>When we send you an outage alert, maintenance notice or invoice email, it'll be logged here.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Sent</th>
          <th>Channel</th>
          <th>Subject / template</th>
          <th>Recipient</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $cls = $status_pill[$r['status'] ?? ''] ?? 'status-open';
          $subject = $r['subject'] ?: $r['template'];
        ?>
          <tr>
            <td class="muted small"><?= $h(substr((string)$r['sent_at'], 0, 16)) ?></td>
            <td><?= $h($channel_label[$r['channel']] ?? ucfirst((string)$r['channel'])) ?></td>
            <td>
              <?= $h($subject) ?>
              <?php if (!empty($r['template']) && $r['template'] !== $subject): ?>
                <br><small class="muted"><?= $h($r['template']) ?></small>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= $h($r['recipient'] ?: '—') ?></td>
            <td>
              <span class="status-pill <?= $h($cls) ?>"><?= $h($r['status']) ?></span>
              <?php if (!empty($r['error'])): ?>
                <br><small class="muted" title="<?= $h($r['error']) ?>"><?= $h(mb_strimwidth((string)$r['error'], 0, 60, '…')) ?></small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small" style="margin-top:14px;">
      Missing something you expected? Check your spam folder, then <a href="/account/tickets.php">open a support ticket</a> and we'll re-send it.
    </p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
