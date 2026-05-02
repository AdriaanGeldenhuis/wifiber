<?php
$page_title = 'Pricing';
$page_desc  = 'Uncapped, unshaped wireless internet packages from R199/m. Home, business and gaming tiers with the lowest contention in the Vaal.';
$page_slug  = '/pricing';
require __DIR__ . '/includes/header.php';

$tiers = [
  'home' => [
    'name'      => 'Home',
    'contention'=> '5:1',
    'tagline'   => 'Great everyday connectivity for streaming, browsing and remote work.',
    'plans' => [
      [2,  1,   199],
      [4,  2,   299],
      [6,  3,   459],
      [8,  4,   559],
      [10, 5,   679, true],
      [15, 7.5, 959],
      [20, 10,  1399],
      [40, 20,  2799],
    ],
  ],
  'business' => [
    'name'      => 'Business',
    'contention'=> '2:1',
    'tagline'   => 'Lower contention for offices, shops and serious work-from-home setups.',
    'plans' => [
      [2,  2,   479],
      [4,  4,   599],
      [6,  6,   799],
      [8,  8,   999],
      [10, 10,  1299, true],
      [15, 15,  1799],
      [20, 20,  2499],
      [40, 40,  3899],
    ],
  ],
  'gaming' => [
    'name'      => 'Gaming',
    'contention'=> '1:1',
    'tagline'   => 'Unshared 1:1 bandwidth. Built for gamers, streamers and latency-sensitive workloads.',
    'plans' => [
      [2,  2,   599],
      [4,  4,   749],
      [6,  6,   949],
      [8,  8,   1149],
      [10, 10,  1499, true],
      [15, 15,  1999],
      [20, 20,  2799],
      [40, 40,  4499],
    ],
  ],
];
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
          <?php foreach ($tier['plans'] as $plan):
            [$down, $up, $price] = $plan;
            $featured = !empty($plan[3]);
          ?>
            <div class="price-card<?= $featured ? ' featured' : '' ?>">
              <div class="price-speed"><?= $down ?> <small>Mbps</small></div>
              <div class="price-up"><?= $up ?> Mbps upload</div>
              <div class="price-cost">R<?= number_format($price, 0, '.', ',') ?> <small>/ month</small></div>
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
        <h4>24-Month Contract</h4>
        <p><strong>Free</strong> wireless installation included.</p>
      </div>
      <div class="install-card">
        <h4>Month-to-Month</h4>
        <p>Once-off installation fee of <strong>R2,799.00</strong>.</p>
      </div>
      <div class="install-card">
        <h4>What you get</h4>
        <p>Wireless link, professional install and ongoing support &mdash; all included.</p>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
