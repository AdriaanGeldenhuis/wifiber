<?php
$page_title = 'Invoice editor';
$active_key = 'invoices';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/invoices.php';

$id      = (int)($_GET['id'] ?? 0);
$invoice = $id > 0 ? invoice_find($id) : null;
$is_new  = !$invoice;

$billing = invoice_billing_settings();

$form = $is_new ? [
    'user_id'   => (int)($_GET['user_id'] ?? 0),
    'issued_at' => date('Y-m-d'),
    'due_at'    => date('Y-m-d', strtotime('+' . $billing['payment_terms_days'] . ' days')),
    'vat_rate'  => $billing['vat_rate'],
    'notes'     => '',
] : [
    'user_id'   => (int)$invoice['user_id'],
    'issued_at' => (string)$invoice['issued_at'],
    'due_at'    => (string)$invoice['due_at'],
    'vat_rate'  => (float)$invoice['vat_rate'],
    'notes'     => (string)($invoice['notes'] ?? ''),
];
$existing_items = $invoice ? invoice_items((int)$invoice['id']) : [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'save';

    $form = [
        'user_id'   => (int)($_POST['user_id']   ?? 0),
        'issued_at' => trim((string)($_POST['issued_at'] ?? '')),
        'due_at'    => trim((string)($_POST['due_at']    ?? '')),
        'vat_rate'  => is_numeric($_POST['vat_rate'] ?? null) ? 0 + $_POST['vat_rate'] : 0,
        'notes'     => trim((string)($_POST['notes'] ?? '')),
    ];
    $items_post = [];
    foreach (($_POST['items'] ?? []) as $it) {
        $items_post[] = [
            'description' => (string)($it['description'] ?? ''),
            'quantity'    => (string)($it['quantity']    ?? '1'),
            'unit_price'  => (string)($it['unit_price']  ?? '0'),
        ];
    }

    if ($action === 'save') {
        try {
            if ($is_new) {
                $new_id = invoice_create($form, $items_post, (int)$user['id']);
                if (!empty($_POST['send_email'])) {
                    $r = send_invoice_email(invoice_find($new_id));
                    flash($r['ok'] ? 'success' : 'error',
                          $r['ok'] ? 'Invoice created and emailed.' : 'Invoice created. Email failed: ' . $r['reason']);
                } else {
                    flash('success', 'Invoice created.');
                }
                header('Location: /admin/invoice-edit.php?id=' . $new_id);
                exit;
            }
            invoice_update((int)$invoice['id'], $form, $items_post);
            if (!empty($_POST['send_email'])) {
                $r = send_invoice_email(invoice_find((int)$invoice['id']));
                flash($r['ok'] ? 'success' : 'error',
                      $r['ok'] ? 'Invoice updated and emailed.' : 'Invoice updated. Email failed: ' . $r['reason']);
            } else {
                flash('success', 'Invoice updated.');
            }
            header('Location: /admin/invoice-edit.php?id=' . (int)$invoice['id']);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            // re-render with submitted items so the user doesn't lose work
            $existing_items = array_map(fn($it) => [
                'description' => $it['description'],
                'quantity'    => $it['quantity'],
                'unit_price'  => $it['unit_price'],
                'line_total'  => round((float)$it['quantity'] * (float)$it['unit_price'], 2),
            ], invoice_normalise_items($items_post));
        }
    }

    if ($action === 'fill_from_package' && $form['user_id']) {
        $u = find_user_by_id($form['user_id']);
        $price = $u ? package_price_lookup((string)($u['package'] ?? '')) : null;
        if ($u && $price) {
            $vat = (float)$form['vat_rate'];
            $ex_vat = $vat > 0 ? round($price['price'] * 100 / (100 + $vat), 2) : (float)$price['price'];
            $month = date('F Y', strtotime($form['issued_at'] ?: 'now'));
            $existing_items = [[
                'description' => sprintf('%s %s/%s Mbps service — %s',
                    $price['tier_name'],
                    invoice_format_speed((float)$price['down']),
                    invoice_format_speed((float)$price['up']),
                    $month),
                'quantity'    => 1,
                'unit_price'  => $ex_vat,
                'line_total'  => $ex_vat,
            ]];
            flash('success', 'Filled from ' . $u['username'] . "'s package: " . $u['package']);
        } else {
            flash('error', $u
                ? "Couldn't match package '" . ($u['package'] ?: 'no package set') . "' to pricing.json."
                : 'Pick a client first.');
        }
    }
}

