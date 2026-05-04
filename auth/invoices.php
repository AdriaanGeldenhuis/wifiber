<?php
/**
 * Invoice / billing helpers (Section 3 of the roadmap).
 *
 * Storage: invoices + invoice_items tables (see data/schema.sql).
 * Convention: line items hold ex-VAT prices, the invoice row stores subtotal
 * (sum of line totals, ex-VAT), vat_amount (subtotal * vat_rate / 100) and
 * total (subtotal + vat_amount). The auto-generation path treats the
 * pricing.json price as VAT-inclusive (matches what customers see on the
 * /pricing page) and back-calculates the ex-VAT line price.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const INVOICE_STATUSES        = ['unpaid', 'paid', 'cancelled'];
const INVOICE_STATUS_LABELS   = [
    'unpaid'    => 'Unpaid',
    'paid'      => 'Paid',
    'cancelled' => 'Cancelled',
    'overdue'   => 'Overdue',     // derived label, never persisted
];
const INVOICE_REMINDER_INTERVAL = 7 * 86400; // 1 week between reminder emails

/* --------------------------------------------------------------- queries */

function invoice_find(int $id): ?array {
    $stmt = pdo()->prepare(
        "SELECT i.*, u.username, u.name AS client_name, u.email AS client_email,
                u.phone AS client_phone, u.address AS client_address,
                u.package AS client_package
         FROM invoices i
         LEFT JOIN users u ON u.id = i.user_id
         WHERE i.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function invoice_items(int $invoice_id): array {
    $stmt = pdo()->prepare(
        "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$invoice_id]);
    return $stmt->fetchAll();
}

function invoices_for_user(int $user_id): array {
    $stmt = pdo()->prepare(
        "SELECT * FROM invoices WHERE user_id = ? ORDER BY issued_at DESC, id DESC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function invoices_all(?string $filter = null): array {
    $sql = "SELECT i.*, u.username, u.name AS client_name, u.email AS client_email
            FROM invoices i
            LEFT JOIN users u ON u.id = i.user_id";
    $args  = [];
    $where = [];

    if ($filter && in_array($filter, INVOICE_STATUSES, true)) {
        $where[] = "i.status = ?";
        $args[]  = $filter;
    } elseif ($filter === 'overdue') {
        $where[] = "i.status = 'unpaid' AND i.due_at < CURDATE()";
    }

    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY (i.status = 'paid') ASC, i.due_at ASC, i.id DESC";

    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function invoice_effective_status(array $invoice): string {
    $status = (string)($invoice['status'] ?? 'unpaid');
    if ($status === 'unpaid' && !empty($invoice['due_at']) && $invoice['due_at'] < date('Y-m-d')) {
        return 'overdue';
    }
    return $status;
}

/* ------------------------------------------------------------- mutations */

function invoice_create(array $data, array $items, ?int $created_by = null): int {
    $user_id   = (int)($data['user_id']   ?? 0);
    $issued_at = (string)($data['issued_at'] ?? date('Y-m-d'));
    $due_at    = (string)($data['due_at']    ?? '');
    $vat_rate  = (float)($data['vat_rate']  ?? 0);
    $notes     = trim((string)($data['notes'] ?? ''));
    $period    = $data['period_start'] ?? null;

    if ($user_id <= 0)                         throw new InvalidArgumentException('Pick a client.');
    if (!find_user_by_id($user_id))            throw new InvalidArgumentException('Client not found.');
    if (!invoice_valid_date($issued_at))       throw new InvalidArgumentException('Issue date is not valid.');
    if (!invoice_valid_date($due_at))          throw new InvalidArgumentException('Due date is not valid.');
    if (!$items)                                throw new InvalidArgumentException('Add at least one line item.');

    $clean_items = invoice_normalise_items($items);
    if (isset($data['totals_override']) && is_array($data['totals_override'])) {
        [$subtotal, $vat_amount, $total] = array_map(fn($v) => round((float)$v, 2), $data['totals_override']);
    } else {
        [$subtotal, $vat_amount, $total] = invoice_compute_totals($clean_items, $vat_rate);
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $temp_number = 'TMP-' . bin2hex(random_bytes(6));
        $stmt = $pdo->prepare(
            "INSERT INTO invoices
                (number, user_id, status, issued_at, due_at, period_start,
                 subtotal, vat_rate, vat_amount, total, notes, created_by)
             VALUES (?, ?, 'unpaid', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $temp_number, $user_id, $issued_at, $due_at, $period ?: null,
            $subtotal, $vat_rate, $vat_amount, $total,
            $notes ?: null, $created_by,
        ]);
        $id = (int)$pdo->lastInsertId();

        $number = invoice_format_number($id, $issued_at);
        $pdo->prepare("UPDATE invoices SET number = ? WHERE id = ?")->execute([$number, $id]);

        invoice_insert_items($id, $clean_items);

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function invoice_update(int $id, array $data, array $items): bool {
    $existing = invoice_find($id);
    if (!$existing) throw new RuntimeException('Invoice not found.');

    $user_id   = (int)($data['user_id']   ?? $existing['user_id']);
    $issued_at = (string)($data['issued_at'] ?? $existing['issued_at']);
    $due_at    = (string)($data['due_at']    ?? $existing['due_at']);
    $vat_rate  = isset($data['vat_rate']) ? (float)$data['vat_rate'] : (float)$existing['vat_rate'];
    $notes     = array_key_exists('notes', $data) ? trim((string)$data['notes']) : (string)$existing['notes'];

    if (!find_user_by_id($user_id))      throw new InvalidArgumentException('Client not found.');
    if (!invoice_valid_date($issued_at)) throw new InvalidArgumentException('Issue date is not valid.');
    if (!invoice_valid_date($due_at))    throw new InvalidArgumentException('Due date is not valid.');
    if (!$items)                          throw new InvalidArgumentException('Add at least one line item.');

    $clean_items = invoice_normalise_items($items);
    [$subtotal, $vat_amount, $total] = invoice_compute_totals($clean_items, $vat_rate);

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE invoices
             SET user_id = ?, issued_at = ?, due_at = ?, vat_rate = ?,
                 subtotal = ?, vat_amount = ?, total = ?, notes = ?
             WHERE id = ?"
        )->execute([
            $user_id, $issued_at, $due_at, $vat_rate,
            $subtotal, $vat_amount, $total,
            $notes !== '' ? $notes : null,
            $id,
        ]);

        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
        invoice_insert_items($id, $clean_items);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function invoice_set_status(int $id, string $status): bool {
    if (!in_array($status, INVOICE_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown status.');
    }
    $sql = $status === 'paid'
        ? "UPDATE invoices SET status = ?, paid_at = CURRENT_TIMESTAMP WHERE id = ?"
        : "UPDATE invoices SET status = ?, paid_at = NULL WHERE id = ?";
    return pdo()->prepare($sql)->execute([$status, $id]);
}

function invoice_delete(int $id): bool {
    return pdo()->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
}

function invoice_insert_items(int $invoice_id, array $clean_items): void {
    $stmt = pdo()->prepare(
        "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($clean_items as $idx => $it) {
        $stmt->execute([
            $invoice_id,
            $it['description'],
            $it['quantity'],
            $it['unit_price'],
            $it['line_total'],
            $idx,
        ]);
    }
}

function invoice_normalise_items(array $raw): array {
    $out = [];
    foreach ($raw as $it) {
        $desc = trim((string)($it['description'] ?? ''));
        if ($desc === '') continue;
        $qty  = (float)($it['quantity']   ?? 1);
        $unit = (float)($it['unit_price'] ?? 0);
        if ($qty <= 0) $qty = 1;
        $line = round($qty * $unit, 2);
        $out[] = [
            'description' => mb_substr($desc, 0, 200),
            'quantity'    => round($qty, 2),
            'unit_price'  => round($unit, 2),
            'line_total'  => $line,
        ];
    }
    return $out;
}

function invoice_compute_totals(array $clean_items, float $vat_rate): array {
    $subtotal = 0.0;
    foreach ($clean_items as $it) $subtotal += (float)$it['line_total'];
    $subtotal   = round($subtotal, 2);
    $vat_amount = round($subtotal * $vat_rate / 100, 2);
    $total      = round($subtotal + $vat_amount, 2);
    return [$subtotal, $vat_amount, $total];
}

function invoice_valid_date(string $iso): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return false;
    [$y,$m,$d] = array_map('intval', explode('-', $iso));
    return checkdate($m, $d, $y);
}

function invoice_format_number(int $id, string $issued_at_iso): string {
    $year = (int)substr($issued_at_iso, 0, 4) ?: (int)date('Y');
    return sprintf('INV-%04d-%05d', $year, $id);
}

function invoice_format_speed(float $mbps): string {
    return ((float)(int)$mbps === $mbps) ? (string)(int)$mbps : (string)$mbps;
}

/* ------------------------------------------------ subscription auto-gen */

function package_price_lookup(string $package): ?array {
    $package = trim($package);
    if ($package === '') return null;

    $file = DATA_DIR . '/pricing.json';
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return null;
    $tiers = $data['tiers'] ?? [];

    // Match patterns like "Home 10", "Home 10 Mbps", "Business 4 Mbps", "Gaming 6/6 Mbps"
    foreach ($tiers as $key => $tier) {
        $name = (string)($tier['name'] ?? $key);
        if (stripos($package, $name) !== 0) continue;
        // Extract first numeric "down" speed from the rest of the string
        $rest = substr($package, strlen($name));
        if (!preg_match('/(\d+(?:\.\d+)?)/', $rest, $m)) continue;
        $down = (float)$m[1];
        foreach (($tier['plans'] ?? []) as $plan) {
            if ((float)($plan['down'] ?? -1) === $down) {
                return [
                    'tier_key'  => (string)$key,
                    'tier_name' => $name,
                    'down'      => $down,
                    'up'        => (float)($plan['up']   ?? 0),
                    'price'     => (float)($plan['price'] ?? 0), // inc-VAT
                ];
            }
        }
    }
    return null;
}

function invoice_subscription_create_for_user(int $user_id, string $period_start_iso, ?int $created_by = null): ?int {
    $u = find_user_by_id($user_id);
    if (!$u) return null;
    if (($u['role'] ?? '') !== 'client')      return null;

    // Prefer the linked product (Phase 28 product-aware billing) and fall
    // back to the legacy pricing.json lookup if the user only has a
    // free-text package on file. This keeps imports + manual customers
    // billing without forcing every account onto a product first.
    $price = invoice_subscription_price_for_user($u);
    if (!$price || $price['price'] <= 0)       return null;

    // Idempotent: if there's already a subscription invoice for this period, skip.
    $check = pdo()->prepare(
        "SELECT id FROM invoices WHERE user_id = ? AND period_start = ? LIMIT 1"
    );
    $check->execute([$user_id, $period_start_iso]);
    if ($check->fetchColumn()) return null;

    $billing  = invoice_billing_settings();
    $vat_rate = (float)$billing['vat_rate'];
    $term     = max(1, (int)$billing['payment_terms_days']);

    // Treat pricing.json price as VAT-inclusive. Round VAT first, then derive
    // the ex-VAT unit so subtotal + vat_amount equals the inc-VAT price exactly
    // (avoids 1-cent rounding mismatches between the public price and invoice).
    $inc_vat    = round((float)$price['price'], 2);
    $vat_amount = $vat_rate > 0
        ? round($inc_vat - ($inc_vat * 100 / (100 + $vat_rate)), 2)
        : 0.0;
    $ex_vat     = round($inc_vat - $vat_amount, 2);

    $month_label = date('F Y', strtotime($period_start_iso));
    $description = sprintf('%s %s/%s Mbps service — %s',
        $price['tier_name'], invoice_format_speed($price['down']),
        invoice_format_speed($price['up']), $month_label);

    $items = [[
        'description' => $description,
        'quantity'    => 1,
        'unit_price'  => $ex_vat,
    ]];
    $issued = $period_start_iso;
    $due    = date('Y-m-d', strtotime($issued . ' +' . $term . ' days'));

    return invoice_create([
        'user_id'         => $user_id,
        'issued_at'       => $issued,
        'due_at'          => $due,
        'vat_rate'        => $vat_rate,
        'period_start'    => $period_start_iso,
        'notes'           => null,
        // Lock totals to the inc-VAT price advertised on /pricing so customers
        // see the exact same number on the invoice.
        'totals_override' => [$ex_vat, $vat_amount, $inc_vat],
    ], $items, $created_by);
}

/* ----------------------------------------------------------- formatting */

function money(float $amount, ?string $symbol = null): string {
    if ($symbol === null) {
        $billing = invoice_billing_settings();
        $symbol  = (string)$billing['currency_symbol'];
    }
    return $symbol . number_format($amount, 2);
}

function invoice_billing_settings(): array {
    $site = load_site_settings();
    $b    = $site['billing'] ?? [];
    return [
        'vat_rate'             => isset($b['vat_rate']) ? (float)$b['vat_rate'] : 15.0,
        'currency_symbol'      => trim((string)($b['currency_symbol'] ?? 'R ')) === '' ? 'R ' : (string)$b['currency_symbol'],
        'payment_terms_days'   => isset($b['payment_terms_days']) ? max(1, (int)$b['payment_terms_days']) : 7,
        'bank_name'            => (string)($b['bank_name']            ?? ''),
        'bank_account_holder'  => (string)($b['bank_account_holder']  ?? ($site['name'] ?? '')),
        'bank_account_number'  => (string)($b['bank_account_number']  ?? ''),
        'bank_branch_code'     => (string)($b['bank_branch_code']     ?? ''),
        'bank_reference_format'=> (string)($b['bank_reference_format'] ?? '{number}'),
        'payment_instructions' => (string)($b['payment_instructions'] ?? ''),
    ];
}

function invoice_payment_reference(array $invoice, array $billing): string {
    $fmt = (string)($billing['bank_reference_format'] ?? '{number}');
    return strtr($fmt, [
        '{number}'   => (string)$invoice['number'],
        '{username}' => (string)($invoice['username'] ?? ''),
        '{id}'       => (string)$invoice['id'],
    ]);
}

/* ---------------------------------------------------------------- email */

function send_invoice_email(array $invoice): array {
    $email = (string)($invoice['client_email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'client has no email'];
    }
    $items   = invoice_items((int)$invoice['id']);
    $billing = invoice_billing_settings();
    $site    = load_site_settings();
    $name    = (string)($site['name'] ?? 'WiFIBER');
    $support = (string)($site['email_accounts'] ?? $site['email_support'] ?? 'accounts@wifiber.co.za');
    $base    = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
    $url     = rtrim($base, '/') . '/account/invoices.php?id=' . (int)$invoice['id'];
    $sym     = $billing['currency_symbol'];

    $body  = "Hi " . ($invoice['client_name'] ?: $invoice['username']) . ",\n\n";
    $body .= "A new invoice is ready for your account.\n\n";
    $body .= "Number:    {$invoice['number']}\n";
    $body .= "Issued:    {$invoice['issued_at']}\n";
    $body .= "Due:       {$invoice['due_at']}\n";
    $body .= "Total:     " . money((float)$invoice['total'], $sym) . "\n\n";

    $body .= "Items\n";
    $body .= str_repeat('-', 40) . "\n";
    foreach ($items as $it) {
        $body .= sprintf("%-30s %s\n",
            mb_substr((string)$it['description'], 0, 30),
            money((float)$it['line_total'], $sym));
    }
    $body .= str_repeat('-', 40) . "\n";
    $body .= sprintf("%-30s %s\n", 'Subtotal', money((float)$invoice['subtotal'], $sym));
    if ((float)$invoice['vat_amount'] > 0) {
        $body .= sprintf("%-30s %s\n",
            'VAT @ ' . rtrim(rtrim((string)$invoice['vat_rate'], '0'), '.') . '%',
            money((float)$invoice['vat_amount'], $sym));
    }
    $body .= sprintf("%-30s %s\n\n", 'Total', money((float)$invoice['total'], $sym));

    if ($billing['bank_account_number']) {
        $body .= "Banking details\n";
        $body .= str_repeat('-', 40) . "\n";
        $body .= "Account holder: {$billing['bank_account_holder']}\n";
        $body .= "Bank:           {$billing['bank_name']}\n";
        $body .= "Account no:     {$billing['bank_account_number']}\n";
        if ($billing['bank_branch_code']) {
            $body .= "Branch code:    {$billing['bank_branch_code']}\n";
        }
        $body .= "Reference:      " . invoice_payment_reference($invoice, $billing) . "\n\n";
    }
    if ($billing['payment_instructions']) {
        $body .= rtrim($billing['payment_instructions']) . "\n\n";
    }

    $body .= "View this invoice: {$url}\n\n";
    $body .= "Questions? Reply to this email.\n\n";
    $body .= "— The {$name} team\n";

    $headers = "From: {$name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . "Reply-To: {$support}\r\n"
             . "X-Mailer: WiFIBER-Billing\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($email, "{$name} invoice {$invoice['number']}", $body, $headers);
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}

function send_invoice_reminder(array $invoice): array {
    $email = (string)($invoice['client_email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'client has no email'];
    }
    $billing = invoice_billing_settings();
    $site    = load_site_settings();
    $name    = (string)($site['name'] ?? 'WiFIBER');
    $support = (string)($site['email_accounts'] ?? $site['email_support'] ?? 'accounts@wifiber.co.za');
    $base    = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
    $url     = rtrim($base, '/') . '/account/invoices.php?id=' . (int)$invoice['id'];
    $days    = (int)floor((time() - strtotime((string)$invoice['due_at'])) / 86400);

    $body  = "Hi " . ($invoice['client_name'] ?: $invoice['username']) . ",\n\n";
    $body .= "Friendly reminder: invoice {$invoice['number']} is overdue ";
    $body .= "by {$days} day" . ($days === 1 ? '' : 's') . ".\n\n";
    $body .= "Total due: " . money((float)$invoice['total'], $billing['currency_symbol']) . "\n";
    $body .= "Due date:  {$invoice['due_at']}\n\n";

    if ($billing['bank_account_number']) {
        $body .= "Banking details\n";
        $body .= "Account holder: {$billing['bank_account_holder']}\n";
        $body .= "Bank:           {$billing['bank_name']}\n";
        $body .= "Account no:     {$billing['bank_account_number']}\n";
        if ($billing['bank_branch_code']) $body .= "Branch code:    {$billing['bank_branch_code']}\n";
        $body .= "Reference:      " . invoice_payment_reference($invoice, $billing) . "\n\n";
    }

    $body .= "View this invoice: {$url}\n\n";
    $body .= "If you've already paid, please reply with your proof of payment.\n\n";
    $body .= "— The {$name} team\n";

    $headers = "From: {$name} <no-reply@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'wifiber.co.za') . ">\r\n"
             . "Reply-To: {$support}\r\n"
             . "X-Mailer: WiFIBER-Billing\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($email, "Reminder: invoice {$invoice['number']} overdue", $body, $headers);
    if ($sent) {
        pdo()->prepare(
            "UPDATE invoices
                SET last_reminder_at = CURRENT_TIMESTAMP,
                    reminder_count   = reminder_count + 1
              WHERE id = ?"
        )->execute([(int)$invoice['id']]);
    }
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}

/* =========================================================================
 * Phase 28 — product-aware subscription, prorated changes, credit notes,
 * outstanding-balance helpers. Layers on top of invoices/invoice_items
 * without rewriting the existing helpers above.
 * ========================================================================= */

/**
 * Resolve the canonical subscription price for a customer.
 *
 * Order of preference:
 *   1. users.product_id → products row (the source of truth post-Phase 22)
 *   2. users.package text → pricing.json (legacy, kept for imports + leads)
 *
 * Returns the same shape package_price_lookup() does so callers don't
 * have to special-case which path won.
 */
function invoice_subscription_price_for_user(array $user): ?array {
    $pid = (int)($user['product_id'] ?? 0);
    if ($pid > 0) {
        $stmt = pdo()->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if ($p && (float)$p['monthly_price'] > 0) {
            return [
                'tier_key'   => (string)($p['tier_key'] ?? ''),
                'tier_name'  => (string)$p['name'],
                'down'       => (float)$p['down_mbps'],
                'up'         => (float)$p['up_mbps'],
                'price'      => (float)$p['monthly_price'], // inc-VAT
                'product_id' => (int)$p['id'],
            ];
        }
    }
    if (!empty($user['package'])) {
        $price = package_price_lookup((string)$user['package']);
        if ($price) return $price;
    }
    return null;
}

/**
 * The day of the month a customer should be billed on. Defaults to 1 if
 * unset so existing accounts keep their pre-Phase-28 schedule.
 *
 * Real months don't all have 31 days, so day-31 customers in February
 * fall back to the last day of the month.
 */
function invoice_billing_day_for_month(int $billing_day, string $month_iso): string {
    $billing_day = max(1, min(31, $billing_day ?: 1));
    $base = strtotime($month_iso);
    if ($base === false) $base = time();
    $year  = (int)date('Y', $base);
    $month = (int)date('n', $base);
    $last  = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $day   = min($billing_day, $last);
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Prorate a customer's mid-month product change.
 *
 * Workflow:
 *   1. Find the customer's open subscription invoice for the current period.
 *   2. Credit the unused days at the OLD price (negative line).
 *   3. Charge the unused days at the NEW price (positive line).
 *   4. Re-total the invoice.
 *
 * Idempotent within the same calendar day — a second call on the same day
 * with the same old/new price won't double-prorate.
 *
 * Returns ['credit' => float, 'debit' => float, 'net' => float] or null
 * if no current invoice exists (operator handles billing manually).
 */
function invoice_reprice_user(int $user_id, ?array $old_product, ?array $new_product, ?string $today_iso = null): ?array {
    if (!$old_product && !$new_product) return null;
    $today_iso = $today_iso ?: date('Y-m-d');
    $period_start = date('Y-m-01', strtotime($today_iso));

    $stmt = pdo()->prepare(
        "SELECT * FROM invoices
          WHERE user_id = ?
            AND period_start = ?
            AND status = 'unpaid'
          ORDER BY id DESC
          LIMIT 1"
    );
    $stmt->execute([$user_id, $period_start]);
    $inv = $stmt->fetch();
    if (!$inv) return null;

    $period_days   = (int)date('t', strtotime($period_start));
    $today_day     = (int)date('j', strtotime($today_iso));
    $remaining     = max(0, $period_days - $today_day + 1);
    if ($remaining <= 0) return null;

    $old_price = $old_product ? (float)$old_product['monthly_price'] : 0.0;
    $new_price = $new_product ? (float)$new_product['monthly_price'] : 0.0;

    // VAT rules of the existing invoice apply.
    $vat_rate = (float)$inv['vat_rate'];

    $factor = $remaining / $period_days;
    $credit_inc = round($old_price * $factor, 2);
    $debit_inc  = round($new_price * $factor, 2);

    if ($credit_inc <= 0 && $debit_inc <= 0) return null;

    $items = invoice_items((int)$inv['id']);

    $today = date('Y-m-d', strtotime($today_iso));
    $ext_credit = "Prorate credit ({$remaining}/{$period_days} days · {$today})";
    $ext_debit  = "Prorate charge ({$remaining}/{$period_days} days · {$today})";

    foreach ($items as $existing) {
        $d = (string)($existing['description'] ?? '');
        if ($d === $ext_credit || $d === $ext_debit) return null; // already done today
    }

    if ($credit_inc > 0 && $old_product) {
        $line = invoice_strip_vat($credit_inc, $vat_rate);
        $items[] = [
            'description' => mb_substr($ext_credit . ' — ' . $old_product['name'], 0, 200),
            'quantity'    => 1,
            'unit_price'  => -1 * $line,
        ];
    }
    if ($debit_inc > 0 && $new_product) {
        $line = invoice_strip_vat($debit_inc, $vat_rate);
        $items[] = [
            'description' => mb_substr($ext_debit . ' — ' . $new_product['name'], 0, 200),
            'quantity'    => 1,
            'unit_price'  => $line,
        ];
    }

    $clean = invoice_normalise_items($items);
    [$subtotal, $vat_amount, $total] = invoice_compute_totals($clean, $vat_rate);

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE invoices
                SET subtotal=?, vat_amount=?, total=?
              WHERE id=?"
        )->execute([$subtotal, $vat_amount, $total, (int)$inv['id']]);
        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([(int)$inv['id']]);
        invoice_insert_items((int)$inv['id'], $clean);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log('invoice.reprice', [
        'target_type' => 'invoice', 'target_id' => (int)$inv['id'],
        'meta' => [
            'remaining_days' => $remaining,
            'period_days'    => $period_days,
            'credit_inc'     => $credit_inc,
            'debit_inc'      => $debit_inc,
            'old_product_id' => $old_product['id'] ?? null,
            'new_product_id' => $new_product['id'] ?? null,
        ],
    ]);
    return [
        'invoice_id' => (int)$inv['id'],
        'credit'     => $credit_inc,
        'debit'      => $debit_inc,
        'net'        => $debit_inc - $credit_inc,
        'remaining'  => $remaining,
    ];
}

function invoice_strip_vat(float $inc_vat, float $vat_rate): float {
    if ($vat_rate <= 0) return round($inc_vat, 2);
    return round($inc_vat - ($inc_vat - ($inc_vat * 100 / (100 + $vat_rate))), 2);
}

/**
 * Sum of unpaid invoices for a customer, minus any unallocated credit
 * (open credit notes + payments without invoice_id).  Negative balance
 * means the customer is in credit.
 */
function invoice_outstanding_balance(int $user_id): array {
    $stmt = pdo()->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = ? AND status = 'unpaid'"
    );
    $stmt->execute([$user_id]);
    $unpaid = (float)$stmt->fetchColumn();

    $credit = 0.0;
    try {
        $stmt = pdo()->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM credit_notes WHERE user_id = ? AND status = 'open'"
        );
        $stmt->execute([$user_id]);
        $credit += (float)$stmt->fetchColumn();
    } catch (Throwable $e) { /* table may not exist yet */ }
    try {
        $stmt = pdo()->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM payments
              WHERE user_id = ?
                AND invoice_id IS NULL
                AND status = 'received'"
        );
        $stmt->execute([$user_id]);
        $credit += (float)$stmt->fetchColumn();
    } catch (Throwable $e) { /* table may not exist yet */ }

    return [
        'unpaid'  => round($unpaid, 2),
        'credit'  => round($credit, 2),
        'balance' => round($unpaid - $credit, 2),
    ];
}

/* ----------------------------------------------------------- credit notes */

function credit_note_format_number(int $id, string $issued_at_iso): string {
    $year = (int)substr($issued_at_iso, 0, 4) ?: (int)date('Y');
    return sprintf('CN-%04d-%05d', $year, $id);
}

function credit_note_create(int $user_id, float $amount, string $reason, ?int $invoice_id = null, ?int $created_by = null): int {
    if ($user_id <= 0)       throw new InvalidArgumentException('Pick a customer.');
    if ($amount <= 0)        throw new InvalidArgumentException('Amount must be positive.');
    if (!find_user_by_id($user_id)) throw new InvalidArgumentException('Customer not found.');

    $issued = date('Y-m-d');
    $temp   = 'TMP-' . bin2hex(random_bytes(6));
    $stmt = pdo()->prepare(
        "INSERT INTO credit_notes
            (number, user_id, invoice_id, amount, reason, status, issued_at, created_by)
         VALUES (?, ?, ?, ?, ?, 'open', ?, ?)"
    );
    $stmt->execute([
        $temp, $user_id, $invoice_id, round($amount, 2),
        mb_substr($reason, 0, 255), $issued, $created_by,
    ]);
    $id = (int)pdo()->lastInsertId();
    $num = credit_note_format_number($id, $issued);
    pdo()->prepare("UPDATE credit_notes SET number = ? WHERE id = ?")->execute([$num, $id]);
    audit_log('credit_note.create', [
        'target_type' => 'credit_note', 'target_id' => $id,
        'meta' => ['user_id' => $user_id, 'amount' => $amount, 'invoice_id' => $invoice_id],
    ]);
    return $id;
}

/**
 * Apply an open credit note to an invoice.  Records a payments row of
 * method='credit_note' and flips the credit note status to 'applied'. If
 * the invoice goes to zero or below, mark it paid.
 */
function credit_note_apply(int $credit_note_id, int $invoice_id, ?int $applied_by = null): array {
    $cn = pdo()->prepare("SELECT * FROM credit_notes WHERE id = ? LIMIT 1");
    $cn->execute([$credit_note_id]);
    $note = $cn->fetch();
    if (!$note)                     throw new RuntimeException('Credit note not found.');
    if ($note['status'] !== 'open') throw new RuntimeException('Credit note is not open.');

    $inv = invoice_find($invoice_id);
    if (!$inv)                                  throw new RuntimeException('Invoice not found.');
    if ((int)$inv['user_id'] !== (int)$note['user_id']) {
        throw new InvalidArgumentException('Credit note belongs to a different customer.');
    }
    if ($inv['status'] === 'paid')              throw new RuntimeException('Invoice is already paid.');

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO payments
                (user_id, invoice_id, method, amount, currency, reference,
                 status, received_at, source, recorded_by, notes)
             VALUES (?, ?, 'credit_note', ?, ?, ?, 'received', NOW(), 'credit_note', ?, ?)"
        )->execute([
            (int)$note['user_id'], $invoice_id, (float)$note['amount'],
            (string)($note['currency'] ?? 'ZAR'),
            (string)$note['number'],
            $applied_by,
            'Applied credit note ' . (string)$note['number'],
        ]);

        $pdo->prepare(
            "UPDATE credit_notes
                SET status='applied', invoice_id=?, applied_at=NOW()
              WHERE id=?"
        )->execute([$invoice_id, $credit_note_id]);

        // Mark invoice paid if the credit covers it. Payments aren't yet
        // partial-aware, so the simple rule: if total credits + payments
        // ≥ invoice total, status = paid.
        $sum = (float)$pdo->query(
            "SELECT COALESCE(SUM(amount), 0)
               FROM payments
              WHERE invoice_id = " . (int)$invoice_id . "
                AND status = 'received'"
        )->fetchColumn();
        if ($sum + 0.005 >= (float)$inv['total']) {
            $pdo->prepare(
                "UPDATE invoices SET status='paid', paid_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$invoice_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log('credit_note.apply', [
        'target_type' => 'credit_note', 'target_id' => $credit_note_id,
        'meta' => ['invoice_id' => $invoice_id, 'amount' => (float)$note['amount']],
    ]);
    return ['credit_note_id' => $credit_note_id, 'invoice_id' => $invoice_id];
}

function credit_note_void(int $credit_note_id): bool {
    $ok = pdo()->prepare(
        "UPDATE credit_notes SET status='void' WHERE id=? AND status='open'"
    )->execute([$credit_note_id]);
    if ($ok) audit_log('credit_note.void', ['target_type' => 'credit_note', 'target_id' => $credit_note_id]);
    return $ok;
}

function credit_notes_for_user(int $user_id): array {
    $stmt = pdo()->prepare(
        "SELECT * FROM credit_notes WHERE user_id = ? ORDER BY issued_at DESC, id DESC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function credit_notes_all(?string $status = null): array {
    $sql = "SELECT cn.*, u.username, u.name AS client_name
              FROM credit_notes cn
              LEFT JOIN users u ON u.id = cn.user_id";
    $args = [];
    if ($status && in_array($status, ['open','applied','void'], true)) {
        $sql .= " WHERE cn.status = ?";
        $args[] = $status;
    }
    $sql .= " ORDER BY cn.issued_at DESC, cn.id DESC";
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}
