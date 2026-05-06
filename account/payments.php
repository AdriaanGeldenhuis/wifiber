<?php
/**
 * Read-only payments history for clients.
 *
 * Surfaces every payment we've recorded against the customer's account
 * — manual EFT capture, bank-CSV import, gateway callback, credit-note
 * application — so they can see what we've banked and how it was
 * allocated.  No actions: any disputes go through a support ticket.
 */

declare(strict_types=1);

$page_title = 'Payments';
$active_key = 'payments';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/payments.php';
require_once __DIR__ . '/../auth/invoices.php';

$h     = fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
$money = fn (float $v) => 'R ' . number_format($v, 2);

$rows = payments_for_user((int)$user['id'], 500);

// Roll-ups for the summary cards.
$total_received     = 0.0;
$total_year         = 0.0;
$last_received_at   = null;
$year_start         = (int)date('Y') . '-01-01';
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'received') {
        $total_received += (float)$r['amount'];
        if ((string)$r['received_at'] >= $year_start) {
            $total_year += (float)$r['amount'];
        }
        if ($last_received_at === null || $r['received_at'] > $last_received_at) {
            $last_received_at = $r['received_at'];
        }
    }
}

$balance = invoice_outstanding_balance((int)$user['id']);

// Counts for the outstanding card.  Cheap follow-up queries — keeps
// invoice_outstanding_balance() small.
$pdo  = pdo();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND status = 'unpaid'");
$stmt->execute([(int)$user['id']]);
$unpaid_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) c, COALESCE(SUM(total), 0) t
       FROM invoices
      WHERE user_id = ? AND status = 'unpaid' AND due_at < CURDATE()"
);
$stmt->execute([(int)$user['id']]);
$overdue = $stmt->fetch() ?: ['c' => 0, 't' => 0];

// Build a quick map of invoice numbers so we can render a link instead
// of a bare invoice id in the table.
$invoice_numbers = [];
$invoice_ids = array_filter(array_unique(array_map(fn ($r) => (int)($r['invoice_id'] ?? 0), $rows)));
if ($invoice_ids) {
    $place = implode(',', array_fill(0, count($invoice_ids), '?'));
    $stmt = pdo()->prepare(
        "SELECT id, number FROM invoices WHERE user_id = ? AND id IN ($place)"
    );
    $stmt->execute(array_merge([(int)$user['id']], array_values($invoice_ids)));
    foreach ($stmt->fetchAll() as $r) {
        $invoice_numbers[(int)$r['id']] = (string)$r['number'];
    }
}

$method_labels = [
    'eft'         => 'EFT',
    'debit_order' => 'Debit order',
    'cash'        => 'Cash',
    'card'        => 'Card',
    'payfast'     => 'PayFast',
    'yoco'        => 'Yoco',
    'stripe'      => 'Stripe',
    'credit_note' => 'Credit note',
    'other'       => 'Other',
];
$status_pill = [
    'pending'  => 'status-open',
    'received' => 'status-paid',
    'refunded' => 'status-cancelled',
    'failed'   => 'status-overdue',
];
?>

<div class="portal-head">
  <h1>Payments</h1>
  <p class="portal-sub">A read-only ledger of every payment we've received from you. Looking for invoices? <a href="/account/invoices.php">Head to invoices</a> or print a <a href="/account/statement.php">full statement</a>.</p>
</div>

<div class="card-grid">
  <div class="portal-card">
    <span class="card-label">Outstanding</span>
    <div class="card-num" style="color:<?= $balance['balance'] > 0 ? '#fbbf24' : 'var(--success)' ?>;"><?= $h($money((float)$balance['balance'])) ?></div>
    <p class="card-sub muted">
      <?= $unpaid_count ?> unpaid &middot;
      <?= (int)($overdue['c'] ?? 0) ?> overdue
      <?php if ((float)($overdue['t'] ?? 0) > 0): ?>
        <br>R<?= number_format((float)$overdue['t'], 2) ?> overdue
      <?php endif; ?>
    </p>
  </div>
  <div class="portal-card">
    <span class="card-label">Paid this year</span>
    <div class="card-num" style="font-size:1.6rem;"><?= $h($money($total_year)) ?></div>
    <p class="card-sub muted">Across <?= count(array_filter($rows, fn ($r) => ($r['status'] ?? '') === 'received' && ($r['received_at'] ?? '') >= $year_start)) ?> payments since <?= $h($year_start) ?>.</p>
  </div>
  <div class="portal-card">
    <span class="card-label">Lifetime received</span>
    <div class="card-num" style="font-size:1.6rem;color:var(--text);"><?= $h($money($total_received)) ?></div>
    <p class="card-sub muted">
      <?php if ($last_received_at): ?>
        Last payment <?= $h(substr((string)$last_received_at, 0, 10)) ?>.
      <?php else: ?>
        No payments recorded yet.
      <?php endif; ?>
    </p>
  </div>
</div>

<div class="portal-card">
  <h2>All payments</h2>
  <?php if (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-icon">₂</div>
      <h3>No payments on file yet</h3>
      <p>As soon as we receive your first payment — manual EFT, debit order, or online card — it'll show up here.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Invoice</th>
          <th>Amount</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $cls = $status_pill[$r['status'] ?? ''] ?? 'status-open';
          $inv_id = (int)($r['invoice_id'] ?? 0);
        ?>
          <tr>
            <td class="muted small"><?= $h(substr((string)$r['received_at'], 0, 16)) ?></td>
            <td><?= $h($method_labels[$r['method']] ?? $r['method']) ?></td>
            <td>
              <?= $h($r['reference'] ?: '—') ?>
              <?php if (!empty($r['notes'])): ?>
                <br><small class="muted"><?= $h($r['notes']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($inv_id && isset($invoice_numbers[$inv_id])): ?>
                <a href="/account/invoices.php?id=<?= $inv_id ?>"><?= $h($invoice_numbers[$inv_id]) ?></a>
              <?php elseif ($inv_id): ?>
                <span class="muted">#<?= $inv_id ?></span>
              <?php else: ?>
                <span class="muted small">unallocated</span>
              <?php endif; ?>
            </td>
            <td><?= $h($money((float)$r['amount'])) ?></td>
            <td><span class="status-pill <?= $h($cls) ?>"><?= $h($r['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small" style="margin-top:14px;">
      Spot something off? <a href="/account/tickets.php">Open a ticket</a> with the date and reference and we'll have a look.
    </p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
