<?php
/**
 * In-app notification inbox.
 *
 * Reads admin_inbox rows visible to the current operator (broadcasts +
 * directed) and lets them mark read individually or in bulk. Workers
 * (poll-wireless, check-link-health, apply-wireless-changes, the outage
 * detector) call inbox_post() to surface anything that needs attention
 * without relying on an email reaching the right inbox.
 */
$page_title = 'Inbox';
$active_key = 'inbox';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/inbox.php';

$self = '/admin/inbox.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $iid = (int)($_POST['id'] ?? 0);
        if (inbox_mark_read($iid, $user)) {
            flash('success', 'Marked as read.');
        }
    } elseif ($action === 'mark_all') {
        $n = inbox_mark_all_read($user);
        flash('success', "Marked {$n} item" . ($n === 1 ? '' : 's') . ' as read.');
    }
    header('Location: ' . $self);
    exit;
}

$only_unread = !empty($_GET['unread']);
$rows  = inbox_recent($user, 200, $only_unread);
$total = count($rows);
$unread = inbox_unread_count($user);

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$severity_colour = [
    'info'    => '#0891b2',
    'success' => '#059669',
    'warning' => '#d97706',
    'error'   => '#dc2626',
];
?>

<div class="portal-head">
  <h1>Inbox <?php if ($unread > 0): ?><span class="muted">· <?= $unread ?> unread</span><?php endif; ?></h1>
  <p class="portal-sub">In-app notifications surfaced by workers (outages, drift alerts, push-to-radio results) and other operators. Email/SMS delivery is logged separately under <a href="/admin/audit.php?action=notify">Audit log</a>.</p>
</div>

<div class="portal-card">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <a href="<?= $self ?>" class="btn btn-<?= $only_unread ? 'ghost' : 'primary' ?> btn-sm">All (<?= $total ?>)</a>
    <a href="<?= $self ?>?unread=1" class="btn btn-<?= $only_unread ? 'primary' : 'ghost' ?> btn-sm">Unread (<?= $unread ?>)</a>
    <?php if ($unread > 0): ?>
      <form method="post" style="display:inline;margin-left:auto;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all">
        <button class="btn btn-ghost btn-sm" type="submit">Mark all read</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="portal-card">
  <?php if (!$rows): ?>
    <div class="empty-state">
      <div class="empty-icon"><?= $only_unread ? '✓' : '📭' ?></div>
      <h3><?= $only_unread ? 'All caught up' : 'No notifications yet' ?></h3>
      <p>Operational events posted by workers will land here. You'll also see these in the bell icon at the bottom of the sidebar.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th></th><th>When</th><th>Severity</th><th>Title</th><th>Audience</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $is_read = (int)$r['is_read'] === 1;
          $sc = $severity_colour[$r['severity']] ?? '#888';
        ?>
          <tr<?= $is_read ? ' style="opacity:.55;"' : '' ?>>
            <td>
              <?php if (!$is_read): ?>
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $sc ?>;" title="unread"></span>
              <?php else: ?>
                <span class="muted">·</span>
              <?php endif; ?>
            </td>
            <td><small><?= $h($r['created_at']) ?></small></td>
            <td>
              <span style="display:inline-block;background:<?= $sc ?>;color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;">
                <?= $h($r['severity']) ?>
              </span>
            </td>
            <td>
              <strong><?= $h($r['title']) ?></strong>
              <?php if (!empty($r['body'])): ?>
                <br><small class="muted"><?= $h(mb_substr((string)$r['body'], 0, 200)) ?><?= mb_strlen((string)$r['body']) > 200 ? '…' : '' ?></small>
              <?php endif; ?>
            </td>
            <td><small class="muted"><?= $h($r['user_id'] === null ? $r['audience'] : 'you') ?></small></td>
            <td>
              <?php if (!empty($r['link'])): ?>
                <a href="<?= $h($r['link']) ?>" class="btn btn-ghost btn-sm">Open</a>
              <?php endif; ?>
              <?php if (!$is_read): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
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
