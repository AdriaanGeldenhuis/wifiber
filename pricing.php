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
?>

<section class="page-hero">
  <div class="container">
    <span class="eyebrow">Pricing</span>
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
          <?= htmlspecialchars($tier['name']) ?>
        </button>
      <?php $first = false; endforeach; ?>
    </div>

    <?php $first = true; foreach ($tiers as $key => $tier): ?>
      <div class="tier-panel<?= $first ? ' active' : '' ?>" data-panel="<?= htmlspecialchars($key) ?>" role="tabpanel">
        <p class="tier-blurb"><strong><?= htmlspecialchars($tier['contention']) ?> contention</strong> &mdash; <?= htmlspecialchars($tier['tagline']) ?></p>
        <div class="price-grid">
          <?php foreach (($tier['plans'] ?? []) as $plan):
            $down     = $plan['down']     ?? 0;
            $up       = $plan['up']       ?? 0;
            $price    = $plan['price']    ?? 0;
            $featured = !empty($plan['featured']);
          ?>
            <div class="price-card<?= $featured ? ' featured' : '' ?>">
              <div class="price-speed"><?= htmlspecialchars((string)$down) ?> <small>Mbps</small></div>
              <div class="price-up"><?= htmlspecialchars((string)$up) ?> Mbps upload</div>
              <div class="price-cost">R<?= number_format((float)$price, 0, '.', ',') ?> <small>/ month</small></div>
              <ul class="price-features">
                <li>Uncapped data</li>
                <li>Unshaped &mdash; no throttling</li>
                <li><?= htmlspecialchars($tier['contention']) ?> contention ratio</li>
                <li>24/7 local support</li>
              </ul>
              <a href="/#contact" class="btn btn-ghost btn-block">Get this plan</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php $first = false; endforeach; ?>

    <div class="install-info">
      <div class="install-card">
        <h4><?= htmlspecialchars($install_24mo_label) ?></h4>
        <p><?= $install_24mo_value ?></p>
      </div>
      <div class="install-card">
        <h4><?= htmlspecialchars($install_mtm_label) ?></h4>
        <p><?= $install_mtm_value ?></p>
      </div>
      <div class="install-card">
        <h4>What you get</h4>
        <p>Wireless link, professional install and ongoing support &mdash; all included.</p>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
