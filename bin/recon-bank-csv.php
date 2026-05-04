<?php
/**
 * Bank statement CSV reconciliation.
 *
 * Reads a downloaded SA-bank statement CSV (FNB / Standard / ABSA /
 * Capitec / Nedbank formats are auto-detected) and turns each credit
 * line into a payments row, attempting to match the bank's free-form
 * description against an open invoice via payment_match_reference().
 *
 * Usage:
 *   php bin/recon-bank-csv.php /path/to/statement.csv [--dry-run] [--bank=fnb]
 *
 * Output is a TSV-friendly per-row summary so the operator can paste
 * into a sheet for the unmatched entries:
 *
 *   matched   2026-04-01  R450.00  GEL0001  inv=INV-2026-00187
 *   unmatched 2026-04-01  R200.00  "Some unknown reference"
 *   skipped   2026-04-01 -R150.00  (debit, ignored)
 *
 * The same parser powers the upload form on /admin/payments-import.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/payments.php';

/* ============================================================ functions */

function recon_bank_csv_process(string $file, string $bank_hint = 'auto', bool $write = true): array {
    $fh = fopen($file, 'r');
    if (!$fh) throw new RuntimeException("could not read $file");

    $rows = [];
    while (($line = fgetcsv($fh, 8192, ',', '"', '\\')) !== false) {
        $rows[] = $line;
    }
    fclose($fh);
    if (!$rows) {
        return ['rows' => [], 'rows_total' => 0, 'matched' => 0, 'on_account' => 0,
                'skipped' => 0, 'duplicates' => 0, 'errors' => 0, 'bank' => 'unknown'];
    }
    $bank = $bank_hint === 'auto' ? recon_detect_bank($rows) : $bank_hint;

    $parsed = recon_extract_credits($rows, $bank);

    $out = ['rows' => [], 'matched' => 0, 'on_account' => 0,
            'skipped' => 0, 'duplicates' => 0, 'errors' => 0,
            'rows_total' => count($parsed), 'bank' => $bank];

    foreach ($parsed as $p) {
        $row = [
            'date'      => $p['date'],
            'amount'    => $p['amount'],
            'reference' => $p['reference'],
            'outcome'   => 'unmatched',
            'note'      => '',
        ];
        if ($p['amount'] <= 0) {
            $row['outcome'] = 'skipped';
            $row['note']    = 'debit, ignored';
            $out['skipped']++;
            $out['rows'][] = $row;
            continue;
        }

        $match = payment_match_reference($p['reference']);
        $external = recon_synthesize_external_id($bank, $p);

        if ($match === null) {
            $row['outcome'] = 'unmatched';
            $row['note']    = 'no customer found';
            $out['rows'][]  = $row;
            continue;
        }

        $payload = [
            'user_id'     => $match['user_id'],
            'invoice_id'  => $match['invoice_id'],
            'method'      => 'eft',
            'amount'      => $p['amount'],
            'currency'    => 'ZAR',
            'reference'   => $p['reference'],
            'external_id' => $external,
            'received_at' => $p['date'] . ' 12:00:00',
            'notes'       => 'Imported from ' . $bank . ' statement',
            'source'      => 'bank_csv',
            'source_meta' => $p,
        ];

        if (!$write) {
            $row['outcome'] = $match['invoice_id'] ? 'matched' : 'on_account';
            $row['note']    = '[dry-run] user=' . $match['user_id']
                            . ($match['invoice_id'] ? ' inv=' . $match['invoice_id'] : '');
            $row['note']   .= ' ext=' . $external;
            ($match['invoice_id'] ? $out['matched']++ : $out['on_account']++);
            $out['rows'][] = $row;
            continue;
        }

        try {
            payment_record($payload);
            $row['outcome'] = $match['invoice_id'] ? 'matched' : 'on_account';
            $row['note']    = 'user=' . $match['user_id']
                            . ($match['invoice_id'] ? ' inv=' . $match['invoice_id'] : ' (on account)');
            ($match['invoice_id'] ? $out['matched']++ : $out['on_account']++);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already on file')) {
                $row['outcome'] = 'duplicate';
                $row['note']    = 'already imported (external_id ' . $external . ')';
                $out['duplicates']++;
            } else {
                $row['outcome'] = 'error';
                $row['note']    = $e->getMessage();
                $out['errors']++;
            }
        } catch (Throwable $e) {
            $row['outcome'] = 'error';
            $row['note']    = $e->getMessage();
            $out['errors']++;
        }
        $out['rows'][] = $row;
    }
    return $out;
}

