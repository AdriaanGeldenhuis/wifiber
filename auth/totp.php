<?php
/**
 * Pure-PHP TOTP (RFC 6238) implementation.
 *
 * No external dependencies. Compatible with Google Authenticator, Authy,
 * 1Password, Microsoft Authenticator and any other RFC 6238 client.
 *
 *   $secret = totp_generate_secret();           // base32 string
 *   $uri    = totp_uri($secret, 'me@x.com', 'WiFIBER');
 *   $code   = totp_code($secret);               // 6-digit current code
 *   $ok     = totp_verify($secret, '123456');   // true/false (±1 step window)
 */

declare(strict_types=1);

function totp_base32_encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $g) {
        if (strlen($g) < 5) $g = str_pad($g, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($g)];
    }
    while (strlen($out) % 8 !== 0) $out .= '=';
    return $out;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $idx = strpos($alphabet, $c);
        if ($idx === false) continue;
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_generate_secret(int $bytes = 20): string {
    return totp_base32_encode(random_bytes($bytes));
}

function totp_code(string $secret_b32, ?int $time = null, int $period = 30, int $digits = 6): string {
    $time    = $time ?? time();
    $counter = (int)floor($time / $period);
    $secret  = totp_base32_decode($secret_b32);

    // Big-endian 8-byte counter
    $bin_counter = pack('NN', 0, $counter);
    $hash        = hash_hmac('sha1', $bin_counter, $secret, true);
    $offset      = ord($hash[19]) & 0x0F;
    $part        = substr($hash, $offset, 4);
    $unpacked    = unpack('N', $part);
    $bin         = ($unpacked[1] ?? 0) & 0x7FFFFFFF;

    $mod = (int) (10 ** $digits);
    return str_pad((string)($bin % $mod), $digits, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret_b32, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret_b32, $now + ($i * 30)), $code)) return true;
    }
    return false;
}

function totp_uri(string $secret_b32, string $account, string $issuer): string {
    $issuer = preg_replace('/[:?&=]/', '', $issuer);
    $label  = rawurlencode("{$issuer}:{$account}");
    $params = http_build_query([
        'secret'    => $secret_b32,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => '6',
        'period'    => '30',
    ]);
    return "otpauth://totp/{$label}?{$params}";
}

function totp_qr_url(string $otpauth_uri, int $size = 220): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
         . '&margin=0&data=' . rawurlencode($otpauth_uri);
}

function totp_format_secret(string $secret_b32): string {
    // Group into 4-char chunks for human readability
    return trim(chunk_split(rtrim($secret_b32, '='), 4, ' '));
}

function totp_recovery_codes(int $count = 8): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $a = strtoupper(bin2hex(random_bytes(2)));
        $b = strtoupper(bin2hex(random_bytes(2)));
        $codes[] = "$a-$b";
    }
    return $codes;
}

function totp_hash_recovery_codes(array $codes): array {
    return array_map(fn($c) => password_hash(preg_replace('/[^A-Z0-9]/', '', strtoupper($c)), PASSWORD_DEFAULT), $codes);
}

function totp_consume_recovery_code(array $hashed_codes, string $supplied): array {
    $supplied = preg_replace('/[^A-Z0-9]/', '', strtoupper($supplied));
    foreach ($hashed_codes as $i => $hash) {
        if ($hash !== '' && password_verify($supplied, $hash)) {
            $hashed_codes[$i] = ''; // burn it
            return ['ok' => true, 'codes' => $hashed_codes];
        }
    }
    return ['ok' => false, 'codes' => $hashed_codes];
}
