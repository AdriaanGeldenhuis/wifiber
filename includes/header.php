<?php
require_once __DIR__ . '/config.php';
$page_title = $page_title ?? $site['name'];
$page_desc  = $page_desc  ?? $site['tagline'];
$page_slug  = $page_slug  ?? '/';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
<title><?= htmlspecialchars($page_title) ?> &mdash; <?= htmlspecialchars($site['tagline']) ?></title>
<link rel="icon" type="image/webp" href="<?= asset('images/logo-300.webp') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body class="page-<?= htmlspecialchars(trim($page_slug, '/')) ?: 'home' ?>">
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
