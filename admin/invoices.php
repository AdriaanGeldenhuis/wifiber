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
    acl_require('invoices.write');
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

    /* ----- Bulk actions (POSTed by the .bulk-bar JS in portal.js) -----
     * Action keys: mark_paid | mark_unpaid | mark_cancelled | send_email | delete
     * ids: comma-separated invoice IDs
     * Returns JSON. */
    if (in_array($action, ['mark_paid','mark_unpaid','mark_cancelled','send_email_bulk','delete_bulk'], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
        $ok  = 0; $fail = 0;
        foreach ($ids as $iid) {
            try {
                if ($action === 'mark_paid')      { invoice_set_status($iid, 'paid');      $ok++; }
                elseif ($action === 'mark_unpaid'){ invoice_set_status($iid, 'unpaid');    $ok++; }
                elseif ($action === 'mark_cancelled') { invoice_set_status($iid, 'cancelled'); $ok++; }
                elseif ($action === 'send_email_bulk') {
                    $inv = invoice_find($iid);
                    if ($inv) { $r = send_invoice_email($inv); $r['ok'] ? $ok++ : $fail++; }
                }
                elseif ($action === 'delete_bulk') {
                    if (invoice_delete($iid)) $ok++; else $fail++;
                }
            } catch (Throwable $e) { $fail++; }
        }
        audit_log('invoice.bulk', ['target_type' => 'invoice', 'meta' => [
            'action' => $action, 'ok' => $ok, 'fail' => $fail, 'count' => count($ids),
        ]]);
        echo json_encode([
            'ok' => $fail === 0,
            'message' => sprintf('%d invoice%s updated%s', $ok, $ok === 1 ? '' : 's',
                $fail ? ", {$fail} failed" : ''),
        ]);
        exit;
    }
}

/* CSV export — same filter set. */
if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/../auth/csv.php';
    $export_rows = invoices_all($filter ?: null);
    $shaped = array_map(function ($r) {
        return [
            'number'       => $r['number'],
            'client_name'  => $r['client_name'] ?? '',
            'username'     => $r['username']    ?? '',
            'email'        => $r['client_email'] ?? '',
            'issued_at'    => $r['issued_at'],
            'due_at'       => $r['due_at'],
            'paid_at'      => $r['paid_at'] ?? '',
            'subtotal'     => $r['subtotal'],
            'vat_amount'   => $r['vat_amount'],
            'total'        => $r['total'],
            'status'       => invoice_effective_status($r),
        ];
    }, $export_rows);
    audit_log('invoice.export', ['target_type' => 'invoice', 'meta' => ['rows' => count($shaped), 'filter' => $filter]]);
    csv_download('invoices' . ($filter ? '-' . $filter : ''), $shaped);
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
    <span style="display:inline-flex;gap:6px;">
      <?php
        $export_qs = $filter ? '?status=' . urlencode($filter) . '&export=csv' : '?export=csv';
      ?>
      <a href="/admin/invoices.php<?= htmlspecialchars($export_qs) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
      <a href="/admin/invoice-edit.php" class="btn btn-primary btn-sm">+ New invoice</a>
    </span>
  </div>
</div>

<div class="bulk-bar" id="invoicesBulk" data-bulk-action="/admin/invoices.php<?= $filter ? '?status=' . urlencode($filter) : '' ?>">
  <span class="bulk-count">0 selected</span>
  <div class="bulk-actions">
    <button type="button" class="btn btn-ghost btn-sm" data-bulk="mark_paid">Mark paid</button>
    <button type="button" class="btn btn-ghost btn-sm" data-bulk="mark_unpaid">Mark unpaid</button>
    <button type="button" class="btn btn-ghost btn-sm" data-bulk="mark_cancelled" data-confirm="Cancel {n} invoice(s)?">Cancel</button>
    <button type="button" class="btn btn-ghost btn-sm" data-bulk="send_email_bulk" data-confirm="Email {n} invoice(s) to clients?">Email</button>
    <button type="button" class="btn btn-danger btn-sm" data-bulk="delete_bulk" data-confirm="Delete {n} invoice(s)? This cannot be undone.">Delete</button>
  </div>
</div>

<div class="portal-card">
  <?php if (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-icon">₂</div>
      <h3>No invoices <?= $filter ? 'with this status' : 'yet' ?></h3>
      <p><?= $filter
            ? 'Try clearing the filter to see all invoices.'
            : 'Generate the first invoice manually, or wait for the monthly cron to bill active subscribers.' ?></p>
      <a class="btn btn-primary" href="<?= $filter ? '/admin/invoices.php' : '/admin/invoice-edit.php' ?>"><?= $filter ? 'Show all invoices' : '+ New invoice' ?></a>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table" data-bulk="#invoicesBulk">
      <thead>
        <tr>
          <th class="col-bulk"><input type="checkbox" class="row-check-all" aria-label="Select all"></th>
          <th>Number</th><th>Client</th><th>Issued</th><th>Due</th><th>Total</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $inv):
          $eff = invoice_effective_status($inv);
        ?>
          <tr>
            <td class="col-bulk"><input type="checkbox" class="row-check" value="<?= (int)$inv['id'] ?>" aria-label="Select"></td>
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
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
