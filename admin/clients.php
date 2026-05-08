<?php
$page_title = 'Clients';
$active_key = 'clients';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/poll_status.php';

// Address-picker AJAX endpoints for the inline create form. Auth has
// already been enforced by _layout.php.
if (isset($_GET['suggest'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'results' => nominatim_search((string)$_GET['suggest'], 5)]);
    exit;
}
if (isset($_GET['reverse_lat'], $_GET['reverse_lng'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $name = nominatim_reverse((float)$_GET['reverse_lat'], (float)$_GET['reverse_lng']);
    echo json_encode(['ok' => true, 'display_name' => $name]);
    exit;
}

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

$clients_freshness = poll_classify(poll_latest_link_sample_at());
?>
<div class="portal-card" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <div>
    <strong>Live wireless telemetry</strong>
    <small class="muted" style="margin-left:6px;">Per-customer signal, SNR and throughput on <code>/admin/client-view.php</code> fill in when the CPE is seen by an AP and <code>bin/poll-wireless.php</code> is running.</small>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <?= poll_badge_html($clients_freshness, 'Newest customer-link sample') ?>
    <a class="btn btn-ghost btn-sm" href="/admin/diagnostics.php">Polling status ↗</a>
  </div>
</div>
<?php
require __DIR__ . '/_users-table.php';
render_users_admin('client', 'Clients', 'Customer accounts for the WiFIBER customer portal.', $user);
require __DIR__ . '/../auth/portal-footer.php';
