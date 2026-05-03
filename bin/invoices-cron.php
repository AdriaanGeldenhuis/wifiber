<?php
/**
 * Daily invoicing cron.
 *
 * Two jobs in one script:
 *
 *   1. Subscription auto-billing
 *      On the 1st of every month, generate one invoice per client whose
 *      `package` matches a row in data/pricing.json. Idempotent: re-running
 *      on the same day skips clients that already have an invoice for the
 *      current period_start.
 *
 *   2. Overdue reminders
 *      Find unpaid invoices whose due date has passed and that haven't had
 *      a reminder sent in the last 7 days, and email a friendly reminder.
 *
 * Recommended cron entry (xneelo / cPanel — runs once a day at 06:00):
 *
 *   0 6 * * *  /usr/bin/php /usr/home/wifibfjedj/public_html/bin/invoices-cron.php >> ~/invoices.log 2>&1
 *
 * Flags:
 *   --period=YYYY-MM-DD   force a specific period_start (default: 1st of current month)
 *   --no-generate         skip subscription generation
 *   --no-reminders        skip sending overdue reminders
 *   --dry-run             print what would happen without writing or emailing
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth/invoices.php';

$opts = [
    'period'        => date('Y-m-01'),
    'no-generate'   => false,
    'no-reminders'  => false,
    'dry-run'       => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-generate'  || $arg === '--skip-generate')  { $opts['no-generate']  = true; continue; }
    if ($arg === '--no-reminders' || $arg === '--skip-reminders') { $opts['no-reminders'] = true; continue; }
    if ($arg === '--dry-run')                                     { $opts['dry-run']      = true; continue; }
    if (strncmp($arg, '--period=', 9) === 0) {
        $opts['period'] = substr($arg, 9);
        if (!invoice_valid_date($opts['period'])) {
            fwrite(STDERR, "invalid --period date\n"); exit(2);
        }
        continue;
    }
    fwrite(STDERR, "unknown arg: $arg\n"); exit(2);
}

$today = date('Y-m-d');
echo "[invoices-cron] {$today}  period={$opts['period']}" . ($opts['dry-run'] ? '  (DRY RUN)' : '') . "\n";

/* -------------------------------------------------- 1) auto-generation */

if (!$opts['no-generate']) {
    echo "\n--- subscription auto-billing ---\n";
    $clients = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client'));
    $generated = 0; $skipped_no_pkg = 0; $skipped_no_price = 0; $skipped_exists = 0; $emailed = 0;

    foreach ($clients as $u) {
        $pkg = trim((string)($u['package'] ?? ''));
        if ($pkg === '') { $skipped_no_pkg++; continue; }
        $price = package_price_lookup($pkg);
        if (!$price) { echo "  ! no price match: {$u['username']} package='{$pkg}'\n"; $skipped_no_price++; continue; }

        // Already invoiced for this period?
        $check = pdo()->prepare("SELECT id FROM invoices WHERE user_id = ? AND period_start = ? LIMIT 1");
        $check->execute([(int)$u['id'], $opts['period']]);
        if ($check->fetchColumn()) { $skipped_exists++; continue; }

        if ($opts['dry-run']) {
            echo "  [dry-run] would invoice {$u['username']} for {$pkg} @ R" . number_format($price['price'], 2) . "\n";
            continue;
        }

        try {
            $inv_id = invoice_subscription_create_for_user((int)$u['id'], $opts['period']);
            if ($inv_id) {
                $generated++;
                $inv = invoice_find($inv_id);
                echo "  + {$inv['number']}: {$u['username']} {$pkg} = " . number_format((float)$inv['total'], 2) . "\n";
                $r = send_invoice_email($inv);
                if ($r['ok']) $emailed++;
                else          echo "    (email failed: {$r['reason']})\n";
            }
        } catch (Throwable $e) {
            echo "  ! error for {$u['username']}: {$e->getMessage()}\n";
        }
    }

    echo sprintf(
        "Result: %d generated, %d emailed, %d already-invoiced, %d no-package, %d unmatched-package.\n",
        $generated, $emailed, $skipped_exists, $skipped_no_pkg, $skipped_no_price
    );
}

/* -------------------------------------------------- 2) overdue reminders */

if (!$opts['no-reminders']) {
    echo "\n--- overdue reminders ---\n";
    $cutoff = date('Y-m-d H:i:s', time() - INVOICE_REMINDER_INTERVAL);
    $stmt = pdo()->prepare(
        "SELECT i.*, u.username, u.name AS client_name, u.email AS client_email
         FROM invoices i
         LEFT JOIN users u ON u.id = i.user_id
         WHERE i.status = 'unpaid'
           AND i.due_at < CURDATE()
           AND (i.last_reminder_at IS NULL OR i.last_reminder_at < ?)
         ORDER BY i.due_at ASC"
    );
    $stmt->execute([$cutoff]);
    $overdue = $stmt->fetchAll();
    echo count($overdue) . " invoice(s) need a reminder.\n";

    $sent = 0; $failed = 0;
    foreach ($overdue as $inv) {
        if ($opts['dry-run']) {
            echo "  [dry-run] would remind {$inv['username']} about {$inv['number']} (due {$inv['due_at']})\n";
            continue;
        }
        $r = send_invoice_reminder($inv);
        if ($r['ok']) {
            echo "  + reminder sent: {$inv['number']} → {$inv['client_email']}\n";
            $sent++;
        } else {
            echo "  ! reminder failed: {$inv['number']} ({$r['reason']})\n";
            $failed++;
        }
    }
    echo "Result: {$sent} sent, {$failed} failed.\n";
}

echo "\n[invoices-cron] done.\n";
