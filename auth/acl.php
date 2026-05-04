<?php
/**
 * Role-based access control.
 *
 *   acl_can($user, 'customers.write')   // bool
 *   acl_require('customers.write')       // 403 if not allowed
 *
 * Replaces the binary admin / noc_readonly check in helpers.php for
 * pages where finer granularity matters — e.g. /admin/invoices.php
 * gates on 'invoices.write' so a billing-clerk role can mutate invoices
 * without giving them the keys to push-to-radio.
 *
 * The rule table is intentionally a flat map: we don't need
 * inheritance, scopes or capability hierarchies. Roles whose
 * permissions overlap are just listed once each. If a role isn't in
 * the allow-list for a given capability, it gets denied — no
 * implicit grants.
 *
 * Capabilities are dotted strings of the form `<resource>.<verb>`:
 *   customers.{read,write,delete}
 *   invoices.{read,write,delete,refund}
 *   payments.{read,write}
 *   products.{read,write}
 *   radius.{read,write,disconnect}
 *   sites.{read,write,delete}
 *   devices.{read,write,delete}
 *   sectors.{read,write,delete}
 *   links.{read,write}
 *   radio.push                — queue a wireless_change_jobs row
 *   outages.{read,close}
 *   maintenance.{read,write}
 *   tickets.{read,write}
 *   reports.read
 *   audit.read
 *   settings.{read,write}
 *   integrations.{read,write}
 *   admins.{read,write}
 *   inbox.read                — anyone with admin sidebar access
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const ACL_STAFF_ROLES = [
    'super_admin','admin','billing','support','technician','noc_readonly','viewer',
];

function acl_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;

    // role => list of capabilities
    $rules = [
        'super_admin' => ['*'],
        'admin'       => ['*'],

        'billing' => [
            'customers.read','customers.write',
            'invoices.read','invoices.write','invoices.refund',
            'payments.read','payments.write',
            'products.read','products.write',
            'radius.read','radius.write','radius.disconnect',
            'reports.read','audit.read',
            'tickets.read','tickets.write',
            'outages.read',
            'inbox.read',
        ],

        'support' => [
            'customers.read','customers.write',
            'tickets.read','tickets.write',
            'outages.read','outages.close',
            'maintenance.read','maintenance.write',
            'invoices.read','payments.read','products.read',
            'sites.read','devices.read','sectors.read','links.read',
            'reports.read','audit.read',
            'inbox.read',
        ],

        'technician' => [
            'sites.read','sites.write',
            'devices.read','devices.write',
            'sectors.read','sectors.write',
            'links.read','links.write',
            'radio.push',
            'maintenance.read','maintenance.write',
            'outages.read','outages.close',
            'reports.read','audit.read',
            'inbox.read',
        ],

        'noc_readonly' => [
            'sites.read','devices.read','sectors.read','links.read',
            'outages.read','maintenance.read',
            'reports.read','audit.read',
            'tickets.read',
            'customers.read','invoices.read','payments.read',
            'inbox.read',
        ],

        'viewer' => [
            'sites.read','devices.read','sectors.read','links.read',
            'outages.read','maintenance.read',
            'reports.read',
            'inbox.read',
        ],

        // Customers don't go through acl_can — their portal is its own
        // area. Listed here so role-checks don't accidentally grant
        // them a staff capability.
        'client' => [],
    ];
    return $rules;
}

/**
 * Returns true if $user is allowed to perform $capability.
 *
 * Wildcard 'admin' / 'super_admin' match anything via the '*' marker.
 * Unknown roles deny by default.
 */
function acl_can(?array $user, string $capability): bool {
    if (!$user) return false;
    $role = (string)($user['role'] ?? '');
    if ($role === '') return false;
    $rules = acl_rules();
    if (!isset($rules[$role])) return false;
    if (in_array('*', $rules[$role], true)) return true;
    return in_array($capability, $rules[$role], true);
}

/**
 * Hard-stops the request with a 403 unless the current user has the
 * capability. Use on POST handlers that mutate data; reads usually
 * just hide the action UI rather than refusing the page entirely.
 */
function acl_require(string $capability, ?array $user = null): array {
    $user = $user ?? current_user();
    if (!$user) {
        http_response_code(403);
        die('Not signed in.');
    }
    if (!acl_can($user, $capability)) {
        audit_log('acl.deny', [
            'user_id' => (int)$user['id'],
            'meta'    => ['capability' => $capability, 'role' => $user['role'] ?? ''],
        ]);
        http_response_code(403);
        die('Your role (' . htmlspecialchars((string)($user['role'] ?? '?'))
          . ') does not allow ' . htmlspecialchars($capability) . '.');
    }
    return $user;
}

/**
 * Roles allowed into the admin portal at all. The sidebar layout uses
 * this to gate /admin/* — clients get bounced to /account/.
 */
function acl_staff_roles(): array {
    return ACL_STAFF_ROLES;
}

/**
 * Convenience for templates: hide a button when the current user can't
 * use it. Usage:
 *   <?php if (acl_show('invoices.write')): ?> <button>...</button> <?php endif; ?>
 */
function acl_show(string $capability): bool {
    return acl_can(current_user(), $capability);
}
