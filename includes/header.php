<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../auth/incidents.php';

$page_title    = $page_title ?? $site['name'];
$page_desc     = $page_desc  ?? $site['tagline'];
$page_slug     = $page_slug  ?? '/';
$active_alert  = incidents_active_top();

/* ---- Header config (admin-editable, /admin/header.php) ----
 * data/header.json drives the logo size, top utility bar, nav links,
 * account button and phone CTA. Sensible defaults below so the site
 * still renders if the file is missing or partially populated. */
$header_defaults = [
    'logo' => [
        'url' => '',
        'height' => 96,
        'padding_y' => 22,
        'wordmark_show' => true,
        'wordmark_text' => '',
    ],
    'top_bar' => [
        'enabled' => true,
        'status_enabled' => true,
        'status_label' => 'All systems operational',
        'status_link' => '/status',
        'status_color' => 'green',
        'items' => [],
    ],
    'nav_links' => [
        ['label' => 'Home',         'href' => '/'],
        ['label' => 'Pricing',      'href' => '/pricing'],
        ['label' => 'Coverage Map', 'href' => '/coverage'],
        ['label' => 'Legal',        'href' => '/legal'],
    ],
    'account' => ['enabled' => true, 'label' => 'My Account', 'href' => '/account/'],
    'cta'     => ['enabled' => true, 'label' => '', 'href' => '', 'show_pulse' => true],
];
$header_file = __DIR__ . '/../data/header.json';
$header_cfg  = $header_defaults;
if (is_file($header_file)) {
    $loaded_h = json_decode((string)@file_get_contents($header_file), true);
    if (is_array($loaded_h)) $header_cfg = array_replace_recursive($header_defaults, $loaded_h);
}
$logo_h     = max(32, min(220, (int)$header_cfg['logo']['height']));
$header_py  = max(0, min(80,  (int)$header_cfg['logo']['padding_y']));
$header_h   = $logo_h + ($header_py * 2);
$top_bar_h  = !empty($header_cfg['top_bar']['enabled']) ? 36 : 0;

