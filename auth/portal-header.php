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
$portal     = $portal ?? 'account';
$page_title = $page_title ?? 'Portal';
$nav        = $nav ?? [];
$active_key = $active_key ?? '';
$user       = $user ?? null;
$is_admin   = $portal === 'admin';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($page_title) ?> &middot; <?= $is_admin ? 'WiFIBER Admin' : 'WiFIBER Account' ?></title>
<link rel="icon" type="image/webp" href="/assets/images/logo-300.webp">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body class="portal portal-<?= htmlspecialchars($portal) ?>">
<?php if ($user && !empty($nav)): ?>
<aside class="portal-side">
  <a href="/" class="portal-brand" title="Back to wifiber.co.za">
    <img src="/assets/images/header-logo-2x.webp" alt="WiFIBER">
    <span><?= $is_admin ? 'Admin' : 'Account' ?></span>
  </a>
  <nav class="portal-nav" aria-label="Portal navigation">
    <?php foreach ($nav as $item):
      $cls = $item['key'] === $active_key ? ' is-active' : '';
    ?>
      <a href="<?= htmlspecialchars($item['href']) ?>" class="portal-nav-item<?= $cls ?>"><?= htmlspecialchars($item['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="portal-user">
    <div class="portal-user-name"><?= htmlspecialchars($user['name'] ?? $user['username'] ?? 'User') ?></div>
    <div class="portal-user-role"><?= htmlspecialchars($user['role'] ?? '') ?></div>
    <a href="/<?= $portal ?>/logout.php" class="portal-logout">Log out</a>
  </div>
</aside>
<?php endif; ?>
<main class="portal-main<?= $user && $nav ? '' : ' portal-main-bare' ?>">
  <div class="portal-inner">
    <?= render_flash() ?>
