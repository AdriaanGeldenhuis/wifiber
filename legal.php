<?php
$page_title = 'Legal';
$page_desc  = 'WiFIBER legal documents &mdash; POPI policy, terms and conditions, code of conduct and cookie policy.';
$page_slug  = '/legal';
require __DIR__ . '/includes/header.php';

$legal_file = __DIR__ . '/data/legal.json';
$legal      = is_file($legal_file) ? (json_decode((string)@file_get_contents($legal_file), true) ?: []) : [];
$sections   = $legal['sections'] ?? [];
?>

<section class="page-hero">
  <div class="container">
    <span class="eyebrow">Legal</span>
    <h1>Policies &amp; Terms</h1>
    <p>The legal stuff &mdash; how we handle your data, what you can expect from us, and how we expect to do business.</p>
  </div>
</section>

<section class="section" style="padding-top:30px;">
  <div class="container">
    <div class="legal-layout">
      <nav class="legal-nav" aria-label="Legal sections">
        <?php foreach ($sections as $i => $s): ?>
          <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-legal="<?= htmlspecialchars($s['key']) ?>"><?= htmlspecialchars($s['label'] ?? $s['title']) ?></button>
        <?php endforeach; ?>
      </nav>

      <div class="legal-content">
        <?php foreach ($sections as $i => $s): ?>
          <article class="legal-panel <?= $i === 0 ? 'active' : '' ?>" data-legal-panel="<?= htmlspecialchars($s['key']) ?>">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <?= $s['content'] ?? '' ?>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
