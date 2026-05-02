<?php
/**
 * Site configuration loader.
 *
 * The actual values live in /data/site.json and are editable from
 * /admin/settings.php. This file just loads them and exposes the
 * $site array used by the templates.
 */

$site_defaults = [
    'name'           => 'WiFIBER',
    'tagline'        => 'Speed That Connects, Reliability That Lasts',
    'phone'          => '0800 111 222',
    'phone_link'     => '0800111222',
    'email_admin'    => 'admin@wifiber.co.za',
    'email_accounts' => 'accounts@wifiber.co.za',
    'email_support'  => 'support@wifiber.co.za',
    'address_line1'  => '180 Mullersteine',
    'address_line2'  => 'Vanderbijlpark, 1912',
    'social'         => ['facebook' => '#', 'linkedin' => '#', 'youtube' => '#'],
];

$site_file = __DIR__ . '/../data/site.json';
$site      = $site_defaults;
if (is_file($site_file)) {
    $loaded = json_decode((string)@file_get_contents($site_file), true);
    if (is_array($loaded)) {
        $site = array_replace_recursive($site_defaults, $loaded);
    }
}

function nav_link(string $href, string $label, string $current): string {
    $active = $current === $href ? ' aria-current="page"' : '';
    return '<a href="' . htmlspecialchars($href) . '"' . $active . '>' . htmlspecialchars($label) . '</a>';
}

function asset(string $path): string {
    return '/assets/' . ltrim($path, '/');
}