$clients = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client'));
usort($clients, fn($a, $b) => strcasecmp($a['username'] ?? '', $b['username'] ?? ''));
$rows = $existing_items ?: [['description' => '', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0]];
?>

<div class="portal-head">
  <h1><?= $is_new ? 'New invoice' : 'Edit ' . htmlspecialchars($invoice['number']) ?></h1>
  <p class="portal-sub">
    <?php if (!$is_new): ?>
      Status: <strong><?= htmlspecialchars(INVOICE_STATUS_LABELS[invoice_effective_status($invoice)]) ?></strong>
    <?php else: ?>
      Pick a client and add line items. Use <em>Fill from package</em> to pre-populate from <code>data/pricing.json</code>.
    <?php endif; ?>
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul style="margin:0;padding-left:18px;">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
  </ul></div>
<?php endif; ?>

<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <div class="portal-card">
    <h2>Header</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Client</label>
        <select name="user_id" required>
          <option value="">— pick —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$form['user_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['username']) ?>
              <?= $c['name'] ? '— ' . htmlspecialchars($c['name']) : '' ?>
              <?= $c['package'] ? ' · ' . htmlspecialchars($c['package']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Issue date</label>
        <input type="date" name="issued_at" required value="<?= htmlspecialchars($form['issued_at']) ?>">
      </div>
      <div class="field">
        <label>Due date</label>
        <input type="date" name="due_at" required value="<?= htmlspecialchars($form['due_at']) ?>">
      </div>
      <div class="field">
        <label>VAT rate (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="vat_rate" value="<?= htmlspecialchars((string)$form['vat_rate']) ?>">
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label>Notes <span class="muted">(internal — not on the invoice)</span></label>
        <textarea name="notes" rows="2" maxlength="600"><?= htmlspecialchars($form['notes']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="portal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h2 style="margin:0;">Line items</h2>
      <button type="submit" formaction="" name="action" value="fill_from_package" class="btn btn-ghost btn-sm">
        Fill from selected client's package
      </button>
    </div>
    <p class="muted small">Prices are <strong>ex-VAT</strong>. The system adds VAT according to the rate above.</p>
    <table class="data-table" id="items-table">
      <thead>
        <tr><th>Description</th><th style="width:80px;">Qty</th><th style="width:130px;">Unit price (ex-VAT)</th><th style="width:130px;">Line total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $it): ?>
          <tr>
            <td><input type="text" name="items[<?= $i ?>][description]" maxlength="200" value="<?= htmlspecialchars((string)($it['description'] ?? ''), ENT_QUOTES) ?>"></td>
            <td><input type="number" step="0.01" min="0" name="items[<?= $i ?>][quantity]"   value="<?= htmlspecialchars((string)($it['quantity']   ?? '1'),   ENT_QUOTES) ?>"></td>
            <td><input type="number" step="0.01" min="0" name="items[<?= $i ?>][unit_price]" value="<?= htmlspecialchars((string)($it['unit_price'] ?? '0'),   ENT_QUOTES) ?>"></td>
            <td class="muted"><?= htmlspecialchars(money((float)($it['line_total'] ?? 0))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php for ($j = count($rows); $j < count($rows) + 3; $j++): ?>
          <tr>
            <td><input type="text" name="items[<?= $j ?>][description]" maxlength="200" placeholder="extra line"></td>
            <td><input type="number" step="0.01" min="0" name="items[<?= $j ?>][quantity]" value="1"></td>
            <td><input type="number" step="0.01" min="0" name="items[<?= $j ?>][unit_price]" value=""></td>
            <td class="muted">—</td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$is_new): $eff = invoice_effective_status($invoice); ?>
    <div class="portal-card">
      <h2>Totals</h2>
      <ul class="kv">
        <li><span>Subtotal (ex-VAT)</span><strong><?= htmlspecialchars(money((float)$invoice['subtotal'])) ?></strong></li>
        <li><span>VAT @ <?= htmlspecialchars((string)$invoice['vat_rate']) ?>%</span><strong><?= htmlspecialchars(money((float)$invoice['vat_amount'])) ?></strong></li>
        <li><span>Total</span><strong><?= htmlspecialchars(money((float)$invoice['total'])) ?></strong></li>
      </ul>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="form-actions" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary"><?= $is_new ? 'Create invoice' : 'Save changes' ?></button>
      <label class="inline-check">
        <input type="checkbox" name="send_email" value="1"> Email a copy to the client now
      </label>
      <a href="/admin/invoices.php" class="btn btn-ghost btn-sm">Back to list</a>
    </div>
  </div>
</form>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
