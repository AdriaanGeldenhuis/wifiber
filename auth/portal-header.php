<?php
/**
 * Shared layout for /admin and /account portals.
 *
 * Variables:
 *   $portal     'admin' | 'account'
 *   $page_title page title (string)
 *   $nav        array of [label, href, key] for sidebar (optional, only when logged in)
 *   $active_key string key marking active nav item
 *   $user       current user array (optional)
 */

// Buffer the entire layout. Most portal pages include _layout.php (which
// renders this header) BEFORE running their POST handler, and the handlers
// finish with header('Location: ...') + exit. Without buffering, the layout
// HTML has already been flushed by then and the redirect silently fails,
// leaving the user looking at the sidebar with a blank main area on save.
ob_start();

$portal     = $portal ?? 'account';
$page_title = $page_title ?? 'Portal';
$nav        = $nav ?? [];
$active_key = $active_key ?? '';
$user       = $user ?? null;
$is_admin   = $portal === 'admin';

// Brand override (set in /admin/settings.php). Falls back to defaults
// so a fresh install renders without site.json present.
$_site_settings = function_exists('load_site_settings') ? load_site_settings() : [];
$_brand         = $_site_settings['brand'] ?? [];
$_brand_logo    = !empty($_brand['logo_url']) ? (string)$_brand['logo_url'] : '/assets/images/header-logo-2x.webp';
$_brand_colour  = !empty($_brand['colour'])   ? strtolower((string)$_brand['colour']) : '';
// Derive the soft / glow tints from the chosen colour so the rest of
// the accent palette stays consistent with the override. Format the
// hex into "r, g, b" so we can build rgba() at runtime.
$_brand_rgb = '';
if ($_brand_colour && preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $_brand_colour, $m)) {
    $_brand_rgb = hexdec($m[1]) . ', ' . hexdec($m[2]) . ', ' . hexdec($m[3]);
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title><?= htmlspecialchars($page_title) ?> &middot; <?= $is_admin ? 'WiFIBER Admin' : 'WiFIBER Account' ?></title>
<link rel="icon" type="image/webp" href="/assets/images/logo-300.webp">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/portal.css">
<?php if ($_brand_rgb): ?>
<style>
  /* Brand override from data/site.json — keeps the dark theme but
     swaps the cyan accent for the configured colour. */
  :root {
    --accent:        <?= htmlspecialchars($_brand_colour) ?>;
    --accent-soft:   rgba(<?= $_brand_rgb ?>, 0.12);
    --accent-glow:   rgba(<?= $_brand_rgb ?>, 0.35);
  }
</style>
<?php endif; ?>
</head>
<body class="portal portal-<?= htmlspecialchars($portal) ?>">
<?php if ($user && !empty($nav)): ?>
<aside class="portal-side">
  <a href="/" class="portal-brand" title="Back to home">
    <img src="<?= htmlspecialchars($_brand_logo) ?>" alt="<?= htmlspecialchars($_site_settings['name'] ?? 'Brand') ?>">
    <span><?= $is_admin ? 'Admin' : 'Account' ?></span>
  </a>
  <nav class="portal-nav" aria-label="Portal navigation">
    <?php foreach ($nav as $entry): ?>
      <?php if (isset($entry['group'])): ?>
        <div class="portal-nav-group">
          <div class="portal-nav-group-label"><?= htmlspecialchars($entry['group']) ?></div>
          <?php foreach ($entry['items'] as $item):
            $cls = $item['key'] === $active_key ? ' is-active' : '';
          ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="portal-nav-item<?= $cls ?>"><?= htmlspecialchars($item['label']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php else:
        $cls = $entry['key'] === $active_key ? ' is-active' : '';
      ?>
        <a href="<?= htmlspecialchars($entry['href']) ?>" class="portal-nav-item<?= $cls ?>"><?= htmlspecialchars($entry['label']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php
  // In-app inbox bell — admin portal only. Reads unread count from
  // admin_inbox; clicking opens /admin/inbox.php for the full list.
  $_inbox_unread = 0;
  if ($is_admin && file_exists(__DIR__ . '/inbox.php')) {
      require_once __DIR__ . '/inbox.php';
      try { $_inbox_unread = inbox_unread_count($user); } catch (Throwable $e) { $_inbox_unread = 0; }
  }
  ?>
  <div class="portal-user">
    <div class="portal-user-name"><?= htmlspecialchars($user['name'] ?? $user['username'] ?? 'User') ?></div>
    <div class="portal-user-role"><?= htmlspecialchars($user['role'] ?? '') ?></div>
    <?php if ($is_admin): ?>
      <a href="/admin/inbox.php" class="portal-inbox-bell" title="Inbox<?= $_inbox_unread ? " ({$_inbox_unread} unread)" : '' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M12 2a1 1 0 0 1 1 1v.6a7 7 0 0 1 6 6.9V14l1.7 2.4a1 1 0 0 1-.8 1.6H4.1a1 1 0 0 1-.8-1.6L5 14v-3.5a7 7 0 0 1 6-6.9V3a1 1 0 0 1 1-1zm-2 17a2 2 0 0 0 4 0h-4z"/>
        </svg>
        Inbox
        <?php if ($_inbox_unread > 0): ?>
          <span class="portal-inbox-badge"><?= $_inbox_unread > 99 ? '99+' : (int)$_inbox_unread ?></span>
        <?php endif; ?>
      </a>
    <?php endif; ?>
    <a href="/<?= $portal ?>/logout.php" class="portal-logout">Log out</a>
  </div>
</aside>
<?php endif; ?>
<main class="portal-main<?= $user && $nav ? '' : ' portal-main-bare' ?>">
  <div class="portal-inner">
    <?= render_flash() ?>
