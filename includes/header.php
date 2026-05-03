<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../auth/incidents.php';

$page_title    = $page_title ?? $site['name'];
$page_desc     = $page_desc  ?? $site['tagline'];
$page_slug     = $page_slug  ?? '/';
$active_alert  = incidents_active_top();

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
<body class="page-<?= htmlspecialchars(trim($page_slug, '/')) ?: 'home' ?>">
<?php if ($active_alert): ?>
  <a href="/status" class="incident-banner <?= htmlspecialchars(incident_severity_class((string)$active_alert['severity'])) ?>">
    <span class="incident-banner-dot"></span>
    <strong><?= htmlspecialchars(INCIDENT_STATUS_LABELS[$active_alert['status']] ?? $active_alert['status']) ?>:</strong>
    <span class="incident-banner-title"><?= htmlspecialchars($active_alert['title']) ?></span>
    <span class="incident-banner-link">View status &rarr;</span>
  </a>
<?php endif; ?>
<header class="site-header">
  <div class="container header-inner">
    <a href="/" class="logo" aria-label="<?= htmlspecialchars($site['name']) ?> home">
      <img src="<?= asset('images/header-logo-2x.webp') ?>" alt="<?= htmlspecialchars($site['name']) ?> logo">
      <span class="logo-text"><?= htmlspecialchars($site['name']) ?></span>
    </a>
    <button class="nav-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </button>
    <nav id="main-nav" class="main-nav" aria-label="Primary">
      <?= nav_link('/', 'Home', $page_slug) ?>
      <?= nav_link('/pricing', 'Pricing', $page_slug) ?>
      <?= nav_link('/coverage', 'Coverage Map', $page_slug) ?>
      <?= nav_link('/legal', 'Legal', $page_slug) ?>
      <a href="/account/" class="portal-link">My Account</a>
      <a href="tel:<?= $site['phone_link'] ?>" class="btn btn-primary nav-cta"><?= htmlspecialchars($site['phone']) ?></a>
    </nav>
  </div>
</header>
<main id="content">
