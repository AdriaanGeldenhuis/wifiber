<?php
/**
 * Client portal layout. See admin/_layout.php for the pattern.
 */
require_once __DIR__ . '/../auth/helpers.php';

$user = require_role('client', '/account/login.php');

$portal = 'account';
$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard',       'href' => '/account/'],
    ['key' => 'profile',   'label' => 'My profile',      'href' => '/account/profile.php'],
    ['key' => 'password',  'label' => 'Change password', 'href' => '/account/password.php'],
];

require __DIR__ . '/../auth/portal-header.php';
