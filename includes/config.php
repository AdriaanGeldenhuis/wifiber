<?php
$site = [
    'name'    => 'WiFIBER',
    'tagline' => 'Speed That Connects, Reliability That Lasts',
    'phone'   => '0800 111 222',
    'phone_link' => '0800111222',
    'email_admin'   => 'admin@wifiber.co.za',
    'email_accounts' => 'accounts@wifiber.co.za',
    'email_support' => 'support@wifiber.co.za',
    'address_line1' => '180 Mullersteine',
    'address_line2' => 'Vanderbijlpark, 1912',
    'social' => [
        'facebook' => '#',
        'linkedin' => '#',
        'youtube'  => '#',
    ],
];

function nav_link(string $href, string $label, string $current): string {
    $active = $current === $href ? ' aria-current="page"' : '';
    return '<a href="' . htmlspecialchars($href) . '"' . $active . '>' . htmlspecialchars($label) . '</a>';
}

function asset(string $path): string {
    return '/assets/' . ltrim($path, '/');
}