/**
 * Auto-detect the bank from the CSV header.  Each SA bank has a
 * recognisably different layout — we sniff the first 5 lines for
 * keywords. Defaults to 'generic' which assumes
 * date,description,amount columns.
 */
function recon_detect_bank(array $rows): string {
    $blob = '';
    foreach (array_slice($rows, 0, 5) as $r) $blob .= ' ' . implode(' ', $r);
    $blob = strtolower($blob);
    if (str_contains($blob, 'absa') || str_contains($blob, 'transaction history report')) return 'absa';
    if (str_contains($blob, 'fnb')  || str_contains($blob, 'first national bank'))         return 'fnb';
    if (str_contains($blob, 'standard bank'))                                              return 'standard';
    if (str_contains($blob, 'capitec'))                                                    return 'capitec';
    if (str_contains($blob, 'nedbank'))                                                    return 'nedbank';
    return 'generic';
}

/**
 * Extract a normalised list of {date, amount, reference} from the raw
 * CSV rows. Each bank has its own column conventions, all hand-mapped
 * here. Header rows are skipped by detecting non-numeric amount cells.
 */
function recon_extract_credits(array $rows, string $bank): array {
    // Skip the human-readable preamble most banks ship before the data.
    // We look for the first row that has a numeric amount in the column
    // we expect.
    $col = match ($bank) {
        'fnb'      => ['date' => 0, 'desc' => 2, 'amount' => 3],
        'absa'     => ['date' => 0, 'desc' => 1, 'amount' => 4],
        'standard' => ['date' => 0, 'desc' => 2, 'amount' => 4],
        'capitec'  => ['date' => 0, 'desc' => 4, 'amount' => 5],
        'nedbank'  => ['date' => 0, 'desc' => 1, 'amount' => 3],
        default    => ['date' => 0, 'desc' => 1, 'amount' => 2],
    };

    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r) || count($r) <= max($col['date'], $col['desc'], $col['amount'])) continue;
        $raw_date = trim((string)$r[$col['date']]);
        $raw_desc = trim((string)$r[$col['desc']]);
        $raw_amt  = preg_replace('/[^\d.\-]/', '', (string)$r[$col['amount']]);
        if (!is_numeric($raw_amt)) continue;
        $amount = (float)$raw_amt;

        $date = recon_parse_date($raw_date);
        if ($date === null) continue;

        $out[] = [
            'date'      => $date,
            'amount'    => $amount,
            'reference' => $raw_desc,
            'bank'      => $bank,
        ];
    }
    return $out;
}

/**
 * Parse an SA bank's date string. The variants we see in the wild:
 *   2026/04/01, 01/04/2026, 01-04-2026, 01 Apr 2026, 2026-04-01
 */
function recon_parse_date(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Build a stable external_id from the row so a re-imported statement
 * doesn't double-record.  Hash the canonical (bank, date, amount,
 * reference) tuple.
 */
function recon_synthesize_external_id(string $bank, array $row): string {
    $key = $bank . '|' . $row['date'] . '|' . number_format($row['amount'], 2, '.', '') . '|' . $row['reference'];
    return $bank . ':' . substr(hash('sha256', $key), 0, 24);
}

/* =============================================================== cli main */

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === __FILE__) {
    $opts = ['dry-run' => false, 'bank' => 'auto'];
    $file = null;
    foreach (array_slice($argv, 1) as $a) {
        if      ($a === '--dry-run') $opts['dry-run'] = true;
        elseif  (preg_match('/^--bank=([a-z]+)$/i', $a, $m)) $opts['bank'] = strtolower($m[1]);
        elseif  ($file === null && is_file($a)) $file = $a;
        else { fwrite(STDERR, "unknown arg: $a\n"); exit(2); }
    }
    if ($file === null) {
        fwrite(STDERR, "usage: recon-bank-csv.php <statement.csv> [--dry-run] [--bank=fnb|standard|absa|capitec|nedbank]\n");
        exit(2);
    }
    $result = recon_bank_csv_process($file, $opts['bank'], !$opts['dry-run']);
    foreach ($result['rows'] as $r) {
        printf("%-9s  %s  R%9s  %-25s  %s\n",
            $r['outcome'], $r['date'], number_format($r['amount'], 2),
            substr((string)$r['reference'], 0, 25),
            $r['note']);
    }
    printf("\n[recon] file=%s  bank=%s  rows=%d  matched=%d  on_account=%d  skipped=%d  duplicates=%d\n",
        $file, $result['bank'], $result['rows_total'],
        $result['matched'], $result['on_account'], $result['skipped'], $result['duplicates']);
    exit($result['errors'] > 0 ? 1 : 0);
}
