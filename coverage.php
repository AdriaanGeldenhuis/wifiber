<?php
$page_title = 'Coverage Map';
$page_desc  = 'Check WiFIBER coverage in the Vaal Triangle &mdash; Vanderbijlpark, Vereeniging, Sasolburg and surrounding areas.';
$page_slug  = '/coverage';
require __DIR__ . '/includes/header.php';

$towns = [
  'Vanderbijlpark', 'Vereeniging', 'Sasolburg', 'Three Rivers',
  'Meyerton', 'Heidelberg', 'Sebokeng', 'Evaton',
  'Roshnee', 'Boipatong', 'Bophelong', 'Sharpeville',
];
?>

<section class="page-hero">
  <div class="container">
    <span class="eyebrow">Coverage</span>
    <h1>Where we cover.</h1>
    <p>WiFIBER serves the Vaal Triangle and surrounding areas. Not sure if your address is in range? Book a free site survey &mdash; we'll check line-of-sight and confirm.</p>
  </div>
</section>

<section class="section" style="padding-top:30px;">
  <div class="container">
    <div class="coverage-wrap">
      <div class="coverage-map-img">
        <img src="<?= asset('images/coverage-map.png') ?>" alt="WiFIBER coverage map of the Vaal Triangle" loading="lazy">
      </div>
      <div>
        <h2 class="mt-0">Areas we serve</h2>
        <p>If you're in or near these areas, you're likely in range. Final coverage depends on line-of-sight to one of our towers.</p>
        <ul class="coverage-towns">
          <?php foreach ($towns as $t): ?>
            <li><?= htmlspecialchars($t) ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="/#contact" class="btn btn-primary">Book a site survey</a>
      </div>
    </div>
  </div>
</section>

<section class="section section-tight" style="padding-top:0;">
  <div class="container">
    <div class="contact-grid">
      <div class="contact-info">
        <h3>Not sure if you're covered?</h3>
        <p>Wireless internet needs <strong>line-of-sight</strong> between your roof and our nearest tower. Trees, buildings and hills can block the signal &mdash; the only way to know for sure is a quick site visit.</p>
        <p>It's free, takes about 20 minutes, and doesn't commit you to anything.</p>
        <ul>
          <li><span>Phone</span><a href="tel:<?= $site['phone_link'] ?>"><?= htmlspecialchars($site['phone']) ?></a></li>
          <li><span>Email</span><a href="mailto:<?= htmlspecialchars($site['email_admin']) ?>"><?= htmlspecialchars($site['email_admin']) ?></a></li>
        </ul>
      </div>
      <form class="form" action="/contact.php" method="post">
        <div class="field">
          <label for="cname">Your name</label>
          <input type="text" id="cname" name="name" required maxlength="100">
        </div>
        <div class="field">
          <label for="cemail">Email</label>
          <input type="email" id="cemail" name="email" required maxlength="120">
        </div>
        <div class="field">
          <label for="caddress">Your address</label>
          <input type="text" id="caddress" name="address" required maxlength="200" placeholder="Street, suburb, town">
        </div>
        <div class="field">
          <label for="cmessage">Anything else? (optional)</label>
          <textarea id="cmessage" name="message" maxlength="2000"></textarea>
        </div>
        <input type="hidden" name="subject" value="Site survey request">
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
        <button type="submit" class="btn btn-primary btn-block">Request site survey</button>
      </form>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
