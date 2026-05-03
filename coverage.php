<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/auth/coverage.php';

$page_title = 'Coverage Map';
$page_desc  = 'Check WiFIBER coverage in the Vaal Triangle &mdash; type your address and find out instantly.';
$page_slug  = '/coverage';

$cov     = coverage_load();
$address = trim((string)($_POST['address'] ?? $_GET['address'] ?? ''));
$check   = null;
$errors  = [];
$saved_lead = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/auth/helpers.php';
    require_csrf();
    $action = $_POST['action'] ?? 'check';

    if ($action === 'waitlist') {
        try {
            $lead_id = waitlist_create([
                'address' => $address,
                'name'    => $_POST['name']  ?? '',
                'email'   => $_POST['email'] ?? '',
                'phone'   => $_POST['phone'] ?? '',
                'notes'   => $_POST['notes'] ?? '',
            ]);
            notify_admin_of_waitlist_lead($lead_id);
            $saved_lead = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $check = coverage_check($address); // re-render the not-covered view
        }
    } else {
        $check = coverage_check($address);
    }
} elseif ($address !== '') {
    $check = coverage_check($address);
}

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <span class="eyebrow">Coverage</span>
    <h1>Are we in your area?</h1>
    <p><?= htmlspecialchars($cov['intro'] ?: $page_desc) ?></p>
  </div>
</section>

<section class="section" style="padding-top:30px;">
  <div class="container">
    <div class="coverage-check-card">
      <form method="post" class="coverage-check-form" action="/coverage">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="check">
        <label for="ccheck">Your address or town</label>
        <div class="coverage-check-row">
          <input type="text" id="ccheck" name="address" required maxlength="200"
                 value="<?= htmlspecialchars($address, ENT_QUOTES) ?>"
                 placeholder="e.g. 12 Main Street, Vanderbijlpark">
          <button type="submit" class="btn btn-primary">Check coverage</button>
        </div>
      </form>

      <?php if ($check && $check['matched']): ?>
        <div class="coverage-result coverage-result-yes">
          <strong>You're in coverage!</strong>
          We serve <em><?= htmlspecialchars($check['area']['name']) ?></em>
          <?php if ($check['matched_term'] !== $check['area']['name']): ?>
            (matched on <em><?= htmlspecialchars($check['matched_term']) ?></em>)
          <?php endif; ?>.
          Final hookup depends on line-of-sight to our nearest tower &mdash; book a free site survey below.
          <div class="coverage-result-cta">
            <a href="/pricing" class="btn btn-primary">See plans &amp; pricing</a>
            <a href="/#contact" class="btn btn-ghost">Book a site survey</a>
          </div>
        </div>
      <?php elseif ($check && !$check['matched']): ?>
        <div class="coverage-result coverage-result-no">
          <strong>We don't reach <?= htmlspecialchars($address) ?> yet.</strong>
          <?php if ($saved_lead): ?>
            <p style="margin-top:10px;">Thanks &mdash; you're on the waitlist. We'll be in touch the moment we light up your area.</p>
          <?php else: ?>
            <p>Drop your details below and we'll let you know the moment we light up your area.</p>
            <?php if ($errors): ?>
              <ul class="coverage-errors">
                <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
              </ul>
            <?php endif; ?>
            <form method="post" class="form coverage-waitlist-form" action="/coverage">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="waitlist">
              <input type="hidden" name="address" value="<?= htmlspecialchars($address, ENT_QUOTES) ?>">
              <div class="form-grid">
                <div class="field">
                  <label>Your name</label>
                  <input type="text" name="name" maxlength="100" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="field">
                  <label>Email</label>
                  <input type="email" name="email" maxlength="120" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="field">
                  <label>Phone</label>
                  <input type="tel" name="phone" maxlength="40" value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                  <label>Anything else? <span class="muted">(optional)</span></label>
                  <textarea name="notes" rows="2" maxlength="600"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
              </div>
              <button type="submit" class="btn btn-primary">Add me to the waitlist</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0;">
  <div class="container">
    <div class="coverage-wrap">
      <div class="coverage-map-img">
        <img src="<?= asset('images/coverage-map.png') ?>" alt="WiFIBER coverage map of the Vaal Triangle" loading="lazy">
      </div>
      <div>
        <h2 class="mt-0">Areas we serve</h2>
        <p>If you're in or near these areas, you're likely in range. Final coverage depends on line-of-sight to one of our towers.</p>
        <ul class="coverage-towns">
          <?php foreach ($cov['areas'] as $a): ?>
            <li><?= htmlspecialchars($a['name']) ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="/#contact" class="btn btn-primary">Book a site survey</a>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
