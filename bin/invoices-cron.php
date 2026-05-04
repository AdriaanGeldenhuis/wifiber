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
if (is_file(__DIR__ . '/../auth/radius.php')) {
    require __DIR__ . '/../auth/radius.php';
}

const DUNNING_SUSPEND_DAYS = 14; // overdue ≥ this many days → auto-suspend via RADIUS

$opts = [
    'period'        => date('Y-m-01'),
    'no-generate'   => false,
    'no-reminders'  => false,
    'no-suspend'    => false,
    'dry-run'       => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-generate'  || $arg === '--skip-generate')  { $opts['no-generate']  = true; continue; }
    if ($arg === '--no-reminders' || $arg === '--skip-reminders') { $opts['no-reminders'] = true; continue; }
    if ($arg === '--no-suspend'   || $arg === '--skip-suspend')   { $opts['no-suspend']   = true; continue; }
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
    // Bill on each customer's billing_day (default 1).  When --period is
    // forced, generate for that period regardless of which day the cron
    // is running on.  Otherwise only bill clients whose billing_day
    // matches today, so a daily cron writes the right invoices.
    $today_day  = (int)date('j');
    $force_all  = (bool)($_SERVER['argv'][1] ?? false) && in_array('--all-clients', $argv, true);
    $clients = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client' && ($u['status'] ?? 'active') !== 'disconnected'));

    $generated = 0; $skipped_no_pkg = 0; $skipped_no_price = 0; $skipped_exists = 0;
    $skipped_wrong_day = 0; $emailed = 0;

    foreach ($clients as $u) {
        $billing_day = (int)($u['billing_day'] ?? 0);
        if ($billing_day < 1) $billing_day = 1;
        // Last-day-of-month edge case: a day-31 customer in February
        // bills on the last day of the month so they don't get skipped.
        $period_days = (int)date('t');
        $effective_day = min($billing_day, $period_days);

        $period = $opts['period'];
        if (!in_array('--period=' . $period, $argv, true) && !$force_all) {
            // No explicit --period: only run on the customer's day.
            if ($effective_day !== $today_day) { $skipped_wrong_day++; continue; }
            $period = invoice_billing_day_for_month($billing_day, date('Y-m-01'));
        }

        $price = invoice_subscription_price_for_user($u);
        if (!$price) {
            // Try the legacy package text as a last resort so accounts
            // imported without a product still get billed.
            $pkg = trim((string)($u['package'] ?? ''));
            if ($pkg === '') { $skipped_no_pkg++; continue; }
            $price = package_price_lookup($pkg);
            if (!$price) { echo "  ! no price for {$u['username']} (package='{$pkg}', product_id=" . (int)($u['product_id'] ?? 0) . ")\n"; $skipped_no_price++; continue; }
        }

        // Already invoiced for this period?
        $check = pdo()->prepare("SELECT id FROM invoices WHERE user_id = ? AND period_start = ? LIMIT 1");
        $check->execute([(int)$u['id'], $period]);
        if ($check->fetchColumn()) { $skipped_exists++; continue; }

        if ($opts['dry-run']) {
            echo "  [dry-run] would invoice {$u['username']} for "
               . ($price['tier_name'] ?? '') . " @ R" . number_format($price['price'], 2)
               . " (period $period)\n";
            continue;
        }

        try {
            $inv_id = invoice_subscription_create_for_user((int)$u['id'], $period);
            if ($inv_id) {
                $generated++;
                $inv = invoice_find($inv_id);
                echo "  + {$inv['number']}: {$u['username']} = " . number_format((float)$inv['total'], 2) . "\n";
                $r = send_invoice_email($inv);
                if ($r['ok']) $emailed++;
                else          echo "    (email failed: {$r['reason']})\n";
            }
        } catch (Throwable $e) {
            echo "  ! error for {$u['username']}: {$e->getMessage()}\n";
        }
    }

    echo sprintf(
        "Result: %d generated, %d emailed, %d already-invoiced, %d wrong-day, %d no-package, %d unmatched-package.\n",
        $generated, $emailed, $skipped_exists, $skipped_wrong_day, $skipped_no_pkg, $skipped_no_price
    );
}

