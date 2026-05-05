<?php
$page_title = 'Pricing';
$page_desc  = 'Uncapped, unshaped wireless internet packages from R199/m. Home, business and gaming tiers with the lowest contention in the Vaal.';
$page_slug  = '/pricing';
require __DIR__ . '/includes/header.php';

$pricing_file = __DIR__ . '/data/pricing.json';
$pricing = is_file($pricing_file) ? (json_decode((string)@file_get_contents($pricing_file), true) ?: []) : [];
$tiers   = $pricing['tiers'] ?? [];
$install_24mo_label = $pricing['install_24mo_label'] ?? '24-Month Contract';
$install_24mo_value = $pricing['install_24mo_value'] ?? 'Free wireless installation included.';
$install_mtm_label  = $pricing['install_mtm_label']  ?? 'Month-to-Month';
$install_mtm_value  = $pricing['install_mtm_value']  ?? 'Once-off installation fee of R2,799.00.';

$tier_icons = [
    'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 21v-6h6v6"/>',
    'business' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
    'gaming'   => '<rect x="2" y="7" width="20" height="10" rx="5"/><path d="M7 12h3M8.5 10.5v3"/><circle cx="15" cy="11" r="1" fill="currentColor"/><circle cx="17" cy="13" r="1" fill="currentColor"/>',
];
$tier_icon = function (string $key) use ($tier_icons): string {
    $svg = $tier_icons[$key] ?? '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>';
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $svg . '</svg>';
};
?>

<section class="page-hero pricing-hero">
  <div class="container">
    <span class="eyebrow">
      <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Pricing
    </span>
    <h1>Simple, uncapped pricing.</h1>
    <p>All packages are uncapped and unshaped. Pick the contention ratio that matches how you use the internet &mdash; switch tiers below.</p>
  </div>
</section>

<section class="section" style="padding-top:30px;">
  <div class="container">
    <div class="pricing-controls" role="tablist" aria-label="Pricing tiers">
      <?php $first = true; foreach ($tiers as $key => $tier): ?>
        <button type="button"
                class="tier-btn<?= $first ? ' active' : '' ?>"
                role="tab"
                data-tier="<?= htmlspecialchars($key) ?>"
                aria-selected="<?= $first ? 'true' : 'false' ?>">
          <?= $tier_icon($key) ?>
          <span><?= htmlspecialchars($tier['name']) ?></span>
        </button>
      <?php $first = false; endforeach; ?>
    </div>

    <?php $first = true; foreach ($tiers as $key => $tier): ?>
      <div class="tier-panel<?= $first ? ' active' : '' ?>" data-panel="<?= htmlspecialchars($key) ?>" role="tabpanel">
        <p class="tier-blurb">
          <span class="contention-pill">
            <span class="contention-dot"></span>
            <strong><?= htmlspecialchars($tier['contention']) ?></strong> contention
          </span>
          <?= htmlspecialchars($tier['tagline']) ?>
        </p>
        <div class="price-grid">
          <?php foreach (($tier['plans'] ?? []) as $plan):
            $down     = $plan['down']     ?? 0;
            $up       = $plan['up']       ?? 0;
            $price    = $plan['price']    ?? 0;
            $featured = !empty($plan['featured']);
          ?>
            <div class="price-card<?= $featured ? ' featured' : '' ?>">
              <span class="price-corner tr" aria-hidden="true"></span>
              <span class="price-corner bl" aria-hidden="true"></span>
              <div class="price-head">
                <div class="price-speed"><?= htmlspecialchars((string)$down) ?><small>Mbps</small></div>
                <div class="price-up">
                  <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                  <?= htmlspecialchars((string)$up) ?> Mbps upload
                </div>
              </div>
              <div class="price-cost-wrap">
                <div class="price-cost-currency">R</div>
                <div class="price-cost"><?= number_format((float)$price, 0, '.', ',') ?></div>
                <div class="price-cost-period">/ month</div>
              </div>
              <ul class="price-features">
                <li><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>Uncapped data</li>
                <li><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>Unshaped &mdash; no throttling</li>
                <li><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg><?= htmlspecialchars($tier['contention']) ?> contention ratio</li>
                <li><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>24/7 local support</li>
              </ul>
              <a href="/#contact" class="btn <?= $featured ? 'btn-primary' : 'btn-ghost' ?> btn-block">
                Get this plan
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php $first = false; endforeach; ?>

    <div class="install-info">
      <div class="install-card">
        <span class="install-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/></svg>
        </span>
        <h4><?= htmlspecialchars($install_24mo_label) ?></h4>
        <p><?= $install_24mo_value ?></p>
      </div>
      <div class="install-card">
        <span class="install-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </span>
        <h4><?= htmlspecialchars($install_mtm_label) ?></h4>
        <p><?= $install_mtm_value ?></p>
      </div>
      <div class="install-card">
        <span class="install-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 6 6 1-4.5 4.5L18 19l-6-3-6 3 1.5-6.5L3 8l6-1z"/></svg>
        </span>
        <h4>What you get</h4>
        <p>Wireless link, professional install and ongoing support &mdash; all included.</p>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
