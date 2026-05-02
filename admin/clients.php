<?php
$page_title = 'Clients';
$active_key = 'clients';
require __DIR__ . '/_layout.php';
require __DIR__ . '/_users-table.php';
render_users_admin('client', 'Clients', 'Customer accounts for the WiFIBER customer portal.', $user);
require __DIR__ . '/../auth/portal-footer.php';
