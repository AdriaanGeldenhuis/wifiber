<?php
/**
 * Payment-gateway ITN/IPN landing pad.
 *
 *   POST /api/v1/payments/ipn.php?gateway=payfast
 *
 * Each gateway has its own verification path because the signature
 * algorithms differ. We dispatch on the ?gateway= query parameter and
 * delegate to the relevant adapter.
 *
 * Auth: gateway-specific (signature + IP allowlist + postback). No
 * bearer-token check — these come from external servers.
 *
 * Response is plain text, not JSON, so PayFast's parser doesn't choke:
 *   200 "ok"          — payment recorded (or already recorded)
 *   200 "duplicate"   — same external_id already on file
 *   400 "<reason>"    — verification failed
 *
 * PayFast's ITN protocol expects a 200 even for invalid posts; we deviate
 * because their docs say "the response code is ignored" and a 4xx makes
 * troubleshooting trivially obvious in the deploy logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/helpers.php';
require_once __DIR__ . '/../../../auth/invoices.php';
require_once __DIR__ . '/../../../auth/payments.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Wifiber-API: payments/ipn');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo "POST only\n";
    exit;
}

$gateway = strtolower(trim((string)($_GET['gateway'] ?? '')));

if ($gateway === 'payfast') {
    require_once __DIR__ . '/../../../auth/payments/payfast.php';

    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $verified  = payfast_verify_itn($_POST, $remote_ip);

    if (!$verified['ok']) {
        audit_log('payment.ipn.reject', [
            'meta' => [
                'gateway' => 'payfast',
                'reason'  => $verified['reason'],
                'ip'      => $remote_ip,
                'm_payment_id' => (string)($_POST['m_payment_id'] ?? ''),
            ],
        ]);
        http_response_code(400);
        echo $verified['reason'];
        exit;
    }

    $invoice_id = (int)$verified['invoice_id'];
    $invoice    = invoice_find($invoice_id);
    if (!$invoice) {
        http_response_code(400);
        echo "invoice not found";
        exit;
    }

    $status = strtolower((string)($_POST['payment_status'] ?? ''));
    if ($status !== 'complete') {
        // Cancelled / pending statuses still get logged but don't write
        // a payments row — PayFast retries until COMPLETE.
        audit_log('payment.ipn.skip', [
            'meta' => ['gateway' => 'payfast', 'status' => $status, 'invoice_id' => $invoice_id],
        ]);
        echo "skipped: status=$status";
        exit;
    }

    try {
        payment_record([
            'user_id'     => (int)$invoice['user_id'],
            'invoice_id'  => $invoice_id,
            'method'      => 'payfast',
            'amount'      => (float)($_POST['amount_gross'] ?? $invoice['total']),
            'currency'    => 'ZAR',
            'reference'   => (string)($_POST['m_payment_id'] ?? $invoice['number']),
            'external_id' => (string)($_POST['pf_payment_id'] ?? ''),
            'status'      => 'received',
            'received_at' => date('Y-m-d H:i:s'),
            'notes'       => 'PayFast ITN — token=' . (string)($_POST['token'] ?? ''),
            'source'      => 'gateway',
            'source_meta' => $_POST,
        ]);
        echo "ok";
    } catch (RuntimeException $e) {
        // Duplicate external_id — payment already on file.
        if (str_contains($e->getMessage(), 'already on file')) {
            echo "duplicate";
            exit;
        }
        http_response_code(500);
        echo "error: " . $e->getMessage();
    } catch (Throwable $e) {
        error_log('payfast IPN error: ' . $e->getMessage());
        http_response_code(500);
        echo "error";
    }
    exit;
}

http_response_code(400);
echo "unknown gateway";
