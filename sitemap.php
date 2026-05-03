<?php
/**
 * Auto-generated sitemap.xml. Routed via .htaccess so /sitemap.xml hits
 * this file. Lists the public pages plus the latest unresolved/recently
 * resolved incidents so search engines can discover the /status page when
 * something noteworthy happened.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=UTF-8');

$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
$today = date('Y-m-d');

$pages = [
    ['/',          '1.0', 'weekly'],
    ['/pricing',   '0.9', 'weekly'],
    ['/coverage',  '0.8', 'monthly'],
    ['/legal',     '0.4', 'yearly'],
    ['/status',    '0.7', 'daily'],
    ['/contact',   '0.5', 'monthly'],
];

// If /contact.php doesn't exist as a routable page, drop it.
if (!is_file(__DIR__ . '/contact.php')) {
    $pages = array_values(array_filter($pages, fn($p) => $p[0] !== '/contact'));
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$path, $priority, $changefreq]) {
    $url = htmlspecialchars($base . $path, ENT_QUOTES);
    echo "  <url>\n";
    echo "    <loc>{$url}</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>{$changefreq}</changefreq>\n";
    echo "    <priority>{$priority}</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
