<?php
/**
 * CSV helpers — turn a list of associative rows into a streamed
 * download. Reused by /admin/audit.php, /admin/invoices.php,
 * /admin/clients.php, etc.
 */

declare(strict_types=1);

/**
 * Stream the rows as a CSV download, then exit.
 *
 *   csv_download('audit-log', $rows, ['created_at','username','action']);
 *
 * If $columns is null, every key from the first row is used.
 * Values that aren't scalar are JSON-encoded inline.
 */
function csv_download(string $filename, array $rows, ?array $columns = null): void {
    while (ob_get_level() > 0) ob_end_clean();

    $stamp = date('Ymd-Hi');
    $safe  = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $filename) ?: 'export';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe . '-' . $stamp . '.csv"');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    if ($out === false) exit;

    // BOM so Excel renders UTF-8 correctly.
    fwrite($out, "\xEF\xBB\xBF");

    if (!$columns && !empty($rows)) {
        $columns = array_keys((array)$rows[0]);
    }
    if ($columns) fputcsv($out, $columns);

    foreach ($rows as $row) {
        $line = [];
        foreach ($columns ?? [] as $col) {
            $v = is_array($row) ? ($row[$col] ?? '') : ($row->$col ?? '');
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $line[] = (string)$v;
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}
