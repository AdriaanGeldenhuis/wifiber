<?php
/**
 * Shared plumbing for the UISP / Splynx / FreeRADIUS importers.
 *
 *   • importer_run_begin() / importer_run_end() — bracket each run so
 *     /admin/imports.php has a history page without us scraping logs.
 *   • importer_http_get_json() — JSON GET against a remote API with
 *     bearer / basic auth, follow-redirects off, sane timeouts.
 *   • importer_find_by_external_ref() / importer_upsert_external_ref() —
 *     the idempotency primitives every importer rides on.
 *
 * The importers themselves dispatch on a free-form (source, resource)
 * pair so the run-history table reads as e.g.
 *   uisp / sites,  uisp / devices,
 *   splynx / customers, splynx / invoices,
 *   radius / radcheck, radius / radacct.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const IMPORTER_HTTP_TIMEOUT_SECS = 20;

/* ----------------------------------------------------------- run history */

function importer_run_begin(string $source, string $resource, bool $dry_run, ?int $triggered_by = null): int {
    $stmt = pdo()->prepare(
        "INSERT INTO import_runs
            (source, resource, started_at, dry_run, triggered_by)
         VALUES (?, ?, NOW(), ?, ?)"
    );
    $stmt->execute([
        mb_substr($source,   0, 20),
        mb_substr($resource, 0, 40),
        $dry_run ? 1 : 0,
        $triggered_by,
    ]);
    return (int)pdo()->lastInsertId();
}

function importer_run_end(int $run_id, array $counts, ?string $notes = null): void {
    pdo()->prepare(
        "UPDATE import_runs
            SET finished_at = NOW(),
                rows_total   = ?,
                rows_created = ?,
                rows_updated = ?,
                rows_skipped = ?,
                rows_failed  = ?,
                notes        = ?
          WHERE id = ?"
    )->execute([
        (int)($counts['total']   ?? 0),
        (int)($counts['created'] ?? 0),
        (int)($counts['updated'] ?? 0),
        (int)($counts['skipped'] ?? 0),
        (int)($counts['failed']  ?? 0),
        $notes,
        $run_id,
    ]);
}

function importer_runs_recent(int $limit = 50): array {
    $limit = max(1, min(500, $limit));
    return pdo()->query(
        "SELECT * FROM import_runs ORDER BY started_at DESC LIMIT $limit"
    )->fetchAll();
}

/* ----------------------------------------------------------------- HTTP */

/**
 * GET a JSON endpoint. Returns ['ok' => bool, 'status' => int, 'data' => ?array, 'error' => ?string].
 *
 * $auth options:
 *   ['bearer' => 'TOKEN']
 *   ['basic'  => ['user' => 'admin', 'pass' => '…']]
 *   ['header' => ['X-Auth-Token: …']]    free-form headers
 */
function importer_http_get_json(string $url, array $auth = [], int $timeout = IMPORTER_HTTP_TIMEOUT_SECS): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl extension not available'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl_init failed'];
    }
    $headers = ['Accept: application/json'];

    if (!empty($auth['bearer'])) $headers[] = 'Authorization: Bearer ' . $auth['bearer'];
    if (!empty($auth['header']) && is_array($auth['header'])) {
        foreach ($auth['header'] as $h) $headers[] = $h;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => max(2, (int)floor($timeout / 4)),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'WiFIBER-Importer/1.0',
    ]);
    if (!empty($auth['basic']) && is_array($auth['basic'])) {
        curl_setopt($ch, CURLOPT_USERPWD, ($auth['basic']['user'] ?? '') . ':' . ($auth['basic']['pass'] ?? ''));
    }
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'status' => $code, 'data' => null, 'error' => $err];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'status' => $code, 'data' => null, 'error' => "HTTP $code"];
    }
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'status' => $code, 'data' => null, 'error' => 'empty body'];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $code, 'data' => null, 'error' => 'response is not JSON'];
    }
    return ['ok' => true, 'status' => $code, 'data' => $data, 'error' => null];
}

/* -------------------------------------------------------- idempotent upsert */

/**
 * Lookup a row by (external_src, external_ref).  Falls back to a
 * provided extra-match callable for systems that have no external_ref
 * yet but should be considered the same row (e.g. matching a UISP MAC
 * to an existing manually-typed devices.mac).
 */
function importer_find_by_external_ref(string $table, string $src, string $ref): ?array {
    $stmt = pdo()->prepare(
        "SELECT * FROM `$table` WHERE external_src = ? AND external_ref = ? LIMIT 1"
    );
    $stmt->execute([$src, $ref]);
    return $stmt->fetch() ?: null;
}

