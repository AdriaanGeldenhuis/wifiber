<?php
/**
 * Client portal layout. See admin/_layout.php for the pattern.
 */
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/outages.php';

$user = require_role('client', '/account/login.php');

$portal = 'account';
$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard',       'href' => '/account/'],
    ['key' => 'profile',   'label' => 'My profile',      'href' => '/account/profile.php'],
    ['key' => 'invoices',  'label' => 'Invoices',        'href' => '/account/invoices.php'],
    ['key' => 'tickets',   'label' => 'Support tickets', 'href' => '/account/tickets.php'],
    ['key' => 'password',  'label' => 'Change password', 'href' => '/account/password.php'],
];

require __DIR__ . '/../auth/portal-header.php';

// Outage banner — if this customer is attached to a sector that has
// an active outage, surface it on every account page so they know
// the ISP is on it (and don't open a ticket about it).
$_account_outage = !empty($user['sector_id'])
    ? outage_active('sector', (int)$user['sector_id'])
    : null;
if ($_account_outage):
    $_started = strtotime((string)$_account_outage['started_at']);
    $_age     = $_started ? max(0, time() - $_started) : 0;
    if      ($_age < 60)    $_dur = $_age . 's';
    elseif  ($_age < 3600)  $_dur = floor($_age / 60)   . 'm';
    elseif  ($_age < 86400) $_dur = floor($_age / 3600) . 'h ' . floor(($_age % 3600) / 60) . 'm';
    else                    $_dur = floor($_age / 86400) . 'd ' . floor(($_age % 86400) / 3600) . 'h';
?>
<div class="alert alert-warning">
  <strong>We're aware of an outage affecting your area.</strong>
  Our team is already working on it &mdash; you don't need to log a ticket.
  <span class="alert-meta">
    Started <?= htmlspecialchars((string)$_account_outage['started_at']) ?>
    &middot; ongoing for <?= htmlspecialchars($_dur) ?>
  </span>
</div>
<?php endif; ?>

