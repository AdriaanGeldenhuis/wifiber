<?php
/**
 * PayFast adapter — RSA's most common card / EFT / instant-EFT gateway.
 *
 * Two responsibilities:
 *
 *   payfast_checkout_url($invoice)        — build the URL we redirect the
 *                                            customer to so they can pay.
 *   payfast_verify_itn($post, $remote_ip) — validate an inbound ITN
 *                                            (Instant Transaction Notification)
 *                                            from PayFast's servers.
 *
 * Configuration lives in data/site.json under "billing.payfast":
 *   {
 *     "merchant_id":  "10000100",          // PayFast sandbox demo
 *     "merchant_key": "46f0cd694581a",
 *     "passphrase":   "jt7NOE43FZPn",       // optional, set in PayFast portal
 *     "sandbox":      true                  // pick the right host
 *   }
 *
 * PayFast docs: https://developers.payfast.co.za/docs#step_1_form_fields
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../invoices.php';

/** Approved PayFast source IP ranges (live + sandbox). Used by ITN verify. */
const PAYFAST_VALID_HOSTS = [
    'www.payfast.co.za', 'sandbox.payfast.co.za', 'w1w.payfast.co.za', 'w2w.payfast.co.za',
];

function payfast_config(): array {
    $site = load_site_settings();
    $cfg  = $site['billing']['payfast'] ?? [];
    return [
        'merchant_id'   => trim((string)($cfg['merchant_id']  ?? '')),
        'merchant_key'  => trim((string)($cfg['merchant_key'] ?? '')),
        'passphrase'    => (string)($cfg['passphrase'] ?? ''),
        'sandbox'       => !empty($cfg['sandbox']),
    ];
}

function payfast_host(): string {
    return payfast_config()['sandbox']
        ? 'sandbox.payfast.co.za'
        : 'www.payfast.co.za';
}

function payfast_is_configured(): bool {
    $c = payfast_config();
    return $c['merchant_id'] !== '' && $c['merchant_key'] !== '';
}

/**
 * Build the redirect URL that takes the customer to PayFast's checkout.
 *
 * Notes:
 *   • Amount is sent as inc-VAT, dot-separated, two decimals.
 *   • m_payment_id == invoice number so the ITN handler can reverse-look-up.
 *   • return_url / cancel_url / notify_url are all our own pages.
 */
function payfast_checkout_url(array $invoice, string $base_url): string {
    $cfg = payfast_config();
    if (!payfast_is_configured()) {
        throw new RuntimeException('PayFast is not configured (data/site.json → billing.payfast).');
    }
    $base = rtrim($base_url, '/');
    $name = trim((string)($invoice['client_name'] ?? $invoice['username'] ?? ''));
    $parts = preg_split('/\s+/', $name, 2) ?: [''];
    $first = $parts[0] ?? '';
    $last  = $parts[1] ?? $first;

    $fields = [
        'merchant_id'      => $cfg['merchant_id'],
        'merchant_key'     => $cfg['merchant_key'],
        'return_url'       => $base . '/account/invoices.php?id=' . (int)$invoice['id'] . '&pay=ok',
        'cancel_url'       => $base . '/account/invoices.php?id=' . (int)$invoice['id'] . '&pay=cancel',
        'notify_url'       => $base . '/api/v1/payments/ipn.php?gateway=payfast',
        'name_first'       => mb_substr($first, 0, 100),
        'name_last'        => mb_substr($last,  0, 100),
        'email_address'    => mb_substr((string)($invoice['client_email'] ?? ''), 0, 100),
        'm_payment_id'     => (string)$invoice['number'],
        'amount'           => number_format((float)$invoice['total'], 2, '.', ''),
        'item_name'        => mb_substr('Invoice ' . $invoice['number'], 0, 100),
        'item_description' => mb_substr((string)($invoice['notes'] ?? 'WiFIBER service'), 0, 250),
        'custom_int1'      => (string)(int)$invoice['id'],
    ];
    $fields = array_filter($fields, fn ($v) => $v !== '' && $v !== null);

    $signature = payfast_signature($fields, $cfg['passphrase']);
    $fields['signature'] = $signature;

    return 'https://' . payfast_host() . '/eng/process?' . http_build_query($fields);
}

