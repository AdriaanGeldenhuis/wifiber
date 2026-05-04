<?php
/**
 * Payments ledger.
 *
 *   • List recent payments (filter by method / status / date).
 *   • Record a manual payment (cash / EFT POP / debit-order receipt).
 *   • Allocate or refund an existing payment.
 *
 * The bank-CSV importer lives at /admin/payments-import.php; gateway
 * receipts come in via /api/v1/payments/ipn.php.  This page is the
 * operator's day-to-day surface for the ledger.
 */
$page_title = 'Payments';
$active_key = 'payments';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/payments.php';

$self = '/admin/payments.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'record':
                payment_record([
                    'user_id'     => (int)($_POST['user_id']    ?? 0),
                    'invoice_id'  => $_POST['invoice_id']       ?? null,
                    'method'      => $_POST['method']           ?? 'eft',
                    'amount'      => (float)($_POST['amount']   ?? 0),
                    'currency'    => $_POST['currency']         ?? 'ZAR',
                    'reference'   => $_POST['reference']        ?? '',
                    'received_at' => $_POST['received_at']      ?? date('Y-m-d H:i:s'),
                    'notes'       => $_POST['notes']            ?? '',
                    'source'      => 'manual',
                ], (int)$user['id']);
                flash('success', 'Payment recorded.');
                break;

            case 'allocate':
                payment_allocate(
                    (int)($_POST['payment_id'] ?? 0),
                    (int)($_POST['invoice_id'] ?? 0)
                );
                flash('success', 'Payment allocated.');
                break;

            case 'refund':
                payment_refund(
                    (int)($_POST['payment_id'] ?? 0),
                    (int)$user['id'],
                    (string)($_POST['reason'] ?? '')
                );
                flash('success', 'Payment refunded.');
                break;
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    header('Location: ' . $self);
    exit;
}

$filter = [
    'method' => $_GET['method']    ?? '',
    'status' => $_GET['status']    ?? '',
    'from'   => $_GET['from']      ?? '',
    'to'     => $_GET['to']        ?? '',
    'search' => $_GET['search']    ?? '',
];
if (!empty($_GET['unallocated'])) $filter['unallocated'] = true;

$rows = payments_all($filter);

// Build per-user lookups for the allocation dropdown.
$user_invoices = [];
foreach (pdo()->query(
    "SELECT id, user_id, number, total, due_at FROM invoices WHERE status = 'unpaid' ORDER BY user_id, due_at"
) as $r) {
    $user_invoices[(int)$r['user_id']][] = $r;
}

$clients = pdo()->query(
    "SELECT id, username, name, account_no FROM users WHERE role = 'client' ORDER BY username"
)->fetchAll();

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Payments</h1>
  <p class="portal-sub">Ledger of every cash-in event. Manual capture below, bank CSV import at <a href="/admin/payments-import.php">payments-import</a>, gateway IPNs at <code>/api/v1/payments/ipn.php</code>.</p>
</div>

