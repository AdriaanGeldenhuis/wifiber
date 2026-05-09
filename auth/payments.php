<?php
/**
 * Payment ledger helpers.
 *
 * The payments table records every cash-in event — manual EFT capture,
 * a debit-order receipt, a bank-CSV import row, or a gateway callback.
 * A payment row may or may not be tied to an invoice — unallocated
 * payments are "money on account" the operator can later apply.
 *
 * Marking an invoice paid is a side effect of recording a payment that
 * covers the invoice total: payment_record() does both atomically.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/invoices.php';

const PAYMENT_METHODS = [
    'eft', 'debit_order', 'cash', 'card',
    'payfast', 'yoco', 'stripe', 'credit_note', 'other',
];
const PAYMENT_STATUSES = ['pending', 'received', 'refunded', 'failed'];
const PAYMENT_SOURCES  = ['manual', 'bank_csv', 'gateway', 'api', 'credit_note'];

/* --------------------------------------------------------------- queries */

function payment_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function payments_for_user(int $user_id, int $limit = 200): array {
    $limit = max(1, min(2000, $limit));
    $stmt = pdo()->prepare(
        "SELECT * FROM payments WHERE user_id = ? ORDER BY received_at DESC, id DESC LIMIT $limit"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function payments_for_invoice(int $invoice_id): array {
    $stmt = pdo()->prepare(
        "SELECT * FROM payments WHERE invoice_id = ? ORDER BY received_at ASC, id ASC"
    );
    $stmt->execute([$invoice_id]);
    return $stmt->fetchAll();
}

function payments_all(?array $filters = null): array {
    $sql = "SELECT p.*, u.username, u.name AS client_name, i.number AS invoice_number
              FROM payments p
              LEFT JOIN users    u ON u.id = p.user_id
              LEFT JOIN invoices i ON i.id = p.invoice_id";
    $where = [];
    $args  = [];
    $f = $filters ?? [];
    if (!empty($f['method']) && in_array($f['method'], PAYMENT_METHODS, true)) {
        $where[] = "p.method = ?"; $args[] = $f['method'];
    }
    if (!empty($f['status']) && in_array($f['status'], PAYMENT_STATUSES, true)) {
        $where[] = "p.status = ?"; $args[] = $f['status'];
    }
    if (!empty($f['from'])) { $where[] = "p.received_at >= ?"; $args[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))   { $where[] = "p.received_at <= ?"; $args[] = $f['to']   . ' 23:59:59'; }
    if (!empty($f['user_id'])) { $where[] = "p.user_id = ?"; $args[] = (int)$f['user_id']; }
    if (!empty($f['unallocated'])) { $where[] = "p.invoice_id IS NULL AND p.status = 'received'"; }
    if (!empty($f['search'])) {
        $like = '%' . $f['search'] . '%';
        $where[] = '(p.reference LIKE ? OR p.external_id LIKE ? OR u.username LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.received_at DESC, p.id DESC LIMIT 500';
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------- mutations */

/**
 * Record a payment.  When invoice_id is supplied, this also flips the
 * invoice to status='paid' if total payments now cover the invoice
 * total.  Source and source_meta let the importer / gateway record
 * tagging without polluting the user-facing reference field.
 *
 * Idempotency: if external_id is supplied, a unique key on (method,
 * external_id) blocks duplicates so a re-played gateway IPN won't
 * double-credit the customer.
 */
function payment_record(array $data, ?int $recorded_by = null): int {
    $user_id    = (int)($data['user_id']    ?? 0);
    $invoice_id = $data['invoice_id'] ?? null;
    $invoice_id = ($invoice_id !== null && (int)$invoice_id > 0) ? (int)$invoice_id : null;
    $method     = in_array($data['method'] ?? '', PAYMENT_METHODS, true) ? $data['method'] : 'eft';
    $amount     = round((float)($data['amount'] ?? 0), 2);
    $currency   = strtoupper(substr((string)($data['currency'] ?? 'ZAR'), 0, 3));
    $reference  = mb_substr(trim((string)($data['reference'] ?? '')), 0, 120);
    $external   = trim((string)($data['external_id'] ?? '')) ?: null;
    $status     = in_array($data['status'] ?? '', PAYMENT_STATUSES, true) ? $data['status'] : 'received';
    $received   = (string)($data['received_at'] ?? date('Y-m-d H:i:s'));
    $notes      = mb_substr(trim((string)($data['notes'] ?? '')), 0, 255);
    $source     = in_array($data['source'] ?? '', PAYMENT_SOURCES, true) ? $data['source'] : 'manual';
    $source_meta = $data['source_meta'] ?? null;
    $source_meta_json = $source_meta && is_array($source_meta)
        ? json_encode($source_meta, JSON_UNESCAPED_SLASHES)
        : null;

    if ($user_id <= 0)             throw new InvalidArgumentException('Pick a customer.');
    if (!find_user_by_id($user_id)) throw new InvalidArgumentException('Customer not found.');
    if ($amount <= 0 && $status === 'received') {
        throw new InvalidArgumentException('Amount must be positive for a received payment.');
    }
    if ($invoice_id !== null) {
        $inv = invoice_find($invoice_id);
        if (!$inv)                                 throw new InvalidArgumentException('Invoice not found.');
        if ((int)$inv['user_id'] !== $user_id)     throw new InvalidArgumentException('Invoice belongs to a different customer.');
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO payments
                (user_id, invoice_id, method, amount, currency, reference, external_id,
                 status, received_at, notes, recorded_by, source, source_meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user_id, $invoice_id, $method, $amount, $currency, $reference, $external,
            $status, $received, $notes, $recorded_by, $source, $source_meta_json,
        ]);
        $id = (int)$pdo->lastInsertId();

        if ($invoice_id !== null && $status === 'received') {
            payment_settle_invoice($invoice_id);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Surface the duplicate-external_id case as a clean error.
        if ($external !== null && str_contains((string)$e->getMessage(), 'uq_payments_external')) {
            throw new RuntimeException('A payment with this gateway reference is already on file.');
        }
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log('payment.record', [
        'target_type' => 'payment', 'target_id' => $id,
        'meta' => [
            'user_id' => $user_id, 'invoice_id' => $invoice_id,
            'method'  => $method,  'amount' => $amount, 'source' => $source,
        ],
    ]);
    return $id;
}

/**
 * If total payments on this invoice ≥ invoice.total, mark it paid.
 * Also flips a paid invoice back to unpaid if a payment was refunded
 * (which we model as another negative-amount row, not a status flip).
 */
function payment_settle_invoice(int $invoice_id): void {
    $inv = invoice_find($invoice_id);
    if (!$inv) return;
    if (($inv['status'] ?? '') === 'cancelled') return;

    $stmt = pdo()->prepare(
        "SELECT COALESCE(SUM(amount), 0)
           FROM payments
          WHERE invoice_id = ? AND status = 'received'"
    );
    $stmt->execute([$invoice_id]);
    $paid_sum = (float)$stmt->fetchColumn();
    $total    = (float)$inv['total'];

    if ($paid_sum + 0.005 >= $total && $inv['status'] !== 'paid') {
        pdo()->prepare("UPDATE invoices SET status='paid', paid_at=CURRENT_TIMESTAMP WHERE id=?")
             ->execute([$invoice_id]);
        // Mirror of bin/invoices-cron.php's auto-suspend flow: when the
        // last unpaid overdue invoice is settled, lift the suspension
        // and re-enable the customer's RADIUS attributes so they can
        // authenticate again without manual intervention.
        if (!empty($inv['user_id'])) {
            payment_maybe_reactivate((int)$inv['user_id']);
        }
    } elseif ($paid_sum + 0.005 < $total && $inv['status'] === 'paid') {
        pdo()->prepare("UPDATE invoices SET status='unpaid', paid_at=NULL WHERE id=?")
             ->execute([$invoice_id]);
    }
}

/* When a customer pays the invoice that put them in dunning, lift the
   suspension and re-enable RADIUS automatically. We deliberately only
   reverse OUR OWN auto-suspend (action='billing.auto_suspend') — a
   later manual suspend (abuse, fraud, ops decision) blocks
   auto-reactivation, so the admin still has to flip them back
   themselves. */
function payment_maybe_reactivate(int $user_id): void {
    if ($user_id <= 0) return;
    $u = find_user_by_id($user_id);
    if (!$u || ($u['status'] ?? '') !== 'suspended') return;

    /* Look at the most recent status-affecting audit_log entry. If the
       last touch wasn't billing's auto-suspend, somebody overrode it
       and we shouldn't fight the override. */
    try {
        $stmt = pdo()->prepare(
            "SELECT action FROM audit_log
              WHERE target_type = 'user' AND target_id = ?
                AND action IN (
                    'billing.auto_suspend',
                    'billing.auto_reactivate',
                    'user.update',
                    'user.suspend',
                    'user.reactivate'
                )
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $last = (string)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($last !== 'billing.auto_suspend') return;

    /* Are there still other unpaid overdue invoices for this customer?
       If so, paying this one isn't enough — keep them suspended until
       they're fully caught up. */
    try {
        $stmt = pdo()->prepare(
            "SELECT COUNT(*)
               FROM invoices
              WHERE user_id = ?
                AND status = 'unpaid'
                AND due_date IS NOT NULL
                AND due_date < CURRENT_DATE()"
        );
        $stmt->execute([$user_id]);
        if ((int)$stmt->fetchColumn() > 0) return;
    } catch (Throwable $e) {
        return;
    }

    try {
        update_user($user_id, function (array $u) {
            $u['status'] = 'active';
            return $u;
        });
        if (is_file(__DIR__ . '/radius.php')) {
            require_once __DIR__ . '/radius.php';
            if (function_exists('radius_reactivate')) {
                @radius_reactivate($user_id);
            }
        }
        audit_log('billing.auto_reactivate', [
            'target_type' => 'user', 'target_id' => $user_id,
            'meta' => ['trigger' => 'invoice_paid'],
        ]);
    } catch (Throwable $e) {
        error_log('payment_maybe_reactivate failed for user ' . $user_id . ': ' . $e->getMessage());
    }
}

function payment_refund(int $payment_id, ?int $refunded_by = null, string $reason = ''): bool {
    $p = payment_find($payment_id);
    if (!$p)                            throw new RuntimeException('Payment not found.');
    if ($p['status'] === 'refunded')    return true;

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET status='refunded', notes=CONCAT_WS(' · ', NULLIF(notes,''), ?) WHERE id = ?")
            ->execute([mb_substr('refund: ' . $reason, 0, 240), $payment_id]);
        if ((int)($p['invoice_id'] ?? 0) > 0) {
            payment_settle_invoice((int)$p['invoice_id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log('payment.refund', [
        'target_type' => 'payment', 'target_id' => $payment_id,
        'meta' => ['amount' => (float)$p['amount'], 'reason' => $reason],
    ]);
    return true;
}

/**
 * Allocate an existing unallocated payment to an invoice.  Splynx-style
 * "money on account" workflow: import EFTs first, match later.
 */
function payment_allocate(int $payment_id, int $invoice_id): bool {
    $p = payment_find($payment_id);
    if (!$p)                                throw new RuntimeException('Payment not found.');
    if (!empty($p['invoice_id']))           throw new RuntimeException('Payment is already allocated.');
    $inv = invoice_find($invoice_id);
    if (!$inv)                              throw new RuntimeException('Invoice not found.');
    if ((int)$inv['user_id'] !== (int)$p['user_id']) {
        throw new InvalidArgumentException('Payment and invoice belong to different customers.');
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET invoice_id = ? WHERE id = ?")
            ->execute([$invoice_id, $payment_id]);
        payment_settle_invoice($invoice_id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_log('payment.allocate', [
        'target_type' => 'payment', 'target_id' => $payment_id,
        'meta' => ['invoice_id' => $invoice_id, 'amount' => (float)$p['amount']],
    ]);
    return true;
}

/**
 * Try to match a free-form bank-statement reference against open
 * invoices.  Used by the bank CSV importer + IPN handlers.
 *
 * Match strategy:
 *   1. Exact INV-YYYY-NNNNN string anywhere in the reference.
 *   2. Account number prefix (users.account_no) + open invoice.
 *   3. Username prefix.
 *
 * Returns ['user_id' => …, 'invoice_id' => …] when a confident match
 * is found, ['user_id' => …, 'invoice_id' => null] when we can place
 * the customer but not the invoice, or null when nothing matched.
 */
function payment_match_reference(string $reference): ?array {
    $ref = trim($reference);
    if ($ref === '') return null;

    if (preg_match('/INV[- ]?(\d{4})[- ]?(\d{4,7})/i', $ref, $m)) {
        $candidate = sprintf('INV-%04d-%05d', (int)$m[1], (int)$m[2]);
        $stmt = pdo()->prepare("SELECT id, user_id FROM invoices WHERE number = ? LIMIT 1");
        $stmt->execute([$candidate]);
        if ($r = $stmt->fetch()) {
            return ['user_id' => (int)$r['user_id'], 'invoice_id' => (int)$r['id']];
        }
    }

    if (preg_match('/([A-Z]{3}\d{4,5})/', strtoupper($ref), $m)) {
        $stmt = pdo()->prepare("SELECT id FROM users WHERE account_no = ? LIMIT 1");
        $stmt->execute([$m[1]]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            // Pick the oldest open invoice for that customer.
            $stmt = pdo()->prepare(
                "SELECT id FROM invoices
                  WHERE user_id = ? AND status = 'unpaid'
                  ORDER BY due_at ASC, id ASC LIMIT 1"
            );
            $stmt->execute([(int)$uid]);
            $inv = $stmt->fetchColumn();
            return [
                'user_id'    => (int)$uid,
                'invoice_id' => $inv ? (int)$inv : null,
            ];
        }
    }

    // Fallback: a username substring match (≥4 chars to dodge "abc"-style false positives).
    if (preg_match('/[A-Za-z]{4,}/', $ref, $m)) {
        $needle = $m[0];
        $stmt = pdo()->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([strtolower($needle)]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            $stmt = pdo()->prepare(
                "SELECT id FROM invoices WHERE user_id = ? AND status = 'unpaid' ORDER BY due_at ASC, id ASC LIMIT 1"
            );
            $stmt->execute([(int)$uid]);
            $inv = $stmt->fetchColumn();
            return [
                'user_id'    => (int)$uid,
                'invoice_id' => $inv ? (int)$inv : null,
            ];
        }
    }
    return null;
}