/**
 * Validate an inbound ITN POST.  Four checks (PayFast's recommended
 * algorithm):
 *
 *   1. Recompute the signature over the supplied fields and confirm match.
 *   2. Confirm source IP resolves to a known PayFast host.
 *   3. Postback the body to PayFast's /eng/query/validate endpoint and
 *      receive 'VALID'.
 *   4. Sanity-check the amount against our stored invoice total.
 *
 * Returns ['ok' => bool, 'reason' => string, 'invoice_id' => ?int].
 */
function payfast_verify_itn(array $post, string $remote_ip, string $base_host = 'wifiber.co.za'): array {
    if (!payfast_is_configured()) {
        return ['ok' => false, 'reason' => 'PayFast not configured'];
    }
    $cfg = payfast_config();

    // 1. signature
    $supplied = (string)($post['signature'] ?? '');
    $check    = $post;
    unset($check['signature']);
    $expected = payfast_signature($check, $cfg['passphrase']);
    if (!hash_equals($expected, $supplied)) {
        return ['ok' => false, 'reason' => 'signature mismatch'];
    }

    // 2. source IP
    if (!payfast_ip_is_valid($remote_ip)) {
        return ['ok' => false, 'reason' => 'source IP not on PayFast allowlist'];
    }

    // 3. postback
    if (!payfast_postback_validate($post)) {
        return ['ok' => false, 'reason' => 'postback returned non-VALID'];
    }

    // 4. amount sanity
    $invoice_id = (int)($post['custom_int1'] ?? 0);
    $invoice    = $invoice_id ? invoice_find($invoice_id) : null;
    if (!$invoice) {
        // Fall back to looking up by number (m_payment_id).
        $num = (string)($post['m_payment_id'] ?? '');
        if ($num !== '') {
            $stmt = pdo()->prepare("SELECT id FROM invoices WHERE number = ? LIMIT 1");
            $stmt->execute([$num]);
            $found = $stmt->fetchColumn();
            if ($found) { $invoice_id = (int)$found; $invoice = invoice_find($invoice_id); }
        }
    }
    if (!$invoice) {
        return ['ok' => false, 'reason' => 'invoice not found'];
    }
    $expected_amount = number_format((float)$invoice['total'], 2, '.', '');
    if ((string)($post['amount_gross'] ?? '') !== $expected_amount) {
        return ['ok' => false, 'reason' => 'amount mismatch'];
    }
    return ['ok' => true, 'reason' => 'verified', 'invoice_id' => $invoice_id];
}

/**
 * PayFast signature: MD5 of a urlencoded querystring built from every
 * field except 'signature', in the order they were posted, with the
 * passphrase appended as a separate parameter when set.
 *
 * Critically: PayFast's url-encoding uses '+' for spaces (PHP default
 * RFC1738), not %20 — http_build_query gets this right by default.
 */
function payfast_signature(array $fields, string $passphrase): string {
    $pairs = [];
    foreach ($fields as $k => $v) {
        if ($k === 'signature') continue;
        if ($v === null || $v === '') continue;
        $pairs[] = $k . '=' . urlencode(trim((string)$v));
    }
    $string = implode('&', $pairs);
    if ($passphrase !== '') {
        $string .= '&passphrase=' . urlencode(trim($passphrase));
    }
    return md5($string);
}

function payfast_ip_is_valid(string $ip): bool {
    if ($ip === '') return false;
    foreach (PAYFAST_VALID_HOSTS as $host) {
        $rs = @gethostbynamel($host);
        if (is_array($rs) && in_array($ip, $rs, true)) return true;
    }
    return false;
}

/**
 * Postback the ITN body to PayFast and require 'VALID' in the response.
 * Network call, not free — guard with a 5-second timeout.
 */
function payfast_postback_validate(array $post): bool {
    if (!function_exists('curl_init')) return false;
    $url = 'https://' . payfast_host() . '/eng/query/validate';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) return false;
    return strtoupper(trim($body)) === 'VALID';
}
