<?php
$page_title = 'Support tickets';
$active_key = 'tickets';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/tickets.php';

$selected = (int)($_GET['id'] ?? 0);
$ticket   = $selected > 0 ? ticket_find($selected) : null;
// Clients can only see their own tickets.
if ($ticket && (int)$ticket['user_id'] !== (int)$user['id']) $ticket = null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $created = ticket_create(
                (int)$user['id'],
                (string)($_POST['subject'] ?? ''),
                (string)($_POST['body'] ?? ''),
                $_FILES['attachment'] ?? null
            );
            ticket_notify_admin($created['ticket_id'], $created['message_id']);
            flash('success', 'Ticket created. We will get back to you shortly.');
            header('Location: /account/tickets.php?id=' . $created['ticket_id']);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if ($action === 'reply' && $ticket) {
        try {
            $msg_id = ticket_reply(
                (int)$ticket['id'],
                (int)$user['id'],
                'client',
                (string)($_POST['body'] ?? ''),
                $_FILES['attachment'] ?? null
            );
            ticket_notify_admin((int)$ticket['id'], $msg_id);
            flash('success', 'Reply sent.');
            header('Location: /account/tickets.php?id=' . (int)$ticket['id']);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$mine = tickets_for_user((int)$user['id']);
?>

<div class="portal-head">
  <h1>Support tickets</h1>
  <p class="portal-sub">Open a ticket and we'll keep the whole conversation in one place.</p>
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
          Opened <?= htmlspecialchars(substr((string)$ticket['created_at'], 0, 16)) ?>
          &middot; Status: <strong><?= htmlspecialchars(TICKET_STATUS_LABELS[$ticket['status']] ?? $ticket['status']) ?></strong>
        </p>
      </div>
      <a href="/account/tickets.php" class="btn btn-ghost btn-sm">&larr; All tickets</a>
    </div>
  </div>

  <div class="portal-card">
    <div class="ticket-thread">
      <?php foreach ($messages as $m):
        $is_admin = ($m['author_role'] ?? '') === 'admin';
        $label = $m['author_label'] ?: ($is_admin ? 'WiFIBER staff' : 'You');
      ?>
        <article class="ticket-msg ticket-msg-<?= $is_admin ? 'admin' : 'client' ?>">
          <header class="ticket-msg-head">
            <strong><?= htmlspecialchars($is_admin ? $label : 'You') ?></strong>
            <span class="muted small"><?= htmlspecialchars(substr((string)$m['created_at'], 0, 16)) ?></span>
          </header>
          <div class="ticket-msg-body"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
          <?php if (!empty($m['attachment_path'])): ?>
            <p class="ticket-msg-att">
              📎 <a href="/account/attachment.php?msg=<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['attachment_name'] ?: 'attachment') ?></a>
              <span class="muted small">(<?= number_format(((int)$m['attachment_size']) / 1024, 1) ?> KB)</span>
            </p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($ticket['status'] !== 'closed' || true): /* always allow client to reply (reply auto-reopens closed) */ ?>
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
  <?php endif; ?>

<?php else: /* ----- list view + new-ticket form ----- */ ?>

  <div class="portal-card">
    <h2>My tickets</h2>
    <?php if (empty($mine)): ?>
      <p class="muted">You haven't opened a ticket yet. Use the form below to start one.</p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>Subject</th><th>Status</th><th>Messages</th><th>Last update</th></tr>
        </thead>
        <tbody>
          <?php foreach ($mine as $t): ?>
            <tr>
              <td>#<?= (int)$t['id'] ?></td>
              <td><a href="/account/tickets.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['subject']) ?></a></td>
              <td><span class="status-pill status-<?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars(TICKET_STATUS_LABELS[$t['status']] ?? $t['status']) ?></span></td>
              <td><?= (int)$t['message_count'] ?></td>
              <td class="muted small"><?= htmlspecialchars(substr((string)$t['updated_at'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="portal-card">
    <h2>Open a new ticket</h2>
    <form method="post" class="form" enctype="multipart/form-data" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" required maxlength="200"
               value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
               placeholder="e.g. Slow speeds in the evenings">
      </div>
      <div class="field">
        <label for="body">Message</label>
        <textarea id="body" name="body" required rows="6" maxlength="8000"
                  placeholder="Tell us what's going on. Include any details you think might help — when it started, error messages, what you've already tried."><?= htmlspecialchars($_POST['body'] ?? '') ?></textarea>
      </div>
      <div class="field">
        <label for="attachment">Attachment <span class="muted">(optional, max 5 MB)</span></label>
        <input type="file" id="attachment" name="attachment">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Send ticket</button>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
