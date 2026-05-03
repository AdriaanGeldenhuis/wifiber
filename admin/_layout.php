<?php
/**
 * Admin layout helper. Loaded by every admin page (except login/setup/logout).
 *
 * - Verifies user is logged in as admin
 * - Verifies request IP is allow-listed
 * - Builds $nav and emits the portal header
 *
 * Usage at the top of an admin page:
 *   $page_title = 'Page name';
 *   $active_key = 'clients';
 *   require __DIR__ . '/_layout.php';
 */
require_once __DIR__ . '/../auth/helpers.php';

require_admin_ip();
$user = require_role('admin', '/admin/login.php');

$portal = 'admin';
$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard',      'href' => '/admin/'],
    ['key' => 'tickets',   'label' => 'Support tickets', 'href' => '/admin/tickets.php'],
    ['key' => 'slides',    'label' => 'Hero slider',    'href' => '/admin/slides.php'],
    ['key' => 'pricing',   'label' => 'Pricing',        'href' => '/admin/pricing.php'],
    ['key' => 'legal',     'label' => 'Legal pages',    'href' => '/admin/legal.php'],
    ['key' => 'images',    'label' => 'Image library',  'href' => '/admin/images.php'],
    ['key' => 'settings',  'label' => 'Site settings',  'href' => '/admin/settings.php'],
    ['key' => 'clients',   'label' => 'Clients',        'href' => '/admin/clients.php'],
    ['key' => 'admins',    'label' => 'Admins',         'href' => '/admin/admins.php'],
    ['key' => 'password',  'label' => 'My password',    'href' => '/admin/password.php'],
    ['key' => '2fa',       'label' => 'Two-factor (2FA)', 'href' => '/admin/2fa.php'],
];

require __DIR__ . '/../auth/portal-header.php';