if (!function_exists('header_icon_svg')) {
    function header_icon_svg(string $name, int $size = 13): string {
        $icons = [
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'pin'    => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'mail'   => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/>',
            'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'signal' => '<path d="M3 12a9 9 0 0 1 18 0"/><path d="M7 12a5 5 0 0 1 10 0"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/>',
            'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            'check'  => '<path d="M5 13l4 4L19 7"/>',
            'shield' => '<path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/>',
            'bolt'   => '<path d="M13 2L3 14h7v8l10-12h-7z"/>',
            'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>',
            'star'   => '<path d="M12 2l3 6 6 1-4.5 4.5L18 19l-6-3-6 3 1.5-6.5L3 8l6-1z"/>',
            'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        ];
        $path = $icons[$name] ?? $icons['info'];
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $path . '</svg>';
    }
}

/* Per-page SEO overrides + canonical URL.
 * Pull from site.json -> seo.pages.<slug-key>; falls back to whatever the
 * page set above. The slug key is the path with leading "/" stripped, or
 * "home" for "/". */
$seo            = $site['seo'] ?? [];
$slug_key       = trim($page_slug, '/') === '' ? 'home' : trim($page_slug, '/');
$page_seo       = $seo['pages'][$slug_key] ?? [];
if (!empty($page_seo['title']))       $page_title = (string)$page_seo['title'];
if (!empty($page_seo['description'])) $page_desc  = (string)$page_seo['description'];

$is_https       = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$canonical_base = ($is_https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
$canonical_url  = $canonical_base . $page_slug;
$og_image       = $seo['default_image'] ?? '/assets/images/og-default.png';
if ($og_image && $og_image[0] === '/') $og_image = $canonical_base . $og_image;
$twitter_handle = trim((string)($seo['twitter_handle'] ?? ''));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
<title><?= htmlspecialchars($page_title) ?> &mdash; <?= htmlspecialchars($site['tagline']) ?></title>
<link rel="canonical" href="<?= htmlspecialchars($canonical_url, ENT_QUOTES) ?>">
<link rel="icon" type="image/webp" href="<?= asset('images/logo-300.webp') ?>">

<!-- Open Graph -->
<meta property="og:title"       content="<?= htmlspecialchars($page_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($page_desc) ?>">
<meta property="og:type"        content="website">
<meta property="og:url"         content="<?= htmlspecialchars($canonical_url, ENT_QUOTES) ?>">
<meta property="og:site_name"   content="<?= htmlspecialchars($site['name']) ?>">
<?php if ($og_image): ?>
<meta property="og:image"       content="<?= htmlspecialchars($og_image, ENT_QUOTES) ?>">
<meta property="og:image:alt"   content="<?= htmlspecialchars($site['name']) ?> — <?= htmlspecialchars($site['tagline']) ?>">
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= htmlspecialchars($page_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($page_desc) ?>">
<?php if ($og_image): ?>
<meta name="twitter:image"       content="<?= htmlspecialchars($og_image, ENT_QUOTES) ?>">
<?php endif; ?>
<?php if ($twitter_handle): ?>
<meta name="twitter:site"        content="<?= htmlspecialchars($twitter_handle, ENT_QUOTES) ?>">
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">

<?php /* Schema.org LocalBusiness — only on the homepage so search engines aren't fed
         duplicate copies on every page. */ ?>
<?php if ($page_slug === '/'):
  $geo = $seo['schema_geo'] ?? [];
  $hours = trim((string)($seo['schema_opening_hours'] ?? ''));
  $jsonld = [
    '@context' => 'https://schema.org',
    '@type'    => 'LocalBusiness',
    'name'     => $site['name'],
    'description' => $site['tagline'],
    'url'      => $canonical_base . '/',
    'telephone'=> $site['phone'] ?? '',
    'email'    => $site['email_admin'] ?? '',
    'image'    => $og_image,
    'address'  => [
      '@type'           => 'PostalAddress',
      'streetAddress'   => $site['address_line1'] ?? '',
      'addressLocality' => trim(strtok((string)($site['address_line2'] ?? ''), ',')),
      'postalCode'      => trim((string)substr((string)($site['address_line2'] ?? ''), strpos(($site['address_line2'] ?? ''), ',') + 1)),
      'addressCountry'  => 'ZA',
    ],
  ];
  if (!empty($geo['lat']) && !empty($geo['lng'])) {
    $jsonld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$geo['lat'], 'longitude' => (float)$geo['lng']];
  }
  if ($hours !== '') $jsonld['openingHours'] = $hours;
  $jsonld['areaServed'] = 'Vaal Triangle, Gauteng & Free State, South Africa';
?>
<script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
</head>
<body class="page-<?= htmlspecialchars(trim($page_slug, '/')) ?: 'home' ?>" style="--header-logo-h: <?= $logo_h ?>px; --header-pad-y: <?= $header_py ?>px; --header-h: <?= $header_h ?>px; --top-bar-h: <?= $top_bar_h ?>px;">
<?php if ($active_alert): ?>
  <a href="/status" class="incident-banner <?= htmlspecialchars(incident_severity_class((string)$active_alert['severity'])) ?>">
    <span class="incident-banner-dot"></span>
    <strong><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$active_alert['status']] ?? $active_alert['status']) ?>:</strong>
    <span class="incident-banner-title"><?= htmlspecialchars($active_alert['title']) ?></span>
    <span class="incident-banner-link">View status &rarr;</span>
  </a>
<?php endif; ?>
<?php if (!empty($header_cfg['top_bar']['enabled'])):
  $tb = $header_cfg['top_bar'];
  $status_color_class = in_array(($tb['status_color'] ?? 'green'), ['green','amber','red','cyan'], true) ? $tb['status_color'] : 'green';