/* -------------------------------------------------- 2) overdue reminders */

if (!$opts['no-reminders']) {
    echo "\n--- dunning reminders (T+3 / T+7 / T+14) ---\n";
    // Ladder: send the first reminder on day-3 overdue (reminder_count=0),
    // the final notice on day-7 (reminder_count=1).  Day-14 suspend is
    // handled by the dunning-suspend block below; it doesn't email.
    $stmt = pdo()->prepare(
        "SELECT i.*, u.username, u.name AS client_name, u.email AS client_email,
                DATEDIFF(CURDATE(), i.due_at) AS days_overdue
         FROM invoices i
         LEFT JOIN users u ON u.id = i.user_id
         WHERE i.status = 'unpaid'
           AND i.due_at < CURDATE()
           AND (
                 (i.reminder_count = 0 AND DATEDIFF(CURDATE(), i.due_at) >= 3)
              OR (i.reminder_count = 1 AND DATEDIFF(CURDATE(), i.due_at) >= 7)
           )
         ORDER BY i.due_at ASC"
    );
    $stmt->execute();
    $overdue = $stmt->fetchAll();
    echo count($overdue) . " invoice(s) on the dunning ladder.\n";

    $sent = 0; $failed = 0;
    foreach ($overdue as $inv) {
        $stage = ((int)$inv['reminder_count'] === 0) ? 'reminder' : 'final notice';
        if ($opts['dry-run']) {
            echo "  [dry-run] would send {$stage} for {$inv['number']} → {$inv['username']} ({$inv['days_overdue']}d overdue)\n";
            continue;
        }
        $r = send_invoice_reminder($inv);
        if ($r['ok']) {
            echo "  + {$stage}: {$inv['number']} → {$inv['client_email']} ({$inv['days_overdue']}d)\n";
            $sent++;
        } else {
            echo "  ! {$stage} failed: {$inv['number']} ({$r['reason']})\n";
            $failed++;
        }
    }
    echo "Result: {$sent} sent, {$failed} failed.\n";
}

/* ----------------------------------------------------- 3) dunning auto-suspend */

if (!$opts['no-suspend'] && function_exists('radius_suspend')) {
    echo "\n--- dunning auto-suspend (≥ " . DUNNING_SUSPEND_DAYS . "d overdue) ---\n";
    $cutoff = date('Y-m-d', time() - DUNNING_SUSPEND_DAYS * 86400);
    $stmt = pdo()->prepare(
        "SELECT DISTINCT i.user_id, u.username, u.status,
                MIN(i.due_at) AS oldest_due
           FROM invoices i
           JOIN users u ON u.id = i.user_id
          WHERE i.status = 'unpaid'
            AND i.due_at <= ?
            AND u.role   = 'client'
            AND u.status = 'active'
          GROUP BY i.user_id, u.username, u.status"
    );
    $stmt->execute([$cutoff]);
    $delinquent = $stmt->fetchAll();
    echo count($delinquent) . " active customer(s) past the suspend threshold.\n";

    $suspended = 0;
    foreach ($delinquent as $d) {
        if ($opts['dry-run']) {
            echo "  [dry-run] would suspend {$d['username']} (oldest unpaid {$d['oldest_due']})\n";
            continue;
        }
        try {
            update_user((int)$d['user_id'], function (array $u) {
                $u['status'] = 'suspended';
                return $u;
            });
            radius_suspend((int)$d['user_id'], 'overdue ≥ ' . DUNNING_SUSPEND_DAYS . 'd');
            audit_log('billing.auto_suspend', [
                'target_type' => 'user', 'target_id' => (int)$d['user_id'],
                'meta' => ['oldest_due' => (string)$d['oldest_due']],
            ]);
            echo "  ! suspended {$d['username']} (oldest unpaid {$d['oldest_due']})\n";
            $suspended++;
        } catch (Throwable $e) {
            echo "  ! suspend failed for {$d['username']}: {$e->getMessage()}\n";
        }
    }
    echo "Result: {$suspended} suspended.\n";
}

echo "\n[invoices-cron] done.\n";
