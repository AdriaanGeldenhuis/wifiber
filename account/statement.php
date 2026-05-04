<?php
/**
 * Customer statement.
 *
 * A running ledger of invoices and payments for the current customer
 * over the last 12 months (overridable with ?from=YYYY-MM-DD).  Designed
 * to be browser-printable: hit Ctrl-P, choose "Save as PDF" — no PDF
 * library required. The print stylesheet hides the navigation chrome.
 *
 * Available to clients (their own statement) and admins, who can pass
 * ?user_id= to view someone else's.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/invoices.php';
require_once __DIR__ . '/../auth/payments.php';

$user = current_user();
if (!$user) { header('Location: /account/login.php'); exit; }

// Admins can view another customer's statement; clients can only see
// their own.
$target_id = (int)$user['id'];
if (($user['role'] ?? '') === 'admin' && !empty($_GET['user_id'])) {
    $target_id = (int)$_GET['user_id'];
}
if (($user['role'] ?? '') === 'client' && !empty($_GET['user_id']) && (int)$_GET['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    die('You can only view your own statement.');
}

$customer = find_user_by_id($target_id);
if (!$customer || ($customer['role'] ?? '') !== 'client') {
    http_response_code(404);
    die('Customer not found.');
}

$from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-12 months')));
$to   = (string)($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-12 months'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$site    = load_site_settings();
$billing = invoice_billing_settings();

$inv_stmt = pdo()->prepare(
    "SELECT id, number, total, status, issued_at, due_at, paid_at
       FROM invoices
      WHERE user_id = ? AND issued_at BETWEEN ? AND ?
      ORDER BY issued_at ASC, id ASC"
);
$inv_stmt->execute([$target_id, $from, $to]);
$invoices = $inv_stmt->fetchAll();

$pay_stmt = pdo()->prepare(
    "SELECT id, amount, method, reference, received_at, invoice_id
       FROM payments
      WHERE user_id = ? AND status = 'received'
        AND received_at BETWEEN ? AND ?
      ORDER BY received_at ASC, id ASC"
);
$pay_stmt->execute([$target_id, $from . ' 00:00:00', $to . ' 23:59:59']);
$payments = $pay_stmt->fetchAll();

// Opening balance = sum of un-cancelled invoices issued before $from MINUS
// payments received before $from.
$stmt = pdo()->prepare(
    "SELECT COALESCE(SUM(total),0) FROM invoices
      WHERE user_id = ? AND issued_at < ? AND status <> 'cancelled'"
);
$stmt->execute([$target_id, $from]);
$open_inv = (float)$stmt->fetchColumn();
$stmt = pdo()->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM payments
      WHERE user_id = ? AND status = 'received' AND received_at < ?"
);
$stmt->execute([$target_id, $from . ' 00:00:00']);
$open_pay = (float)$stmt->fetchColumn();
$opening_balance = round($open_inv - $open_pay, 2);

// Build a chronological ledger merging invoices and payments.
$ledger = [];
foreach ($invoices as $i) {
    $ledger[] = [
        'date'   => (string)$i['issued_at'],
        'kind'   => 'invoice',
        'ref'    => (string)$i['number'],
        'desc'   => 'Invoice ' . $i['number'] . ($i['status'] === 'paid' ? ' — paid ' . substr((string)($i['paid_at'] ?? ''), 0, 10) : ''),
        'debit'  => (float)$i['total'],
        'credit' => 0.0,
        'invoice_id' => (int)$i['id'],
    ];
}
foreach ($payments as $p) {
    $note = 'Payment · ' . $p['method'] . ($p['reference'] ? ' · ' . $p['reference'] : '');
    $ledger[] = [
        'date'   => substr((string)$p['received_at'], 0, 10),
        'kind'   => 'payment',
        'ref'    => 'PAY-' . (int)$p['id'],
        'desc'   => $note,
        'debit'  => 0.0,
        'credit' => (float)$p['amount'],
        'invoice_id' => $p['invoice_id'] ? (int)$p['invoice_id'] : null,
    ];
}
usort($ledger, fn ($a, $b) => $a['date'] <=> $b['date']);

$running = $opening_balance;
foreach ($ledger as &$row) {
    $running += $row['debit'] - $row['credit'];
    $row['balance'] = round($running, 2);
}
unset($row);
$closing_balance = round($running, 2);

$total_billed = array_sum(array_column($ledger, 'debit'));
$total_paid   = array_sum(array_column($ledger, 'credit'));

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$money = fn (float $v) => 'R ' . number_format($v, 2);
$site_name = (string)($site['name'] ?? 'WiFIBER');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Statement — <?= $h($customer['name'] ?: $customer['username']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Helvetica Neue', Arial, sans-serif; margin: 0; padding: 32px; color: #222; background: #fff; font-size: 13px; }
  h1 { margin: 0 0 4px; font-size: 22px; }
  h2 { font-size: 14px; margin: 24px 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; }
  th { background: #f7f7f9; font-weight: 600; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .muted { color: #888; font-size: 12px; }
  .totals td { border-top: 2px solid #222; font-weight: 600; }
  .pill { display: inline-block; padding: 1px 8px; border-radius: 6px; background: #eee; font-size: 11px; }
  .pill.paid    { background: #def7e8; color: #0a7a3e; }
  .pill.unpaid  { background: #fff3d6; color: #8a6300; }
  .pill.overdue { background: #fde0e0; color: #8a1f1f; }
  .header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  .actions { margin: 24px 0; }
  .actions a, .actions button { display: inline-block; margin-right: 8px; padding: 6px 14px; background: #222; color: #fff; border: 0; border-radius: 6px; text-decoration: none; cursor: pointer; }
  @media print {
    .actions { display: none; }
    body { padding: 0; }
  }
</style>
</head>
<body>

<div class="actions">
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <a href="/account/invoices.php">← Back to invoices</a>
</div>

<div class="header-grid">
  <div>
    <h1>Statement</h1>
    <p class="muted">
      <?= $h($from) ?> &rarr; <?= $h($to) ?><br>
      Generated <?= $h(date('Y-m-d H:i')) ?>
    </p>
  </div>
  <div style="text-align:right;">
    <strong style="font-size:18px;"><?= $h($site_name) ?></strong><br>
    <small class="muted">
      <?= $h((string)($site['email_accounts'] ?? $site['email_support'] ?? '')) ?><br>
      <?= $h((string)($site['phone'] ?? '')) ?>
    </small>
  </div>
</div>

<div class="header-grid" style="margin-top:24px;">
  <div>
    <h2>Customer</h2>
    <strong><?= $h($customer['name'] ?: $customer['username']) ?></strong><br>
    <?php if (!empty($customer['account_no'])): ?><small>Account <?= $h($customer['account_no']) ?></small><br><?php endif; ?>
    <?php if (!empty($customer['address'])): ?><small><?= nl2br($h($customer['address'])) ?></small><?php endif; ?>
  </div>
  <div style="text-align:right;">
    <h2>Balance</h2>
    <p class="muted" style="margin:0;">Opening</p>
    <strong><?= $money($opening_balance) ?></strong><br>
    <p class="muted" style="margin:8px 0 0;">Closing</p>
    <strong style="font-size:20px;color:<?= $closing_balance > 0 ? '#8a1f1f' : '#0a7a3e' ?>;"><?= $money($closing_balance) ?></strong>
  </div>
</div>

<h2>Ledger</h2>
<table>
  <thead>
    <tr>
      <th>Date</th><th>Reference</th><th>Description</th>
      <th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><?= $h($from) ?></td>
      <td>—</td>
      <td><em>Opening balance</em></td>
      <td class="num"></td>
      <td class="num"></td>
      <td class="num"><strong><?= $money($opening_balance) ?></strong></td>
    </tr>
    <?php foreach ($ledger as $row): ?>
      <tr>
        <td><?= $h($row['date']) ?></td>
        <td><?= $h($row['ref']) ?></td>
        <td><?= $h($row['desc']) ?></td>
        <td class="num"><?= $row['debit']  > 0 ? $money($row['debit']) : '' ?></td>
        <td class="num"><?= $row['credit'] > 0 ? $money($row['credit']) : '' ?></td>
        <td class="num"><?= $money($row['balance']) ?></td>
      </tr>
    <?php endforeach; ?>
    <tr class="totals">
      <td colspan="3">Closing balance</td>
      <td class="num"><?= $money($total_billed) ?></td>
      <td class="num"><?= $money($total_paid) ?></td>
      <td class="num"><?= $money($closing_balance) ?></td>
    </tr>
  </tbody>
</table>

<?php if ($billing['bank_account_number'] && $closing_balance > 0): ?>
  <h2>How to pay</h2>
  <table>
    <tbody>
      <tr><th>Account holder</th><td><?= $h($billing['bank_account_holder']) ?></td></tr>
      <tr><th>Bank</th>          <td><?= $h($billing['bank_name']) ?></td></tr>
      <tr><th>Account number</th><td><?= $h($billing['bank_account_number']) ?></td></tr>
      <?php if ($billing['bank_branch_code']): ?><tr><th>Branch code</th><td><?= $h($billing['bank_branch_code']) ?></td></tr><?php endif; ?>
      <tr><th>Reference</th><td><?= $h(strtr((string)$billing['bank_reference_format'], ['{number}' => $customer['account_no'] ?? $customer['username'], '{username}' => (string)$customer['username'], '{id}' => (string)$customer['id']])) ?></td></tr>
    </tbody>
  </table>
<?php endif; ?>

<p class="muted" style="margin-top:32px;">
  Questions about this statement? Reply to your most recent invoice email,
  or contact <?= $h((string)($site['email_accounts'] ?? $site['email_support'] ?? 'accounts@wifiber.co.za')) ?>.
</p>

</body>
</html>
