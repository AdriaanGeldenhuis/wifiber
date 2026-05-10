<?php
$page_title = 'Staff';
$active_key = 'admins';
require __DIR__ . '/_layout.php';
require __DIR__ . '/_users-table.php';

// Every staff role we can create from this page. Mirrors
// ACL_STAFF_ROLES_FALLBACK but kept local so the form choices match
// what the ACL actually grants. 'client' is intentionally excluded —
// customer accounts go through /admin/clients.php.
$staff_roles = ['admin','super_admin','billing','support','technician','noc_readonly','viewer'];

render_users_admin(
    'admin',
    'Staff',
    'Everyone with access to this admin panel — admins, billing, support, technicians and NOC.',
    $user,
    $staff_roles
);
require __DIR__ . '/../auth/portal-footer.php';
