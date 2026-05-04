<?php
/**
 * Lightweight value validators / normalizers used by the client editor.
 *
 * Each function returns a small array of the shape
 *   ['ok' => bool, 'value' => string, 'error' => string|null, 'extra' => array]
 * so callers can both reject bad input and keep the canonicalised form
 * (e.g. uppercase MACs, E.164 phones) without re-running the parsing.
 *
 * Empty strings are always treated as "valid, nothing to do" — the
 * editor lets admins leave most fields blank.
 */

declare(strict_types=1);

/**
 * Validate a South African ID number. 13 digits with a Luhn-on-digits
 * checksum: prefix YYMMDD + SSSS-gender + 0=ZA-citizen/1=permanent +
 * 8 + Luhn check digit.
 */
function validate_sa_id(string $raw): array {
    $v = preg_replace('/\D+/', '', $raw);
    if ($v === '') return ['ok' => true, 'value' => '', 'error' => null, 'extra' => []];
    if (strlen($v) !== 13) {
        return ['ok' => false, 'value' => $v, 'error' => 'SA ID number must be 13 digits.', 'extra' => []];
    }

    // Luhn checksum on the 13 digits.
    $sum = 0;
    for ($i = 0; $i < 13; $i++) {
        $d = (int)$v[$i];
        // Doubled positions are the EVEN indices counting from the right
        // (so positions 0, 2, 4, … from the left for a 13-digit number).
        if ($i % 2 === 1) {
            $d *= 2;
            if ($d > 9) $d -= 9;
        }
        $sum += $d;
    }
    if ($sum % 10 !== 0) {
        return ['ok' => false, 'value' => $v, 'error' => 'SA ID checksum failed — please double-check.', 'extra' => []];
    }

    $year  = (int)substr($v, 0, 2);
    $month = (int)substr($v, 2, 2);
    $day   = (int)substr($v, 4, 2);
    if (!checkdate($month ?: 1, $day ?: 1, 2000)) {
        return ['ok' => false, 'value' => $v, 'error' => 'SA ID encodes an invalid date of birth.', 'extra' => []];
    }
    $century = $year <= (int)date('y') ? 2000 : 1900;
    $dob = sprintf('%04d-%02d-%02d', $century + $year, $month, $day);
    $gender = ((int)substr($v, 6, 4)) >= 5000 ? 'male' : 'female';

    return ['ok' => true, 'value' => $v, 'error' => null, 'extra' => ['dob' => $dob, 'gender' => $gender]];
}

/**
 * SA VAT numbers are 10 digits; SARS issues numbers starting with "4".
 */
function validate_sa_vat(string $raw): array {
    $v = preg_replace('/\D+/', '', $raw);
    if ($v === '') return ['ok' => true, 'value' => '', 'error' => null, 'extra' => []];
    if (strlen($v) !== 10) {
        return ['ok' => false, 'value' => $v, 'error' => 'VAT number must be 10 digits.', 'extra' => []];
    }
    if ($v[0] !== '4') {
        return ['ok' => false, 'value' => $v, 'error' => 'SA VAT numbers start with 4.', 'extra' => []];
    }
    return ['ok' => true, 'value' => $v, 'error' => null, 'extra' => []];
}

/**
 * Normalize and validate a MAC address. Accepts colon, dash or no
 * separator on input; canonical form is lowercase colon-separated.
 */
function validate_mac(string $raw): array {
    $hex = preg_replace('/[^0-9a-fA-F]+/', '', $raw);
    if ($hex === '') return ['ok' => true, 'value' => '', 'error' => null, 'extra' => []];
    if (strlen($hex) !== 12) {
        return ['ok' => false, 'value' => $raw, 'error' => 'MAC address must have 12 hex digits.', 'extra' => []];
    }
    $hex = strtolower($hex);
    $canonical = implode(':', str_split($hex, 2));
    return ['ok' => true, 'value' => $canonical, 'error' => null, 'extra' => []];
}

/**
 * Validate an IPv4 or IPv6 address using PHP's stdlib filter.
 */
function validate_ip(string $raw): array {
    $v = trim($raw);
    if ($v === '') return ['ok' => true, 'value' => '', 'error' => null, 'extra' => []];
    if (!filter_var($v, FILTER_VALIDATE_IP)) {
        return ['ok' => false, 'value' => $v, 'error' => 'Not a valid IP address.', 'extra' => []];
    }
    return ['ok' => true, 'value' => $v, 'error' => null, 'extra' => []];
}

/**
 * Normalise a phone number to a best-effort E.164 string. South Africa
 * is the implicit default — local 0XX numbers become +27XX.
 *
 * Not a full libphonenumber port: handles common SA / international
 * formats. The original input is kept too so admins can still spot
 * "+27 12 345 6789" verbatim if they want.
 */
function normalize_phone_e164(string $raw, string $default_country = 'ZA'): array {
    $v = trim($raw);
    if ($v === '') return ['ok' => true, 'value' => '', 'error' => null, 'extra' => []];

    // 00xxx... — international dial prefix; treat as +xxx.
    $v = preg_replace('/^00/', '+', $v);

    if (preg_match('/^\+/', $v)) {
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return ['ok' => false, 'value' => $v, 'error' => 'Phone number length looks wrong.', 'extra' => []];
        }
        return ['ok' => true, 'value' => '+' . $digits, 'error' => null, 'extra' => []];
    }

    $digits = preg_replace('/\D+/', '', $v);
    if ($default_country === 'ZA' && strlen($digits) === 10 && $digits[0] === '0') {
        return ['ok' => true, 'value' => '+27' . substr($digits, 1), 'error' => null, 'extra' => []];
    }
    if ($default_country === 'ZA' && strlen($digits) === 11 && substr($digits, 0, 2) === '27') {
        return ['ok' => true, 'value' => '+' . $digits, 'error' => null, 'extra' => []];
    }
    if (strlen($digits) === 9 && $default_country === 'ZA') {
        // 9 digits with no leading 0 — assume someone dropped the trunk.
        return ['ok' => true, 'value' => '+27' . $digits, 'error' => null, 'extra' => []];
    }

    return ['ok' => false, 'value' => $v, 'error' => 'Could not normalise phone — include +country or leading 0.', 'extra' => []];
}