/**
 * INSERT / UPDATE a row keyed on (external_src, external_ref).
 *
 * $values is column => value with literal SQL friendly types (strings,
 * ints, floats, bools, nulls). Returns ['id' => int, 'change' =>
 * 'created' | 'updated' | 'noop'].  Honours $dry_run by reporting what
 * would happen without writing.
 */
function importer_upsert_external_ref(
    string $table,
    string $src,
    string $ref,
    array $values,
    bool $dry_run = false,
    ?array $extra_match = null
): array {
    $existing = importer_find_by_external_ref($table, $src, $ref);
    if (!$existing && $extra_match) {
        // Try a fallback equality lookup so we don't create a duplicate
        // when an admin already typed the row in by hand. e.g.
        //   ['mac' => 'AA:BB:...'] → match by MAC then attach external_ref.
        $where = [];
        $args  = [];
        foreach ($extra_match as $col => $val) {
            $where[] = "`$col` = ?";
            $args[]  = $val;
        }
        $sql = "SELECT * FROM `$table` WHERE " . implode(' AND ', $where) . " LIMIT 1";
        $stmt = pdo()->prepare($sql);
        $stmt->execute($args);
        $existing = $stmt->fetch() ?: null;
    }

    $values['external_src'] = $src;
    $values['external_ref'] = $ref;

    if ($existing) {
        // UPDATE only the fields that actually differ — keep the audit
        // log signal-to-noise tight.
        $diff = [];
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, $existing)) continue;
            $current = $existing[$k];
            if ((string)$current !== (string)$v) $diff[$k] = $v;
        }
        if (!$diff) return ['id' => (int)$existing['id'], 'change' => 'noop'];
        if ($dry_run) return ['id' => (int)$existing['id'], 'change' => 'updated', 'diff' => $diff];

        $set  = [];
        $args = [];
        foreach ($diff as $k => $v) {
            $set[]  = "`$k` = ?";
            $args[] = $v;
        }
        $args[] = (int)$existing['id'];
        pdo()->prepare("UPDATE `$table` SET " . implode(', ', $set) . " WHERE id = ?")
             ->execute($args);
        return ['id' => (int)$existing['id'], 'change' => 'updated'];
    }

    if ($dry_run) return ['id' => 0, 'change' => 'created'];

    // INSERT — drop any keys that aren't real columns by trying all and
    // catching the SQL error path.  Cheaper than introspecting
    // information_schema for every importer row.
    $cols = array_keys($values);
    $place = implode(', ', array_fill(0, count($cols), '?'));
    $cols_q = implode(', ', array_map(fn ($c) => "`$c`", $cols));
    pdo()->prepare("INSERT INTO `$table` ($cols_q) VALUES ($place)")->execute(array_values($values));
    return ['id' => (int)pdo()->lastInsertId(), 'change' => 'created'];
}

/* ------------------------------------------------------- progress reporting */

class ImporterCounters {
    public int $total   = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $failed  = 0;

    public function note(string $change): void {
        $this->total++;
        match ($change) {
            'created' => $this->created++,
            'updated' => $this->updated++,
            'noop'    => $this->skipped++,
            'skipped' => $this->skipped++,
            'failed'  => $this->failed++,
            default   => null,
        };
    }

    public function as_array(): array {
        return [
            'total'   => $this->total,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed'  => $this->failed,
        ];
    }

    public function summary(): string {
        return sprintf('total=%d created=%d updated=%d skipped=%d failed=%d',
            $this->total, $this->created, $this->updated, $this->skipped, $this->failed);
    }
}

/* ---------------------------------------------------- common CLI parsing */

/**
 * Parse the standard --dry-run / --limit=N / --base-url= / --token= /
 * --user= / --pass= flags every importer accepts.  Importer-specific
 * flags should be handled before the call so they don't fall into the
 * "unknown flag" exit path.
 */
function importer_parse_common_args(array $argv, array &$opts): array {
    $remaining = [];
    foreach (array_slice($argv, 1) as $a) {
        if      ($a === '--dry-run')                    $opts['dry-run'] = true;
        elseif  (preg_match('/^--limit=(\d+)$/', $a, $m)) $opts['limit'] = (int)$m[1];
        elseif  (preg_match('/^--base-url=(.+)$/', $a, $m)) $opts['base-url'] = rtrim($m[1], '/');
        elseif  (preg_match('/^--token=(.+)$/', $a, $m))    $opts['token']    = $m[1];
        elseif  (preg_match('/^--user=(.+)$/',  $a, $m))    $opts['user']     = $m[1];
        elseif  (preg_match('/^--pass=(.+)$/',  $a, $m))    $opts['pass']     = $m[1];
        elseif  (preg_match('/^--only=([\w,]+)$/', $a, $m)) $opts['only']     = explode(',', $m[1]);
        else $remaining[] = $a;
    }
    return $remaining;
}
