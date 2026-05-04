<?php
/**
 * Splynx (legacy WISP billing platform) importer.
 *
 * Reads Splynx's REST API and upserts into our schema:
 *
 *   tariffs/internet           → products
 *   admin/customers/customer   → users (role=client)
 *   admin/customers/customer-internet-services
 *                              → users.product_id (linked via tariff_id)
 *   admin/finance/invoices     → invoices + invoice_items
 *   admin/finance/payments     → payments
 *
 * Idempotency: every row is keyed on (external_src='splynx',
 * external_ref=Splynx id). Re-running updates in place; manual rows are
 * left alone.
 *
 * Usage:
 *   php bin/import-splynx.php --base-url=https://splynx.example.com \
 *                              --user=API_KEY --pass=API_SECRET \
 *                              [--dry-run] [--limit=N] \
 *                              [--only=tariffs,customers,services,invoices,payments]
 *
 * Splynx API key + secret get exchanged for a session token via
 * /api/2.0/admin/auth/tokens. We simplify by using basic-auth — Splynx
 * accepts that on every endpoint when the API key is enabled in
 * Administration → API keys.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/importers.php';
require __DIR__ . '/../auth/products.php';
require __DIR__ . '/../auth/invoices.php';
require __DIR__ . '/../auth/payments.php';

const SPLYNX_RESOURCES = ['tariffs', 'customers', 'services', 'invoices', 'payments'];

$opts = [
    'dry-run'  => false,
    'limit'    => 0,
    'base-url' => '',
    'user'     => '',
    'pass'     => '',
    'only'     => SPLYNX_RESOURCES,
];
$rest = importer_parse_common_args($argv, $opts);
foreach ($rest as $a) { fwrite(STDERR, "unknown arg: $a\n"); exit(2); }

if ($opts['base-url'] === '' || $opts['user'] === '' || $opts['pass'] === '') {
    fwrite(STDERR, "usage: import-splynx.php --base-url=https://… --user=API_KEY --pass=API_SECRET [--dry-run] [--limit=N] [--only=...]\n");
    exit(2);
}
$opts['only'] = array_values(array_intersect($opts['only'], SPLYNX_RESOURCES));

$prefix = rtrim($opts['base-url'], '/') . '/api/2.0';
$auth   = ['basic' => ['user' => $opts['user'], 'pass' => $opts['pass']]];

echo "[splynx] base={$opts['base-url']} resources=" . implode(',', $opts['only']) . ($opts['dry-run'] ? ' (DRY RUN)' : '') . "\n";

if (in_array('tariffs',   $opts['only'], true)) splynx_import_tariffs($prefix,   $auth, $opts);
if (in_array('customers', $opts['only'], true)) splynx_import_customers($prefix, $auth, $opts);
if (in_array('services',  $opts['only'], true)) splynx_import_services($prefix,  $auth, $opts);
if (in_array('invoices',  $opts['only'], true)) splynx_import_invoices($prefix,  $auth, $opts);
if (in_array('payments',  $opts['only'], true)) splynx_import_payments($prefix,  $auth, $opts);

echo "[splynx] done.\n";

/* ============================================================ functions */

