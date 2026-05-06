<?php
/**
 * Customer statement.
 *
 * A running ledger of invoices and payments for the current customer
 * over a date range (defaults to the last 12 months — overridable with
 * ?from=YYYY-MM-DD&to=YYYY-MM-DD).
 *
 * Two modes:
 *   • client viewing their own — wraps in the portal layout so the
 *     navigation is still there and the statement looks like a paper
 *     receipt sitting on the dark portal background.
 *   • staff viewing a customer's (?user_id=N) — renders standalone as a
 *     print-friendly white page so it can be saved to PDF without portal
 *     chrome.
 *
 * Print stylesheet hides the portal sidebar / actions in either mode so
 * Ctrl-P → "Save as PDF" gives a clean A4 sheet.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/invoices.php';
require_once __DIR__ . '/../auth/payments.php';

$user = current_user();
if (!$user) { header('Location: /account/login.php'); exit; }

$role     = (string)($user['role'] ?? '');
$is_staff = in_array($role, ACL_STAFF_ROLES_FALLBACK, true);
if ($role !== 'client' && !$is_staff) {
    http_response_code(403);
    die('Access denied.');
}

$target_id = (int)$user['id'];
if ($is_staff && !empty($_GET['user_id'])) {
    $target_id = (int)$_GET['user_id'];
}
if ($role === 'client' && !empty($_GET['user_id']) && (int)$_GET['user_id'] !== (int)$user['id']) {
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

// Opening balance = un-cancelled invoices issued before $from minus
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

// Build chronological ledger.
$ledger = [];
foreach ($invoices as $i) {
    $ledger[] = [
        'date'   => (string)$i['issued_at'],
        'kind'   => 'invoice',
        'ref'    => (string)$i['number'],
        'desc'   => 'Invoice ' . $i['number']
                    . ($i['status'] === 'paid' ? ' — paid ' . substr((string)($i['paid_at'] ?? ''), 0, 10) : ''),
        'status' => (string)$i['status'],
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
        'status' => 'received',
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

$h         = fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
$money     = fn (float $v) => 'R&nbsp;' . number_format($v, 2);
$site_name = (string)($site['name'] ?? 'WiFIBER');

// Brand asset (logo) — same source of truth as the portal header so a
// custom logo uploaded in /admin/settings.php carries through to the
// statement without a second config knob.
$brand        = $site['brand'] ?? [];
$brand_logo   = !empty($brand['logo_url']) ? (string)$brand['logo_url'] : '/assets/images/header-logo-2x.webp';
$brand_tagline = (string)($site['tagline'] ?? '');

// Build the company address block. The site JSON splits the address
// across address_line1 / address_line2; either may be absent.
$company_address_lines = array_values(array_filter([
    (string)($site['address_line1'] ?? ''),
    (string)($site['address_line2'] ?? ''),
], fn ($v) => trim($v) !== ''));

$pay_ref = strtr((string)($billing['bank_reference_format'] ?? '{number}'), [
    '{number}'   => $customer['account_no'] ?? $customer['username'],
    '{username}' => (string)$customer['username'],
    '{id}'       => (string)$customer['id'],
]);

// Capture the actual statement HTML so we can drop it into either
// wrapper (portal layout for clients, standalone HTML for staff)
// without duplicating markup.
ob_start();
?>
<div class="statement-actions">
  <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print / Save as PDF</button>
  <a href="/account/invoices.php" class="btn btn-ghost btn-sm">&larr; Back to invoices</a>
  <form method="get" class="statement-range" autocomplete="off">
    <label>From <input type="date" name="from" value="<?= $h($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= $h($to) ?>"></label>
    <?php if ($is_staff && $target_id !== (int)$user['id']): ?>
      <input type="hidden" name="user_id" value="<?= (int)$target_id ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-ghost btn-sm">Update</button>
  </form>
</div>

<article class="statement-paper">
  <header class="statement-head">
    <div>
      <h1>Statement</h1>
      <p class="statement-period"><?= $h($from) ?> &rarr; <?= $h($to) ?></p>
      <p class="statement-meta">Generated <?= $h(date('Y-m-d H:i')) ?></p>
    </div>
    <div class="statement-brand">
      <img class="statement-logo" src="<?= $h($brand_logo) ?>" alt="<?= $h($site_name) ?>">
      <strong><?= $h($site_name) ?></strong>
      <?php if ($brand_tagline !== ''): ?>
        <small class="statement-tagline"><?= $h($brand_tagline) ?></small>
      <?php endif; ?>
      <?php foreach ($company_address_lines as $line): ?>
        <small><?= $h($line) ?></small>
      <?php endforeach; ?>
      <?php if (!empty($site['phone'])): ?>
        <small><?= $h((string)$site['phone']) ?></small>
      <?php endif; ?>
      <?php
      $accounts_email = (string)($site['email_accounts'] ?? $site['email_support'] ?? '');
      if ($accounts_email !== ''): ?>
        <small><?= $h($accounts_email) ?></small>
      <?php endif; ?>
    </div>
  </header>

  <section class="statement-grid">
    <div class="statement-customer">
      <span class="statement-label">Customer</span>
      <strong><?= $h($customer['name'] ?: $customer['username']) ?></strong>
      <?php if (!empty($customer['account_no'])): ?>
        <small>Account&nbsp;<?= $h($customer['account_no']) ?></small>
      <?php endif; ?>
      <?php if (!empty($customer['email'])): ?>
        <small><?= $h($customer['email']) ?></small>
      <?php endif; ?>
      <?php if (!empty($customer['address'])): ?>
        <small><?= nl2br($h($customer['address'])) ?></small>
      <?php endif; ?>
    </div>

    <div class="statement-summary">
      <div class="statement-summary-row">
        <span>Opening balance</span>
        <strong><?= $money($opening_balance) ?></strong>
      </div>
      <div class="statement-summary-row">
        <span>Billed in period</span>
        <strong><?= $money((float)$total_billed) ?></strong>
      </div>
      <div class="statement-summary-row">
        <span>Paid in period</span>
        <strong>&minus;&nbsp;<?= $money((float)$total_paid) ?></strong>
      </div>
      <div class="statement-summary-row statement-summary-total <?= $closing_balance > 0 ? 'is-due' : 'is-clear' ?>">
        <span>Closing balance</span>
        <strong><?= $money($closing_balance) ?></strong>
      </div>
      <?php if ($closing_balance > 0): ?>
        <p class="statement-due">Amount due now</p>
      <?php else: ?>
        <p class="statement-due statement-due-clear">Account is up to date — thank you.</p>
      <?php endif; ?>
    </div>
  </section>

  <section>
    <h2 class="statement-section">Ledger</h2>
    <table class="statement-ledger">
      <thead>
        <tr>
          <th>Date</th>
          <th>Reference</th>
          <th>Description</th>
          <th class="num">Debit</th>
          <th class="num">Credit</th>
          <th class="num">Balance</th>
        </tr>
      </thead>
      <tbody>
        <tr class="statement-opening">
          <td><?= $h($from) ?></td>
          <td>&mdash;</td>
          <td><em>Opening balance</em></td>
          <td class="num">&nbsp;</td>
          <td class="num">&nbsp;</td>
          <td class="num"><strong><?= $money($opening_balance) ?></strong></td>
        </tr>
        <?php if (empty($ledger)): ?>
          <tr><td colspan="6" class="statement-empty">No invoices or payments in this period.</td></tr>
        <?php else: ?>
          <?php foreach ($ledger as $row):
            $is_invoice = $row['kind'] === 'invoice';
            $pill_cls = match (true) {
              !$is_invoice                    => 'is-payment',
              $row['status'] === 'paid'       => 'is-paid',
              $row['status'] === 'cancelled'  => 'is-cancelled',
              default                         => 'is-unpaid',
            };
            $pill_label = $is_invoice ? ucfirst((string)$row['status']) : 'Payment';
          ?>
            <tr>
              <td><?= $h($row['date']) ?></td>
              <td>
                <?php if ($is_invoice): ?>
                  <a href="/account/invoices.php?id=<?= (int)$row['invoice_id'] ?>"><?= $h($row['ref']) ?></a>
                <?php else: ?>
                  <?= $h($row['ref']) ?>
                <?php endif; ?>
              </td>
              <td>
                <?= $h($row['desc']) ?>
                <span class="statement-pill <?= $h($pill_cls) ?>"><?= $h($pill_label) ?></span>
              </td>
              <td class="num"><?= $row['debit']  > 0 ? $money((float)$row['debit'])  : '&nbsp;' ?></td>
              <td class="num"><?= $row['credit'] > 0 ? $money((float)$row['credit']) : '&nbsp;' ?></td>
              <td class="num"><?= $money((float)$row['balance']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        <tr class="statement-totals">
          <td colspan="3">Closing balance as of <?= $h($to) ?></td>
          <td class="num"><?= $money((float)$total_billed) ?></td>
          <td class="num"><?= $money((float)$total_paid) ?></td>
          <td class="num"><strong><?= $money($closing_balance) ?></strong></td>
        </tr>
      </tbody>
    </table>
  </section>

  <?php if (!empty($billing['bank_account_number']) && $closing_balance > 0): ?>
    <section class="statement-pay">
      <h2 class="statement-section">How to pay</h2>
      <table class="statement-paydetails">
        <tbody>
          <tr><th>Account holder</th><td><?= $h($billing['bank_account_holder']) ?></td></tr>
          <tr><th>Bank</th>          <td><?= $h($billing['bank_name']) ?></td></tr>
          <tr><th>Account number</th><td><?= $h($billing['bank_account_number']) ?></td></tr>
          <?php if (!empty($billing['bank_branch_code'])): ?>
            <tr><th>Branch code</th>   <td><?= $h($billing['bank_branch_code']) ?></td></tr>
          <?php endif; ?>
          <tr><th>Reference</th>     <td><strong><?= $h($pay_ref) ?></strong></td></tr>
          <tr><th>Amount due</th>    <td><strong><?= $money($closing_balance) ?></strong></td></tr>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

  <footer class="statement-foot">
    Questions about this statement? Contact
    <a href="mailto:<?= $h((string)($site['email_accounts'] ?? $site['email_support'] ?? 'accounts@wifiber.co.za')) ?>"><?= $h((string)($site['email_accounts'] ?? $site['email_support'] ?? 'accounts@wifiber.co.za')) ?></a>
    <?php if (!empty($site['phone'])): ?>
      &middot; <?= $h((string)$site['phone']) ?>
    <?php endif; ?>
  </footer>
</article>

<style>
/* The statement renders both as a portal page (dark surround, white
   "paper" card) and as a standalone print sheet. The same .statement-paper
   block works in both contexts. */
