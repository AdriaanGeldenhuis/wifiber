<?php
/**
 * Credit-note ledger.
 *
 *   • Issue a credit note against a customer (with or without an
 *     invoice anchor).  Uses ZAR as the default currency until we
 *     wire multi-currency into product/invoice flows.
 *   • Apply an open credit note to an unpaid invoice — recorded as a
 *     payments row of method='credit_note'.
 *   • Void an open credit note that was issued in error.
 */
$page_title = 'Credit notes';
$active_key = 'credit_notes';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/invoices.php';

$self = '/admin/credit-notes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    acl_require('invoices.write');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'issue':
                $cn_id = credit_note_create(
                    (int)($_POST['user_id'] ?? 0),
                    (float)($_POST['amount']     ?? 0),
                    (string)($_POST['reason']    ?? ''),
                    !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null,
                    (int)$user['id']
                );
                flash('success', 'Credit note issued (#' . $cn_id . ').');
                break;

            case 'apply':
                credit_note_apply(
                    (int)($_POST['credit_note_id'] ?? 0),
                    (int)($_POST['invoice_id']     ?? 0),
                    (int)$user['id']
                );
                flash('success', 'Credit note applied.');
                break;

            case 'void':
                credit_note_void((int)($_POST['credit_note_id'] ?? 0));
                flash('success', 'Credit note voided.');
                break;
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    header('Location: ' . $self);
    exit;
}

$status_filter = in_array($_GET['status'] ?? '', ['open','applied','void'], true) ? $_GET['status'] : null;
$rows = credit_notes_all($status_filter);

// Build a lookup of unpaid invoices per user so the "apply" form only
// offers what's eligible.
$user_invoices = [];
$inv_stmt = pdo()->query(
    "SELECT id, user_id, number, total, due_at FROM invoices WHERE status = 'unpaid' ORDER BY user_id, due_at"
);
foreach ($inv_stmt as $r) {
    $user_invoices[(int)$r['user_id']][] = $r;
}

// Customer dropdown for issuance.
$clients_stmt = pdo()->query("SELECT id, username, name, account_no FROM users WHERE role = 'client' ORDER BY username");
$clients = $clients_stmt->fetchAll();

$status_label = ['open' => 'Open', 'applied' => 'Applied', 'void' => 'Void'];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Credit notes</h1>
  <p class="portal-sub">Refunds, write-offs, goodwill credits. Applying a credit note to an unpaid invoice records a payment of <code>method=credit_note</code>; the invoice flips to paid once the running total covers it.</p>
</div>

<div class="portal-card">
  <h2>Filter</h2>
  <p>
    <a href="?" class="btn btn-<?= $status_filter === null ? 'primary' : 'ghost' ?> btn-sm">All</a>
    <?php foreach ($status_label as $k => $lbl): ?>
      <a href="?status=<?= $k ?>" class="btn btn-<?= $status_filter === $k ? 'primary' : 'ghost' ?> btn-sm"><?= $h($lbl) ?></a>
    <?php endforeach; ?>
  </p>
</div>

<div class="portal-card">
  <h2>Issued credit notes <span class="muted" style="font-weight:400;font-size:.85em;">(<?= count($rows) ?>)</span></h2>
  <?php if (!$rows): ?>
    <p class="muted">No credit notes match this filter.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Number</th><th>Customer</th>
          <th style="text-align:right;">Amount</th>
          <th>Reason</th><th>Status</th><th>Issued</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $cn):
          $uid     = (int)$cn['user_id'];
          $invs    = $user_invoices[$uid] ?? [];
          $applied = (int)($cn['invoice_id'] ?? 0);
        ?>
          <tr>
            <td><strong><?= $h($cn['number']) ?></strong></td>
            <td>
              <?php if ($cn['username']): ?>
                <a href="/admin/client-edit.php?id=<?= $uid ?>"><?= $h($cn['client_name'] ?: $cn['username']) ?></a>
              <?php else: ?>
                <span class="muted">#<?= $uid ?></span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">R <?= number_format((float)$cn['amount'], 2) ?></td>
            <td><?= $h($cn['reason']) ?></td>
            <td>
              <span class="status-pill status-<?= $h($cn['status']) ?>"><?= $h($status_label[$cn['status']] ?? $cn['status']) ?></span>
              <?php if ($applied): ?><br><small class="muted">→ inv #<?= $applied ?></small><?php endif; ?>
            </td>
            <td><small><?= $h($cn['issued_at']) ?></small></td>
            <td>
              <?php if ($cn['status'] === 'open' && $invs): ?>
                <details>
                  <summary class="btn btn-primary btn-sm">Apply</summary>
                  <form method="post" class="form" style="margin-top:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="credit_note_id" value="<?= (int)$cn['id'] ?>">
                    <select name="invoice_id" required>
                      <?php foreach ($invs as $iv): ?>
                        <option value="<?= (int)$iv['id'] ?>"><?= $h($iv['number']) ?> — R<?= number_format((float)$iv['total'], 2) ?> due <?= $h($iv['due_at']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                  </form>
                </details>
                <form method="post" class="inline-form" data-confirm="Void credit note <?= $h($cn['number']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="void">
                  <input type="hidden" name="credit_note_id" value="<?= (int)$cn['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Void</button>
                </form>
              <?php elseif ($cn['status'] === 'open'): ?>
                <small class="muted">no unpaid invoice for this customer</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Issue a credit note</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="issue">
    <div class="field"><label>Customer</label>
      <select name="user_id" required>
        <option value="">— pick a customer —</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>">
            <?= $h(($c['name'] ?: $c['username']) . ($c['account_no'] ? ' (' . $c['account_no'] . ')' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Amount (R)</label>
      <input type="number" step="0.01" min="0.01" name="amount" required>
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Reason</label>
      <input type="text" name="reason" maxlength="255" placeholder="Goodwill credit for outage on 2026-04-21">
    </div>
    <div class="field" style="grid-column:1/-1;">
      <small class="muted">Apply against a specific invoice on the next page after creating, or leave the credit unallocated as "money on account".</small>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary">Issue credit note</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