?>
<div class="top-bar">
  <div class="container top-bar-inner">
    <?php if (!empty($tb['status_enabled'])): ?>
      <a href="<?= htmlspecialchars($tb['status_link'] ?: '#', ENT_QUOTES) ?>" class="top-bar-status status-<?= $status_color_class ?>">
        <span class="top-bar-dot"></span>
        <span><?= htmlspecialchars((string)($tb['status_label'] ?? '')) ?></span>
      </a>
    <?php else: ?>
      <span></span>
    <?php endif; ?>
    <?php if (!empty($tb['items'])): ?>
      <ul class="top-bar-meta">
        <?php foreach ($tb['items'] as $item):
          $text = (string)($item['text'] ?? '');
          $link = (string)($item['link'] ?? '');
          $icon = (string)($item['icon'] ?? 'info');
          if ($text === '') continue;
        ?>
          <li>
            <?php if ($link !== ''): ?>
              <a href="<?= htmlspecialchars($link, ENT_QUOTES) ?>">
                <?= header_icon_svg($icon) ?>
                <span><?= htmlspecialchars($text) ?></span>
              </a>
            <?php else: ?>
              <?= header_icon_svg($icon) ?>
              <span><?= htmlspecialchars($text) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<header class="site-header">
  <div class="header-rule" aria-hidden="true"></div>
  <div class="container header-inner">
    <a href="/" class="logo" aria-label="<?= htmlspecialchars($site['name']) ?> home">
      <?php
        $brand_logo_url = !empty($header_cfg['logo']['url'])
          ? $header_cfg['logo']['url']
          : (!empty($site['brand']['logo_url']) ? $site['brand']['logo_url'] : asset('images/header-logo-2x.webp'));
        $wordmark = trim((string)($header_cfg['logo']['wordmark_text'] ?? '')) !== ''
          ? (string)$header_cfg['logo']['wordmark_text']
          : (string)$site['name'];
      ?>
      <img src="<?= htmlspecialchars($brand_logo_url) ?>" alt="<?= htmlspecialchars($site['name']) ?> logo">
      <?php if (!empty($header_cfg['logo']['wordmark_show'])): ?>
        <span class="logo-text"><?= htmlspecialchars($wordmark) ?></span>
      <?php endif; ?>
    </a>
    <button class="nav-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </button>
    <nav id="main-nav" class="main-nav" aria-label="Primary">
      <?php foreach (($header_cfg['nav_links'] ?? []) as $nl):
        $label = trim((string)($nl['label'] ?? ''));
        $href  = trim((string)($nl['href']  ?? ''));
        if ($label === '' || $href === '') continue;
        echo nav_link($href, $label, $page_slug);
      endforeach; ?>
      <?php if (!empty($header_cfg['account']['enabled'])):
        $acct_label = trim((string)$header_cfg['account']['label']) !== '' ? $header_cfg['account']['label'] : 'My Account';
        $acct_href  = trim((string)$header_cfg['account']['href'])  !== '' ? $header_cfg['account']['href']  : '/account/';
      ?>
        <a href="<?= htmlspecialchars($acct_href, ENT_QUOTES) ?>" class="portal-link">
          <?= header_icon_svg('user', 15) ?>
          <span><?= htmlspecialchars($acct_label) ?></span>
        </a>
      <?php endif; ?>
      <?php if (!empty($header_cfg['cta']['enabled'])):
        $cta_label = trim((string)$header_cfg['cta']['label']) !== '' ? $header_cfg['cta']['label'] : (string)$site['phone'];
        $cta_href  = trim((string)$header_cfg['cta']['href'])  !== '' ? $header_cfg['cta']['href']  : 'tel:' . $site['phone_link'];
      ?>
        <a href="<?= htmlspecialchars($cta_href, ENT_QUOTES) ?>" class="btn btn-primary nav-cta">
          <?php if (!empty($header_cfg['cta']['show_pulse'])): ?><span class="nav-cta-pulse" aria-hidden="true"></span><?php endif; ?>
          <?= header_icon_svg('phone', 15) ?>
          <span><?= htmlspecialchars($cta_label) ?></span>
        </a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main id="content">
