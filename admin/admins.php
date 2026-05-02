<?php
$page_title = 'Admins';
$active_key = 'admins';
require __DIR__ . '/_layout.php';
require __DIR__ . '/_users-table.php';
render_users_admin('admin', 'Admins', 'Staff accounts with access to this admin panel.', $user);
require __DIR__ . '/../auth/portal-footer.php';
