<?php
$page_title = 'Clients';
$active_key = 'clients';
require __DIR__ . '/_layout.php';

// CSV export — runs before the table render so we can stream and exit.
if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/../auth/csv.php';
    $rows = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client'));

    // Honor the same filters as the on-screen list so admins can export
    // exactly what they're looking at.
    $status_filter   = trim((string)($_GET['status'] ?? ''));
    $unplaced_filter = ($_GET['unplaced'] ?? '') === '1';
    $search          = trim((string)($_GET['q'] ?? ''));
    if ($status_filter !== '' && in_array($status_filter, CUSTOMER_STATUS, true)) {
        $rows = array_values(array_filter($rows, fn($u) => ($u['status'] ?? 'active') === $status_filter));
    }
    if ($unplaced_filter) {
        $rows = array_values(array_filter($rows, fn($u) => ($u['lat'] ?? null) === null || ($u['lng'] ?? null) === null));
    }
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function (array $u) use ($needle) {
            $hay = mb_strtolower(implode(' ', [
                $u['username']   ?? '', $u['name']    ?? '', $u['surname'] ?? '',
                $u['email']      ?? '', $u['phone']   ?? '', $u['account_no'] ?? '',
                $u['address']    ?? '', $u['package'] ?? '',
            ]));
            return strpos($hay, $needle) !== false;
        }));
    }

    $shaped = array_map(fn($u) => [
        'account_no'    => $u['account_no']    ?? '',
        'username'      => $u['username']      ?? '',
        'name'          => $u['name']          ?? '',
        'surname'       => $u['surname']       ?? '',
        'email'         => $u['email']         ?? '',
        'phone'         => $u['phone']         ?? '',
        'phone_e164'    => $u['phone_e164']    ?? '',
        'status'        => $u['status']        ?? '',
        'customer_type' => $u['customer_type'] ?? '',
        'package'       => $u['package']       ?? '',
        'address'       => $u['address']       ?? '',
        'lat'           => $u['lat']           ?? '',
        'lng'           => $u['lng']           ?? '',
        'service_start' => $u['service_start'] ?? '',
        'billing_day'   => $u['billing_day']   ?? '',
        'created_at'    => $u['created_at']    ?? '',
        'last_login'    => $u['last_login']    ?? '',
    ], $rows);
    audit_log('client.export', ['target_type' => 'user', 'meta' => [
        'rows'   => count($shaped),
        'status' => $status_filter ?: null,
        'unplaced' => $unplaced_filter ?: null,
        'q'      => $search ?: null,
    ]]);
    csv_download('clients', $shaped);
}

require __DIR__ . '/_users-table.php';
render_users_admin('client', 'Clients', 'Customer accounts for the WiFIBER customer portal.', $user);
require __DIR__ . '/../auth/portal-footer.php';
