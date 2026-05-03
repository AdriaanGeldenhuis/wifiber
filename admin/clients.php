<?php
$page_title = 'Clients';
$active_key = 'clients';
require __DIR__ . '/_layout.php';

// CSV export — runs before the table render so we can stream and exit.
if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/../auth/csv.php';
    $rows = array_values(array_filter(load_users(), fn($u) => ($u['role'] ?? '') === 'client'));
    $shaped = array_map(fn($u) => [
        'account_no' => $u['account_no'] ?? '',
        'username'   => $u['username']   ?? '',
        'name'       => $u['name']       ?? '',
        'email'      => $u['email']      ?? '',
        'phone'      => $u['phone']      ?? '',
        'status'     => $u['status']     ?? '',
        'package'    => $u['package']    ?? '',
        'address'    => $u['address']    ?? '',
        'created_at' => $u['created_at'] ?? '',
        'last_login' => $u['last_login'] ?? '',
    ], $rows);
    audit_log('client.export', ['target_type' => 'user', 'meta' => ['rows' => count($shaped)]]);
    csv_download('clients', $shaped);
}

require __DIR__ . '/_users-table.php';
render_users_admin('client', 'Clients', 'Customer accounts for the WiFIBER customer portal.', $user);
require __DIR__ . '/../auth/portal-footer.php';
