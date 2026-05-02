<?php
http_response_code(404);
$page_title = 'Page not found';
$page_slug  = '/';
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero" style="padding-bottom:120px;">
  <div class="container">
    <span class="eyebrow">404</span>
    <h1>Lost the signal.</h1>
    <p>The page you were looking for isn't here. It may have moved or never existed.</p>
    <p style="margin-top:30px;"><a href="/" class="btn btn-primary">Back to home</a></p>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