function splynx_import_tariffs(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('splynx', 'tariffs', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- tariffs (internet) ---\n";

    $r = importer_http_get_json($prefix . '/admin/tariffs/internet', $auth);
    if (!$r['ok']) { echo "  ! fetch failed: {$r['error']}\n"; importer_run_end($run, $c->as_array(), $r['error']); return; }
    $rows = is_array($r['data']) ? $r['data'] : [];
    if ($opts['limit'] > 0) $rows = array_slice($rows, 0, $opts['limit']);

    foreach ($rows as $t) {
        $c->total++;
        $ref = (string)($t['id'] ?? '');
        if ($ref === '') { $c->failed++; continue; }

        $values = [
            'name'          => mb_substr((string)($t['title'] ?? 'Splynx tariff ' . $ref), 0, 120),
            'tier_key'      => 'home',
            'down_mbps'     => splynx_kbits_to_mbps($t['speed_download'] ?? null),
            'up_mbps'       => splynx_kbits_to_mbps($t['speed_upload']   ?? null),
            'monthly_price' => (float)($t['price'] ?? 0),
            'install_24mo'  => 0,
            'install_mtm'   => 0,
            'is_active'     => empty($t['enabled']) ? 0 : 1,
            'sort_order'    => 500,
            'description'   => mb_substr((string)($t['description'] ?? ''), 0, 1000),
        ];

        try {
            $res = importer_upsert_external_ref('products', 'splynx', $ref, $values, $opts['dry-run']);
            $c->note($res['change']);
            if ($res['change'] !== 'noop') echo "  + {$res['change']}: {$values['name']} (R{$values['monthly_price']}, {$values['down_mbps']}/{$values['up_mbps']} Mbps)\n";
        } catch (Throwable $e) {
            echo "  ! tariff $ref: {$e->getMessage()}\n";
            $c->failed++;
        }
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function splynx_kbits_to_mbps($v): float {
    if ($v === null || $v === '') return 0.0;
    return round((float)$v / 1024.0, 2); // Splynx stores kbits/s
}

function splynx_import_customers(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('splynx', 'customers', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- customers ---\n";

    // Pull paginated. Splynx returns up to 100 per page by default; we
    // walk pages until we get fewer than 100 back.
    $page = 1;
    $seen = 0;
    while (true) {
        $r = importer_http_get_json($prefix . '/admin/customers/customer?page=' . $page . '&per_page=100', $auth);
        if (!$r['ok']) { echo "  ! fetch failed: {$r['error']}\n"; importer_run_end($run, $c->as_array(), $r['error']); return; }
        $rows = is_array($r['data']) ? $r['data'] : [];
        if (!$rows) break;

        foreach ($rows as $cust) {
            $c->total++;
            $ref = (string)($cust['id'] ?? '');
            if ($ref === '') { $c->failed++; continue; }

            $existing = importer_find_by_external_ref('users', 'splynx', $ref);

            $username = preg_replace('/[^a-z0-9._-]+/i', '', strtolower((string)($cust['login'] ?? 'splynx_' . $ref)));
            if ($username === '') $username = 'splynx_' . $ref;
            $name     = (string)($cust['name'] ?? $cust['full_name'] ?? '');
            $surname  = '';
            if (str_contains($name, ' ')) {
                [$first, $surname] = explode(' ', $name, 2);
                $name = $first;
            }
            $status_map = [
                'new'       => 'lead',
                'active'    => 'active',
                'blocked'   => 'suspended',
                'disabled'  => 'disconnected',
                'inactive'  => 'disconnected',
            ];
            $status = $status_map[strtolower((string)($cust['status'] ?? ''))] ?? 'lead';

            $values = [
                'username'        => $existing['username'] ?? mb_substr($username, 0, 60),
                'role'            => 'client',
                'customer_type'   => (string)($cust['category'] ?? '') === 'company' ? 'business' : 'residential',
                'name'            => mb_substr($name ?: $username, 0, 100),
                'surname'         => mb_substr($surname, 0, 60),
                'email'           => mb_substr((string)($cust['email'] ?? ''), 0, 120),
                'phone'           => mb_substr((string)($cust['phone'] ?? ''), 0, 40),
                'address'         => mb_substr(trim((string)($cust['street_1'] ?? '') . ' ' . (string)($cust['city'] ?? '')), 0, 200),
                'status'          => $status,
                'billing_day'     => (int)($cust['billing_day'] ?? 1) ?: 1,
                'service_start'   => splynx_date($cust['date_add'] ?? null),
                'notes'           => mb_substr((string)($cust['additional_information'] ?? ''), 0, 1000),
            ];
            if (!$existing) {
                // First-time import: mint a random password and account
                // number so the row is well-formed; the customer will
                // reset their password via the welcome flow later.
                $values['password_hash'] = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                $values['account_no']    = generate_account_no($values['surname'] ?: 'IMP');
            }

            try {
                $res = importer_upsert_external_ref('users', 'splynx', $ref, $values, $opts['dry-run'],
                    !$existing && $values['email'] !== '' ? ['email' => $values['email']] : null);
                $c->note($res['change']);
                if ($res['change'] !== 'noop') echo "  + {$res['change']}: {$values['name']} ({$values['username']}, splynx #$ref)\n";
            } catch (Throwable $e) {
                echo "  ! customer $ref: {$e->getMessage()}\n";
                $c->failed++;
            }
            $seen++;
            if ($opts['limit'] > 0 && $seen >= $opts['limit']) break 2;
        }
        if (count($rows) < 100) break;
        $page++;
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function splynx_date($v): ?string {
    if (!$v) return null;
    $ts = strtotime((string)$v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function splynx_import_services(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('splynx', 'services', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- internet services (link customer → tariff) ---\n";

    $page = 1; $seen = 0;
    while (true) {
        $r = importer_http_get_json($prefix . '/admin/customers/customer-internet-services?page=' . $page . '&per_page=100', $auth);
        if (!$r['ok']) { echo "  ! fetch failed: {$r['error']}\n"; importer_run_end($run, $c->as_array(), $r['error']); return; }
        $rows = is_array($r['data']) ? $r['data'] : [];
        if (!$rows) break;

        foreach ($rows as $svc) {
            $c->total++;
            $cust_ref = (string)($svc['customer_id'] ?? '');
            $tariff_ref = (string)($svc['tariff_id']  ?? '');
            $user_row = importer_find_by_external_ref('users',    'splynx', $cust_ref);
            $prod_row = importer_find_by_external_ref('products', 'splynx', $tariff_ref);
            if (!$user_row || !$prod_row) { $c->skipped++; continue; }

            $values = [
                'product_id' => (int)$prod_row['id'],
                'package'    => (string)$prod_row['name'],
            ];
            try {
                pdo()->prepare("UPDATE users SET product_id = ?, package = ? WHERE id = ?")
                     ->execute([(int)$prod_row['id'], (string)$prod_row['name'], (int)$user_row['id']]);
                $c->updated++;
                if (!$opts['dry-run']) echo "  + linked {$user_row['username']} → {$prod_row['name']}\n";
            } catch (Throwable $e) {
                echo "  ! service $cust_ref/$tariff_ref: {$e->getMessage()}\n";
                $c->failed++;
            }
            $seen++;
            if ($opts['limit'] > 0 && $seen >= $opts['limit']) break 2;
        }
        if (count($rows) < 100) break;
        $page++;
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function splynx_import_invoices(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('splynx', 'invoices', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- invoices ---\n";

    $page = 1; $seen = 0;
    while (true) {
        $r = importer_http_get_json($prefix . '/admin/finance/invoices?page=' . $page . '&per_page=100', $auth);
        if (!$r['ok']) { echo "  ! fetch failed: {$r['error']}\n"; importer_run_end($run, $c->as_array(), $r['error']); return; }
        $rows = is_array($r['data']) ? $r['data'] : [];
        if (!$rows) break;

        foreach ($rows as $inv) {
            $c->total++;
            $ref      = (string)($inv['id'] ?? '');
            $cust_ref = (string)($inv['customer_id'] ?? '');
            $user_row = importer_find_by_external_ref('users', 'splynx', $cust_ref);
            if (!$user_row) { $c->skipped++; continue; }

            $status = match (strtolower((string)($inv['status'] ?? ''))) {
                'paid'      => 'paid',
                'cancelled' => 'cancelled',
                default     => 'unpaid',
            };
            $issued_at = splynx_date($inv['date_created'] ?? null) ?? date('Y-m-d');
            $due_at    = splynx_date($inv['date_till']    ?? null) ?? $issued_at;
            $total     = (float)($inv['total'] ?? 0);
            $vat_rate  = (float)($inv['vat_percent'] ?? 0);
            $vat_amt   = (float)($inv['vat_amount']  ?? 0);
            $subtotal  = round($total - $vat_amt, 2);
            $period    = splynx_date($inv['date_from'] ?? null) ?? $issued_at;

            $values = [
                'number'         => mb_substr((string)($inv['number'] ?? ('SPL-' . $ref)), 0, 40),
                'user_id'        => (int)$user_row['id'],
                'status'         => $status,
                'issued_at'      => $issued_at,
                'due_at'         => $due_at,
                'period_start'   => $period,
                'subtotal'       => $subtotal,
                'vat_rate'       => $vat_rate,
                'vat_amount'     => $vat_amt,
                'total'          => $total,
                'notes'          => mb_substr((string)($inv['memo'] ?? ''), 0, 1000),
            ];
            if ($status === 'paid') {
                $values['paid_at'] = $values['issued_at'] . ' 00:00:00';
            }

            try {
                $res = importer_upsert_external_ref('invoices', 'splynx', $ref, $values, $opts['dry-run']);
                $c->note($res['change']);
                if ($res['change'] !== 'noop') echo "  + {$res['change']}: {$values['number']} (R{$total}, {$status})\n";
            } catch (Throwable $e) {
                echo "  ! invoice $ref: {$e->getMessage()}\n";
                $c->failed++;
            }
            $seen++;
            if ($opts['limit'] > 0 && $seen >= $opts['limit']) break 2;
        }
        if (count($rows) < 100) break;
        $page++;
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}

function splynx_import_payments(string $prefix, array $auth, array $opts): void {
    $run = importer_run_begin('splynx', 'payments', $opts['dry-run']);
    $c   = new ImporterCounters();
    echo "\n--- payments ---\n";

    $page = 1; $seen = 0;
    while (true) {
        $r = importer_http_get_json($prefix . '/admin/finance/payments?page=' . $page . '&per_page=100', $auth);
        if (!$r['ok']) { echo "  ! fetch failed: {$r['error']}\n"; importer_run_end($run, $c->as_array(), $r['error']); return; }
        $rows = is_array($r['data']) ? $r['data'] : [];
        if (!$rows) break;

        foreach ($rows as $pay) {
            $c->total++;
            $ref      = (string)($pay['id'] ?? '');
            $cust_ref = (string)($pay['customer_id'] ?? '');
            $user_row = importer_find_by_external_ref('users', 'splynx', $cust_ref);
            if (!$user_row) { $c->skipped++; continue; }

            $invoice_id = null;
            $inv_ref = (string)($pay['invoice_id'] ?? '');
            if ($inv_ref !== '' && $inv_ref !== '0') {
                $inv_row = importer_find_by_external_ref('invoices', 'splynx', $inv_ref);
                if ($inv_row) $invoice_id = (int)$inv_row['id'];
            }

            $method = match (strtolower((string)($pay['payment_type'] ?? ''))) {
                'cash'                                  => 'cash',
                'bank_transfer', 'eft'                  => 'eft',
                'debit', 'debit_order', 'recurring'     => 'debit_order',
                'credit_card', 'card', 'visa', 'master' => 'card',
                default                                 => 'other',
            };

            $payload = [
                'user_id'     => (int)$user_row['id'],
                'invoice_id'  => $invoice_id,
                'method'      => $method,
                'amount'      => (float)($pay['amount'] ?? 0),
                'currency'    => 'ZAR',
                'reference'   => mb_substr((string)($pay['memo'] ?? $pay['receipt_number'] ?? ''), 0, 120),
                'external_id' => 'splynx:' . $ref,
                'status'      => 'received',
                'received_at' => splynx_date($pay['date'] ?? null) . ' 12:00:00',
                'notes'       => 'Imported from Splynx',
                'source'      => 'api',
                'source_meta' => $pay,
            ];

            // Use the dedicated payment_record so the invoice gets settled
            // automatically. payment_record is itself idempotent on
            // (method, external_id) so a re-run is a no-op.
            try {
                if ($opts['dry-run']) {
                    $c->created++;
                    echo "  [dry-run] would record R{$payload['amount']} for {$user_row['username']} ({$method})\n";
                } else {
                    payment_record($payload, null);
                    $c->created++;
                }
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'already on file')) {
                    $c->skipped++;
                } else {
                    echo "  ! payment $ref: {$e->getMessage()}\n";
                    $c->failed++;
                }
            } catch (Throwable $e) {
                echo "  ! payment $ref: {$e->getMessage()}\n";
                $c->failed++;
            }
            $seen++;
            if ($opts['limit'] > 0 && $seen >= $opts['limit']) break 2;
        }
        if (count($rows) < 100) break;
        $page++;
    }
    echo "  → " . $c->summary() . "\n";
    importer_run_end($run, $c->as_array());
}