.statement-actions {
  display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
  margin-bottom: 18px;
}
.statement-range { display: flex; gap: 8px; align-items: center; margin-left: auto; flex-wrap: wrap; }
.statement-range label { display: flex; align-items: center; gap: 6px; font-size: .85rem; color: var(--text-dim, #555); }
.statement-range input[type="date"] {
  padding: 6px 8px;
  border: 1px solid var(--border-strong, #c7c9cf);
  border-radius: 6px;
  background: var(--bg-elev, #fff);
  color: var(--text, #222);
  font-family: inherit;
  font-size: .85rem;
}

.statement-paper {
  background: #fff;
  color: #1a1f2c;
  border-radius: 14px;
  padding: 36px 40px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45), 0 1px 0 rgba(255,255,255,0.04) inset;
  font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  line-height: 1.5;
  max-width: 920px;
  margin: 0 auto;
}
.statement-paper h1 {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.9rem;
  letter-spacing: -0.01em;
  margin: 0 0 4px;
  color: #0e1622;
}
.statement-paper h2.statement-section {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1rem;
  text-transform: uppercase;
  letter-spacing: .14em;
  color: #4a5468;
  margin: 32px 0 12px;
  padding-bottom: 6px;
  border-bottom: 1px solid #ecedf0;
}
.statement-paper p { color: inherit; margin: 0 0 .35em; }

.statement-head {
  display: flex; justify-content: space-between; gap: 24px;
  align-items: flex-start; flex-wrap: wrap;
  padding-bottom: 24px;
  border-bottom: 2px solid #0e1622;
}
.statement-period { font-size: 1rem; color: #1a1f2c; margin: 0; }
.statement-meta   { font-size: .82rem; color: #6b7280; margin: 4px 0 0; }
.statement-brand {
  text-align: right; display: flex; flex-direction: column; gap: 2px;
  align-items: flex-end;
}
.statement-logo {
  display: block;
  height: 56px; width: auto;
  max-width: 220px;
  object-fit: contain;
  margin-bottom: 8px;
}
.statement-brand strong { font-size: 1.05rem; color: #0e1622; }
.statement-brand small  { color: #6b7280; font-size: .82rem; line-height: 1.45; }
.statement-tagline { color: #4a5468 !important; font-style: italic; }

.statement-grid {
  display: grid; grid-template-columns: 1.2fr 1fr; gap: 32px;
  margin-top: 28px;
}
.statement-label {
  display: block; text-transform: uppercase; letter-spacing: .14em;
  font-size: .72rem; color: #6b7280; margin-bottom: 6px; font-weight: 600;
}
.statement-customer strong {
  display: block; font-size: 1.1rem; color: #0e1622; margin-bottom: 4px;
}
.statement-customer small {
  display: block; color: #4a5468; font-size: .88rem; line-height: 1.45;
}

.statement-summary {
  background: #f7f8fa; border-radius: 10px; padding: 18px 20px;
  border: 1px solid #ecedf0;
}
.statement-summary-row {
  display: flex; justify-content: space-between; align-items: baseline;
  padding: 6px 0; font-size: .92rem; color: #4a5468;
}
.statement-summary-row strong { color: #0e1622; font-variant-numeric: tabular-nums; }
.statement-summary-total {
  margin-top: 8px; padding-top: 12px; border-top: 1px solid #d9dce2;
  font-size: 1.05rem;
}
.statement-summary-total span { color: #0e1622; font-weight: 600; }
.statement-summary-total strong {
  font-size: 1.4rem;
  font-family: 'Space Grotesk', 'Inter', sans-serif;
}
.statement-summary-total.is-due strong   { color: #b91c1c; }
.statement-summary-total.is-clear strong { color: #047857; }
.statement-due {
  margin: 6px 0 0; font-size: .8rem; text-transform: uppercase;
  letter-spacing: .14em; color: #b91c1c; font-weight: 700;
}
.statement-due-clear { color: #047857; }

.statement-ledger {
  width: 100%; border-collapse: collapse;
  margin-top: 6px;
  font-size: .92rem;
}
.statement-ledger th {
  text-align: left;
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .12em;
  color: #6b7280;
  font-weight: 600;
  padding: 10px 8px;
  border-bottom: 1px solid #d9dce2;
}
.statement-ledger td {
  padding: 11px 8px;
  border-bottom: 1px solid #ecedf0;
  color: #1a1f2c;
  vertical-align: top;
}
.statement-ledger tr:last-child td { border-bottom: none; }
.statement-ledger td.num,
.statement-ledger th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.statement-ledger a { color: #0e6fbb; text-decoration: none; }
.statement-ledger a:hover { text-decoration: underline; }
.statement-opening td { background: #fafbfc; }
.statement-opening em  { color: #6b7280; font-style: normal; }
.statement-empty {
  text-align: center; color: #6b7280; padding: 24px 8px !important;
}
.statement-totals td {
  background: #0e1622; color: #fff !important; font-weight: 600;
  border-bottom: none !important;
}
.statement-totals td.num { color: #fff !important; }
.statement-totals td:first-child { border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
.statement-totals td:last-child  { border-top-right-radius: 8px; border-bottom-right-radius: 8px; }

.statement-pill {
  display: inline-block; margin-left: 10px;
  padding: 1px 8px; border-radius: 999px;
  font-size: .68rem; font-weight: 600;
  text-transform: uppercase; letter-spacing: .08em;
  border: 1px solid transparent;
  vertical-align: middle;
}
.statement-pill.is-paid      { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.statement-pill.is-unpaid    { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.statement-pill.is-cancelled { background: #f4f4f5; color: #6b7280; border-color: #e4e4e7; }
.statement-pill.is-payment   { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }

.statement-pay { margin-top: 28px; }
.statement-paydetails {
  width: 100%; border-collapse: collapse; max-width: 520px;
  font-size: .92rem;
}
.statement-paydetails th {
  text-align: left; padding: 8px 12px;
  background: #f7f8fa; color: #4a5468; font-weight: 500;
  width: 40%; border: 1px solid #ecedf0;
}
.statement-paydetails td {
  padding: 8px 12px; color: #0e1622; border: 1px solid #ecedf0;
}

.statement-foot {
  margin-top: 28px; padding-top: 18px;
  border-top: 1px solid #ecedf0;
  color: #6b7280; font-size: .82rem; text-align: center;
}
.statement-foot a { color: #0e6fbb; }

@media (max-width: 720px) {
  .statement-paper       { padding: 24px 18px; border-radius: 10px; }
  .statement-grid        { grid-template-columns: 1fr; gap: 18px; }
  .statement-brand       { text-align: left; align-items: flex-start; }
  .statement-logo        { height: 44px; }
  .statement-head        { flex-direction: column; gap: 16px; }
  .statement-ledger      { font-size: .82rem; }
  .statement-ledger th, .statement-ledger td { padding: 8px 6px; }
  .statement-actions     { flex-direction: column; align-items: stretch; }
  .statement-range       { margin-left: 0; }
}

@media print {
  body { background: #fff !important; }
  .portal-side, .statement-actions { display: none !important; }
  .portal-main, .portal-inner { padding: 0 !important; max-width: none !important; }
  .statement-paper {
    box-shadow: none; padding: 0; max-width: none; border-radius: 0;
    background: #fff;
  }
  .statement-totals td { background: #0e1622 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .statement-pill { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  a { color: inherit !important; text-decoration: none !important; }
}
</style>
<?php
$paper_html = ob_get_clean();

/* -------- Render: portal layout for clients, standalone for staff -------- */

if ($role === 'client') {
    $page_title = 'Statement';
    $active_key = 'statement';
    require __DIR__ . '/_layout.php';
    echo $paper_html;
    require __DIR__ . '/../auth/portal-footer.php';
    exit;
}

// Staff (admins/billing/etc.) — render as a standalone print-friendly
// page so they can save it to PDF without portal chrome. The paper
// markup is reused; only the wrapper changes.
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Statement &mdash; <?= $h($customer['name'] ?: $customer['username']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<style>
  body { margin: 0; padding: 32px 16px; background: #eef0f4; font-family: 'Inter', sans-serif; }
  .btn { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 999px; font-size: .9rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; }
  .btn-primary { background: #0e1622; color: #fff; }
  .btn-ghost { background: #fff; color: #0e1622; border-color: #c7c9cf; }
  .btn-sm { padding: 6px 14px; font-size: .82rem; }
</style>
</head>
<body>
<?= $paper_html ?>
</body>
</html>
