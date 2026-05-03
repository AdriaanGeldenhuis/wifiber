<?php
$page_title = 'Invoices';
$active_key = 'invoices';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/invoices.php';

$filter = (string)($_GET['status'] ?? '');
if ($filter !== '' && $filter !== 'overdue' && !in_array($filter, INVOICE_STATUSES, true)) {
    $filter = '';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'set_status' && $id) {
        try {
            invoice_set_status($id, (string)($_POST['status'] ?? ''));
            flash('success', 'Invoice status updated.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        header('Location: /admin/invoices.php' . ($filter ? '?status=' . urlencode($filter) : ''));
        exit;
    }

    if ($action === 'delete' && $id) {
        if (invoice_delete($id)) flash('success', 'Invoice deleted.');
        else                      flash('error', 'Could not delete invoice.');
        header('Location: /admin/invoices.php' . ($filter ? '?status=' . urlencode($filter) : ''));
        exit;
    }

    if ($action === 'send_email' && $id) {
        $inv = invoice_find($id);
        $r   = $inv ? send_invoice_email($inv) : ['ok' => false, 'reason' => 'not found'];
        flash($r['ok'] ? 'success' : 'error',
              $r['ok'] ? "Invoice email sent to {$inv['client_email']}." : "Email failed: {$r['reason']}.");
        header('Location: /admin/invoices.php' . ($filter ? '?status=' . urlencode($filter) : ''));
        exit;
    }
}

$rows = invoices_all($filter ?: null);
?>

<div class="portal-head">
  <h1>Invoices</h1>
  <p class="portal-sub">Create and manage invoices. Auto-monthly billing runs from <code>bin/invoices-cron.php</code>.</p>
</div>

<div class="portal-card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
    <p class="inline-form" style="margin:0;">
      <span class="muted small">Filter:</span>
      <a href="/admin/invoices.php" class="btn btn-ghost btn-sm" <?= $filter === '' ? 'aria-current="page"' : '' ?>>All</a>
      <?php foreach (INVOICE_STATUSES as $s): ?>
        <a href="/admin/invoices.php?status=<?= htmlspecialchars($s) ?>" class="btn btn-ghost btn-sm" <?= $filter === $s ? 'aria-current="page"' : '' ?>>
          <?= htmlspecialchars(INVOICE_STATUS_LABELS[$s]) ?>
        </a>
      <?php endforeach; ?>
      <a href="/admin/invoices.php?status=overdue" class="btn btn-ghost btn-sm" <?= $filter === 'overdue' ? 'aria-current="page"' : '' ?>>Overdue</a>
    </p>
    <a href="/admin/invoice-edit.php" class="btn btn-primary btn-sm">+ New invoice</a>
  </div>
</div>

<div class="portal-card">
  <?php if (empty($rows)): ?>
    <p class="muted">No invoices <?= $filter ? 'with this status' : 'yet' ?>.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Number</th><th>Client</th><th>Issued</th><th>Due</th><th>Total</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $inv):
          $eff = invoice_effective_status($inv);
        ?>
          <tr>
            <td><a href="/admin/invoice-edit.php?id=<?= (int)$inv['id'] ?>"><?= htmlspecialchars($inv['number']) ?></a></td>
            <td><?= htmlspecialchars($inv['client_name'] ?: $inv['username'] ?: '—') ?></td>
            <td class="muted small"><?= htmlspecialchars($inv['issued_at']) ?></td>
            <td class="muted small"><?= htmlspecialchars($inv['due_at']) ?></td>
            <td><?= htmlspecialchars(money((float)$inv['total'])) ?></td>
            <td><span class="status-pill status-<?= htmlspecialchars($eff) ?>"><?= htmlspecialchars(INVOICE_STATUS_LABELS[$eff]) ?></span></td>
            <td class="row-actions">
              <details>
                <summary>Actions</summary>
                <?php if ($inv['status'] === 'unpaid'): ?>
                  <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                    <input type="hidden" name="status" value="paid">
                    <button class="btn btn-ghost btn-sm" type="submit">Mark paid</button>
                  </form>
                <?php elseif ($inv['status'] === 'paid'): ?>
                  <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                    <input type="hidden" name="status" value="unpaid">
                    <button class="btn btn-ghost btn-sm" type="submit">Mark unpaid</button>
                  </form>
                <?php endif; ?>
                <?php if ($inv['status'] !== 'cancelled'): ?>
                  <form method="post" class="inline-form" data-confirm="Cancel this invoice?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                    <input type="hidden" name="status" value="cancelled">
                    <button class="btn btn-ghost btn-sm" type="submit">Cancel</button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($inv['client_email'])): ?>
                  <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_email">
                    <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Email to client</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="inline-form" data-confirm="Permanently delete this invoice? This cannot be undone.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