<div class="portal-card">
  <h2>Filter</h2>
  <form method="get" class="form form-grid">
    <div class="field"><label>Method</label>
      <select name="method">
        <option value="">— any —</option>
        <?php foreach (PAYMENT_METHODS as $m): ?>
          <option value="<?= $m ?>" <?= $filter['method']===$m?'selected':'' ?>><?= $h($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status">
        <option value="">— any —</option>
        <?php foreach (PAYMENT_STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= $filter['status']===$s?'selected':'' ?>><?= $h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>From</label>
      <input type="date" name="from" value="<?= $h($filter['from']) ?>">
    </div>
    <div class="field"><label>To</label>
      <input type="date" name="to" value="<?= $h($filter['to']) ?>">
    </div>
    <div class="field"><label>Search</label>
      <input type="text" name="search" value="<?= $h($filter['search']) ?>" placeholder="reference / username">
    </div>
    <div class="field-check" style="grid-column:1/-1;">
      <input type="checkbox" id="unallocated" name="unallocated" value="1" <?= !empty($_GET['unallocated'])?'checked':'' ?>>
      <label for="unallocated">Only show unallocated (money on account)</label>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary btn-sm" type="submit">Filter</button>
      <a href="<?= $self ?>" class="btn btn-ghost btn-sm">Reset</a>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Ledger <span class="muted" style="font-weight:400;font-size:.85em;">(<?= count($rows) ?>)</span></h2>
  <?php if (!$rows): ?>
    <p class="muted">Nothing matches.</p>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th><th>Customer</th><th>Method</th>
            <th style="text-align:right;">Amount</th>
            <th>Reference</th><th>Invoice</th><th>Source</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p):
            $uid    = (int)$p['user_id'];
            $invs   = $user_invoices[$uid] ?? [];
            $unalloc = empty($p['invoice_id']) && $p['status'] === 'received';
          ?>
            <tr<?= $unalloc ? ' style="background:rgba(251,191,36,.06);"' : '' ?>>
              <td><small><?= $h(substr((string)$p['received_at'], 0, 16)) ?></small></td>
              <td>
                <a href="/admin/client-edit.php?id=<?= $uid ?>"><?= $h($p['client_name'] ?: $p['username'] ?: ('#' . $uid)) ?></a>
              </td>
              <td><span class="status-pill"><?= $h($p['method']) ?></span></td>
              <td style="text-align:right;"><strong>R <?= number_format((float)$p['amount'], 2) ?></strong></td>
              <td><small><?= $h($p['reference']) ?></small></td>
              <td>
                <?php if ($p['invoice_number']): ?>
                  <a href="/admin/invoice-edit.php?id=<?= (int)$p['invoice_id'] ?>"><?= $h($p['invoice_number']) ?></a>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td><small><?= $h($p['source']) ?></small></td>
              <td><span class="status-pill status-<?= $h($p['status']) ?>"><?= $h($p['status']) ?></span></td>
              <td>
                <?php if ($unalloc && $invs): ?>
                  <details>
                    <summary class="btn btn-primary btn-sm">Allocate</summary>
                    <form method="post" class="form" style="margin-top:8px;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="allocate">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                      <select name="invoice_id" required>
                        <?php foreach ($invs as $iv): ?>
                          <option value="<?= (int)$iv['id'] ?>"><?= $h($iv['number']) ?> — R<?= number_format((float)$iv['total'], 2) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                    </form>
                  </details>
                <?php endif; ?>
                <?php if ($p['status'] === 'received'): ?>
                  <form method="post" class="inline-form" data-confirm="Mark this payment as refunded?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refund">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="reason" value="manual reversal">
                    <button class="btn btn-danger btn-sm" type="submit">Refund</button>
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

<div class="portal-card">
  <h2>Record a manual payment</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record">
    <div class="field"><label>Customer</label>
      <select name="user_id" id="pay-user" required>
        <option value="">— pick —</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= $h(($c['name'] ?: $c['username']) . ($c['account_no'] ? ' ('.$c['account_no'].')' : '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Method</label>
      <select name="method">
        <?php foreach (PAYMENT_METHODS as $m): if ($m === 'credit_note') continue; ?>
          <option value="<?= $m ?>"><?= $h($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Amount (R)</label>
      <input type="number" step="0.01" min="0.01" name="amount" required>
    </div>
    <div class="field"><label>Received at</label>
      <input type="datetime-local" name="received_at" value="<?= date('Y-m-d\TH:i') ?>">
    </div>
    <div class="field"><label>Reference</label>
      <input type="text" name="reference" maxlength="120" placeholder="EFT ref or POP number">
    </div>
    <div class="field"><label>Allocate to invoice (optional)</label>
      <input type="number" name="invoice_id" min="0" placeholder="leave empty for money on account">
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <input type="text" name="notes" maxlength="255">
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary">Record payment</button>
      <a href="/admin/payments-import.php" class="btn btn-ghost">Bulk import bank CSV →</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
