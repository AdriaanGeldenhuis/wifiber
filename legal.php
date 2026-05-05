<?php
$page_title = 'Legal';
$page_desc  = 'WiFIBER legal documents &mdash; POPI policy, terms and conditions, code of conduct and cookie policy.';
$page_slug  = '/legal';
require __DIR__ . '/includes/header.php';

$legal_file = __DIR__ . '/data/legal.json';
$legal      = is_file($legal_file) ? (json_decode((string)@file_get_contents($legal_file), true) ?: []) : [];
$sections   = $legal['sections'] ?? [];

$legal_icons = [
    'popi'    => '<path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/>',
    'privacy' => '<path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/>',
    'terms'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>',
    'tcs'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>',
    'conduct' => '<path d="M12 2l3 6 6 1-4.5 4.5L18 19l-6-3-6 3 1.5-6.5L3 8l6-1z"/>',
    'ethics'  => '<path d="M12 2l3 6 6 1-4.5 4.5L18 19l-6-3-6 3 1.5-6.5L3 8l6-1z"/>',
    'cookies' => '<path d="M21.5 12.5A9.5 9.5 0 1 1 12 2.5"/><path d="M21 5l1 1M16 8a3 3 0 0 1 3 3"/><circle cx="8" cy="11" r="1" fill="currentColor"/><circle cx="13" cy="13" r="1" fill="currentColor"/><circle cx="11" cy="17" r="1" fill="currentColor"/>',
    'aup'     => '<path d="M3 12a9 9 0 0 1 18 0"/><path d="M7 12a5 5 0 0 1 10 0"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/>',
];
$legal_icon = function (string $key) use ($legal_icons): string {
    $svg = $legal_icons[$key] ?? '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>';
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $svg . '</svg>';
};
?>

<section class="page-hero">
  <div class="container">
    <span class="eyebrow">
      <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/></svg>
      Legal
    </span>
    <h1>Policies &amp; Terms</h1>
    <p>The legal stuff &mdash; how we handle your data, what you can expect from us, and how we expect to do business.</p>
  </div>
</section>

<section class="section" style="padding-top:30px;">
  <div class="container">
    <div class="legal-layout">
      <nav class="legal-nav" aria-label="Legal sections">
        <span class="legal-nav-label">Documents</span>
        <?php foreach ($sections as $i => $s): ?>
          <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-legal="<?= htmlspecialchars($s['key']) ?>">
            <span class="legal-nav-icon"><?= $legal_icon((string)$s['key']) ?></span>
            <span class="legal-nav-text"><?= htmlspecialchars($s['label'] ?? $s['title']) ?></span>
            <svg class="legal-nav-arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
          </button>
        <?php endforeach; ?>
      </nav>

      <div class="legal-content">
        <span class="legal-content-corner tr" aria-hidden="true"></span>
        <span class="legal-content-corner bl" aria-hidden="true"></span>
        <?php foreach ($sections as $i => $s): ?>
          <article class="legal-panel <?= $i === 0 ? 'active' : '' ?>" data-legal-panel="<?= htmlspecialchars($s['key']) ?>">
            <span class="legal-panel-tag">
              <?= $legal_icon((string)$s['key']) ?>
              <?= htmlspecialchars($s['label'] ?? $s['title']) ?>
            </span>
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <div class="legal-panel-body">
              <?= $s['content'] ?? '' ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
