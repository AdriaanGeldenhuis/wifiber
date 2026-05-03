<?php
$page_title = 'Support tickets';
$active_key = 'tickets';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/tickets.php';

$selected = (int)($_GET['id'] ?? 0);
$ticket   = $selected > 0 ? ticket_find($selected) : null;
$status_filter = (string)($_GET['status'] ?? '');
if ($status_filter !== '' && !in_array($status_filter, TICKET_STATUSES, true)) {
    $status_filter = '';
}

// Lightweight poll endpoint — JS calls this every ~12s while a thread
// is open, so a new client reply pulls the page in without manual
// refresh. Returns the highest message id and the ticket status.
if ($ticket && !empty($_GET['poll'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $msgs = ticket_messages((int)$ticket['id']);
    $top  = 0;
    foreach ($msgs as $m) if ((int)$m['id'] > $top) $top = (int)$m['id'];
    echo json_encode([
        'ok'         => true,
        'latest_id'  => $top,
        'count'      => count($msgs),
        'status'     => $ticket['status'],
        'updated_at' => $ticket['updated_at'],
    ]);
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'reply' && $ticket) {
        try {
            $msg_id = ticket_reply(
                (int)$ticket['id'],
                (int)$user['id'],
                'admin',
                (string)($_POST['body'] ?? ''),
                $_FILES['attachment'] ?? null
            );
            ticket_notify_client((int)$ticket['id'], $msg_id);
            flash('success', 'Reply sent.');
            header('Location: /admin/tickets.php?id=' . (int)$ticket['id']);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if ($action === 'set_status' && $ticket) {
        try {
            $new = (string)($_POST['status'] ?? '');
            ticket_set_status((int)$ticket['id'], $new);
            flash('success', 'Status updated to ' . (TICKET_STATUS_LABELS[$new] ?? $new) . '.');
            header('Location: /admin/tickets.php?id=' . (int)$ticket['id']);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$tickets = tickets_all($status_filter ?: null);
?>

<div class="portal-head">
  <h1>Support tickets</h1>
  <p class="portal-sub">All client tickets land here. Reply to keep the conversation in one place.</p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($ticket): /* ----- single-ticket view ----- */
  $messages = ticket_messages((int)$ticket['id']);
?>
  <div class="portal-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
      <div>
        <h2 style="margin-bottom:4px;">#<?= (int)$ticket['id'] ?> &mdash; <?= htmlspecialchars($ticket['subject']) ?></h2>
        <p class="muted small" style="margin:0;">
          From <strong><?= htmlspecialchars($ticket['client_name'] ?: $ticket['username']) ?></strong>
          <?php if (!empty($ticket['client_email'])): ?>
            &middot; <a href="mailto:<?= htmlspecialchars($ticket['client_email']) ?>"><?= htmlspecialchars($ticket['client_email']) ?></a>
          <?php endif; ?>
          &middot; opened <?= htmlspecialchars(substr((string)$ticket['created_at'], 0, 16)) ?>
        </p>
      </div>
      <a href="/admin/tickets.php" class="btn btn-ghost btn-sm">&larr; All tickets</a>
    </div>

    <form method="post" class="inline-form" style="margin-top:14px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_status">
      <label class="muted small" for="status">Status:</label>
      <select id="status" name="status">
        <?php foreach (TICKET_STATUSES as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>>
            <?= htmlspecialchars(TICKET_STATUS_LABELS[$s]) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-ghost btn-sm" type="submit">Update</button>
    </form>
  </div>

  <div class="portal-card">
    <div class="ticket-thread">
      <?php foreach ($messages as $m):
        $is_admin = ($m['author_role'] ?? '') === 'admin';
        $label = $m['author_label'] ?: ($is_admin ? 'staff' : ($ticket['username'] ?? 'client'));
      ?>
        <article class="ticket-msg ticket-msg-<?= $is_admin ? 'admin' : 'client' ?>">
          <header class="ticket-msg-head">
            <strong><?= htmlspecialchars($label) ?></strong>
            <span class="muted small"><?= htmlspecialchars($is_admin ? 'staff' : 'client') ?></span>
            <span class="muted small"><?= htmlspecialchars(substr((string)$m['created_at'], 0, 16)) ?></span>
          </header>
          <div class="ticket-msg-body"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
          <?php if (!empty($m['attachment_path'])): ?>
            <p class="ticket-msg-att">
              📎 <a href="/admin/attachment.php?msg=<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['attachment_name'] ?: 'attachment') ?></a>
              <span class="muted small">(<?= number_format(((int)$m['attachment_size']) / 1024, 1) ?> KB)</span>
            </p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="portal-card">
    <h2>Reply</h2>
    <form method="post" class="form" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reply">
      <div class="field">
        <label for="body">Your message</label>
        <textarea id="body" name="body" required rows="5" maxlength="8000"></textarea>
      </div>
      <div class="field">
        <label for="attachment">Attachment <span class="muted">(optional, max 5 MB)</span></label>
        <input type="file" id="attachment" name="attachment">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Send reply</button>
      </div>
    </form>
  </div>

<?php else: /* ----- list view ----- */ ?>

  <div class="portal-card">
    <h2>All tickets</h2>
    <p class="inline-form" style="margin-bottom:14px;">
      <span class="muted small">Filter:</span>
      <a href="/admin/tickets.php" class="btn btn-ghost btn-sm" <?= $status_filter === '' ? 'aria-current="page"' : '' ?>>All</a>
      <?php foreach (TICKET_STATUSES as $s): ?>
        <a href="/admin/tickets.php?status=<?= htmlspecialchars($s) ?>" class="btn btn-ghost btn-sm" <?= $status_filter === $s ? 'aria-current="page"' : '' ?>>
          <?= htmlspecialchars(TICKET_STATUS_LABELS[$s]) ?>
        </a>
      <?php endforeach; ?>
    </p>

    <?php if (empty($tickets)): ?>
      <div class="empty-state">
        <div class="empty-icon">✉</div>
        <h3>No tickets <?= $status_filter ? 'with this status' : 'yet' ?></h3>
        <p><?= $status_filter
              ? 'Try a different filter, or clear it to see every ticket.'
              : 'When a customer opens a support ticket from the client portal it shows up here.' ?></p>
        <?php if ($status_filter): ?>
          <a class="btn btn-primary" href="/admin/tickets.php">Show all tickets</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>Subject</th><th>Client</th><th>Status</th><th>Messages</th><th>Last update</th></tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td>#<?= (int)$t['id'] ?></td>
              <td><a href="/admin/tickets.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['subject']) ?></a></td>
              <td><?= htmlspecialchars($t['client_name'] ?: $t['username'] ?: '—') ?></td>
              <td><span class="status-pill status-<?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars(TICKET_STATUS_LABELS[$t['status']] ?? $t['status']) ?></span></td>
              <td><?= (int)$t['message_count'] ?></td>
              <td class="muted small"><?= htmlspecialchars(substr((string)$t['updated_at'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php if ($ticket): ?>
<script>
// Live-update the open ticket thread. Polls every 12s and reloads when
// either a new message appears or the ticket's status changes.
(function () {
  var TID = <?= (int)$ticket['id'] ?>;
  var KNOWN = (function () {
    var els = document.querySelectorAll('.ticket-msg');
    var max = 0;
    // We don't render message ids, so use the count + the page load time
    // as a baseline. The poll endpoint returns the *current* count and
    // top id, so on first poll we just record them.
    return { count: els.length, top: -1 };
  })();
  var STATUS = <?= json_encode($ticket['status']) ?>;
  var url = '/admin/tickets.php?id=' + TID + '&poll=1';
  setInterval(async function () {
    try {
      var r = await fetch(url, { credentials: 'same-origin' });
      var j = await r.json();
      if (!j || !j.ok) return;
      if (KNOWN.top === -1) { KNOWN.top = j.latest_id; KNOWN.count = j.count; return; }
      if (j.latest_id > KNOWN.top || j.count > KNOWN.count) {
        window.toast && window.toast('New reply on this ticket — refreshing…', 'info', 2500);
        setTimeout(function () { location.reload(); }, 800);
      } else if (j.status !== STATUS) {
        STATUS = j.status;
        window.toast && window.toast('Status changed to ' + j.status, 'info', 3000);
      }
    } catch (e) {}
  }, 12000);
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
