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
$user = require_role(['admin', 'noc_readonly'], '/admin/login.php');

$portal = 'admin';
$nav = [
    ['group' => 'Overview', 'items' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/admin/'],
        ['key' => 'reports',   'label' => 'Reports',   'href' => '/admin/reports.php'],
    ]],
    ['group' => 'Operations', 'items' => [
        ['key' => 'incidents',   'label' => 'Service status',     'href' => '/admin/incidents.php'],
        ['key' => 'outages',     'label' => 'Outages',            'href' => '/admin/outages.php'],
        ['key' => 'maintenance', 'label' => 'Maintenance windows','href' => '/admin/maintenance.php'],
        ['key' => 'tickets',     'label' => 'Support tickets',    'href' => '/admin/tickets.php'],
    ]],
    ['group' => 'Network', 'items' => [
        ['key' => 'coverage',     'label' => 'Coverage',         'href' => '/admin/coverage.php'],
        ['key' => 'map',          'label' => 'Network map',      'href' => '/admin/map.php'],
        ['key' => 'sites',        'label' => 'Sites',            'href' => '/admin/sites.php'],
        ['key' => 'devices',      'label' => 'Devices',          'href' => '/admin/devices.php'],
        ['key' => 'sectors',      'label' => 'Sectors',          'href' => '/admin/sectors.php'],
        ['key' => 'links',        'label' => 'Wireless links',   'href' => '/admin/links.php'],
        ['key' => 'freq-planner', 'label' => 'Frequency planner','href' => '/admin/freq-planner.php'],
        ['key' => 'topology',     'label' => 'Topology review',  'href' => '/admin/topology-review.php'],
    ]],
    ['group' => 'Customers & Billing', 'items' => [
        ['key' => 'clients',          'label' => 'Clients',            'href' => '/admin/clients.php'],
        ['key' => 'invoices',         'label' => 'Invoices',           'href' => '/admin/invoices.php'],
        ['key' => 'payments',         'label' => 'Payments',           'href' => '/admin/payments.php'],
        ['key' => 'payments_import',  'label' => 'Bank CSV import',    'href' => '/admin/payments-import.php'],
        ['key' => 'credit_notes',     'label' => 'Credit notes',       'href' => '/admin/credit-notes.php'],
        ['key' => 'products',         'label' => 'Products (billing)', 'href' => '/admin/products.php'],
        ['key' => 'radius',           'label' => 'RADIUS / NAS',       'href' => '/admin/radius.php'],
    ]],
    ['group' => 'Website Content', 'items' => [
        ['key' => 'slides',  'label' => 'Hero slider',      'href' => '/admin/slides.php'],
        ['key' => 'pricing', 'label' => 'Pricing (public)', 'href' => '/admin/pricing.php'],
        ['key' => 'legal',   'label' => 'Legal pages',      'href' => '/admin/legal.php'],
        ['key' => 'images',  'label' => 'Image library',    'href' => '/admin/images.php'],
    ]],
    ['group' => 'System Settings', 'items' => [
        ['key' => 'settings',     'label' => 'Site settings', 'href' => '/admin/settings.php'],
        ['key' => 'admins',       'label' => 'Admins',        'href' => '/admin/admins.php'],
        ['key' => 'audit',        'label' => 'Audit log',     'href' => '/admin/audit.php'],
        ['key' => 'integrations', 'label' => 'Integrations',  'href' => '/admin/integrations.php'],
    ]],
    ['group' => 'My Account', 'items' => [
        ['key' => 'password', 'label' => 'My password',     'href' => '/admin/password.php'],
        ['key' => '2fa',      'label' => 'Two-factor (2FA)', 'href' => '/admin/2fa.php'],
    ]],
];

require __DIR__ . '/../auth/portal-header.php';
