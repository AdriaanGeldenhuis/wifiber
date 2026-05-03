<?php
$page_title = 'Invoices';
$active_key = 'invoices';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/invoices.php';

$selected = (int)($_GET['id'] ?? 0);
$invoice  = $selected > 0 ? invoice_find($selected) : null;
// Clients can only view their own invoices.
if ($invoice && (int)$invoice['user_id'] !== (int)$user['id']) $invoice = null;

$mine    = invoices_for_user((int)$user['id']);
$billing = invoice_billing_settings();
?>

<div class="portal-head">
  <h1>Invoices</h1>
  <p class="portal-sub">Your billing history.</p>
</div>

<?php if ($invoice):
  $items = invoice_items((int)$invoice['id']);
  $eff   = invoice_effective_status($invoice);
?>
  <div class="portal-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
      <div>
        <h2 style="margin-bottom:4px;"><?= htmlspecialchars($invoice['number']) ?></h2>
        <p class="muted small" style="margin:0;">
          Issued <?= htmlspecialchars($invoice['issued_at']) ?>
          &middot; Due <?= htmlspecialchars($invoice['due_at']) ?>
          &middot; <span class="status-pill status-<?= htmlspecialchars($eff) ?>"><?= htmlspecialchars(INVOICE_STATUS_LABELS[$eff]) ?></span>
        </p>
      </div>
      <a href="/account/invoices.php" class="btn btn-ghost btn-sm">&larr; All invoices</a>
    </div>
  </div>

  <div class="portal-card">
    <h2>Items</h2>
    <table class="data-table">
      <thead>
        <tr><th>Description</th><th>Qty</th><th>Unit (ex-VAT)</th><th>Line total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= htmlspecialchars($it['description']) ?></td>
            <td><?= htmlspecialchars(rtrim(rtrim((string)$it['quantity'], '0'), '.') ?: '1') ?></td>
            <td><?= htmlspecialchars(money((float)$it['unit_price'])) ?></td>
            <td><?= htmlspecialchars(money((float)$it['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" style="text-align:right;">Subtotal</td><td><?= htmlspecialchars(money((float)$invoice['subtotal'])) ?></td></tr>
        <?php if ((float)$invoice['vat_amount'] > 0): ?>
          <tr><td colspan="3" style="text-align:right;">VAT @ <?= htmlspecialchars(rtrim(rtrim((string)$invoice['vat_rate'], '0'), '.')) ?>%</td><td><?= htmlspecialchars(money((float)$invoice['vat_amount'])) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="3" style="text-align:right;"><strong>Total</strong></td><td><strong><?= htmlspecialchars(money((float)$invoice['total'])) ?></strong></td></tr>
      </tfoot>
    </table>
  </div>

  <?php if ($invoice['status'] === 'unpaid' && $billing['bank_account_number']): ?>
    <div class="portal-card">
      <h2>How to pay</h2>
      <ul class="kv">
        <li><span>Account holder</span><strong><?= htmlspecialchars($billing['bank_account_holder']) ?></strong></li>
        <li><span>Bank</span><strong><?= htmlspecialchars($billing['bank_name']) ?></strong></li>
        <li><span>Account number</span><strong><?= htmlspecialchars($billing['bank_account_number']) ?></strong></li>
        <?php if ($billing['bank_branch_code']): ?>
          <li><span>Branch code</span><strong><?= htmlspecialchars($billing['bank_branch_code']) ?></strong></li>
        <?php endif; ?>
        <li><span>Reference</span><strong><?= htmlspecialchars(invoice_payment_reference($invoice, $billing)) ?></strong></li>
      </ul>
      <?php if ($billing['payment_instructions']): ?>
        <p class="muted small" style="margin-top:14px;"><?= nl2br(htmlspecialchars($billing['payment_instructions'])) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php else: ?>

  <div class="portal-card">
    <h2>My invoices</h2>
    <?php if (empty($mine)): ?>
      <div class="empty-state">
        <div class="empty-icon">₂</div>
        <h3>No invoices yet</h3>
        <p>Once your service starts billing, your invoices will appear here. You'll also get an email each month.</p>
      </div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th>Number</th><th>Issued</th><th>Due</th><th>Total</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($mine as $inv):
            $eff = invoice_effective_status($inv);
          ?>
            <tr>
              <td><a href="/account/invoices.php?id=<?= (int)$inv['id'] ?>"><?= htmlspecialchars($inv['number']) ?></a></td>
              <td class="muted small"><?= htmlspecialchars($inv['issued_at']) ?></td>
              <td class="muted small"><?= htmlspecialchars($inv['due_at']) ?></td>
              <td><?= htmlspecialchars(money((float)$inv['total'])) ?></td>
              <td><span class="status-pill status-<?= htmlspecialchars($eff) ?>"><?= htmlspecialchars(INVOICE_STATUS_LABELS[$eff]) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
