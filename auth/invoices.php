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
    if (empty($u['package']))                  return null;

    $price = package_price_lookup((string)$u['package']);
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
        pdo()->prepare("UPDATE invoices SET last_reminder_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([(int)$invoice['id']]);
    }
    return ['ok' => (bool)$sent, 'reason' => $sent ? 'sent' : 'mail() failed'];
}
