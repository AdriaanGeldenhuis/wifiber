<?php
$page_title = 'WiFIBER';
$page_desc  = 'Wireless internet for the Vaal Triangle. Fast, secure and seamless connectivity for home and business.';
$page_slug  = '/';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
  <div class="container hero-grid">
    <div class="hero-text">
      <span class="eyebrow">Vaal Triangle WISP</span>
      <h1>Speed That Connects.<br><span class="accent">Reliability That Lasts.</span></h1>
      <p class="lead">
        Whether for your home or business, we deliver fast, secure and seamless internet
        you can trust &mdash; built on top-tier networking equipment with multiple backup systems.
      </p>
      <div class="hero-cta">
        <a href="/coverage" class="btn btn-primary">View Coverage Map</a>
        <a href="/pricing" class="btn btn-ghost">See Pricing</a>
      </div>
    </div>
    <div class="hero-art">
      <img src="<?= asset('images/logo.webp') ?>" alt="WiFIBER" loading="eager" width="480" height="480">
    </div>
  </div>
</section>

<section class="section-tight">
  <div class="container">
    <div class="stat-strip">
      <div class="stat"><span class="num">1:1</span><span class="label">Lowest contention</span></div>
      <div class="stat"><span class="num">24/7</span><span class="label">Local support</span></div>
      <div class="stat"><span class="num">100%</span><span class="label">Uncapped & unshaped</span></div>
      <div class="stat"><span class="num">0</span><span class="label">Hidden fees</span></div>
    </div>
  </div>
</section>

<section class="section" id="why">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Why WiFIBER</span>
      <h2>Built for the Vaal, by people who actually live here.</h2>
      <p>Five reasons our customers stay connected with us &mdash; and why new ones keep switching.</p>
    </div>
    <div class="feature-grid">
      <div class="feature">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 18 0"/><path d="M7 12a5 5 0 0 1 10 0"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></svg>
        </div>
        <h3>Fast, helpful support</h3>
        <p>Real humans answering the phone. Most issues resolved on the first call.</p>
      </div>
      <div class="feature">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
        </div>
        <h3>Quick installations</h3>
        <p>Hassle-free setup, usually within days &mdash; not weeks.</p>
      </div>
      <div class="feature">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        </div>
        <h3>Lowest contention in the Vaal</h3>
        <p>Unshared bandwidth on premium tiers. The speed you pay for is the speed you get.</p>
      </div>
      <div class="feature">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v6M12 22v-6M4.93 4.93l4.24 4.24M19.07 19.07l-4.24-4.24M2 12h6M22 12h-6M4.93 19.07l4.24-4.24M19.07 4.93l-4.24 4.24"/></svg>
        </div>
        <h3>Multiple backup systems</h3>
        <p>Redundant power, links and gear so your connection stays up when others go down.</p>
      </div>
      <div class="feature">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h.01M11 10h.01M15 10h.01"/></svg>
        </div>
        <h3>Top-tier equipment</h3>
        <p>Ubiquiti, MikroTik and enterprise-grade hardware end to end.</p>
      </div>
      <div class="feature">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1l3 6 6 1-4.5 4.5L18 19l-6-3-6 3 1.5-6.5L3 8l6-1z"/></svg>
        </div>
        <h3>Years of experience</h3>
        <p>WISP, software and network expertise &mdash; all under one roof.</p>
      </div>
    </div>
  </div>
</section>

<section class="partners">
  <div class="container">
    <p class="partners-label">Powered by industry-leading partners</p>
    <div class="partner-logos">
      <img src="<?= asset('images/partner-ubiquiti.webp') ?>" alt="Ubiquiti" loading="lazy">
      <img src="<?= asset('images/partner-mikrotik.webp') ?>" alt="MikroTik" loading="lazy">
      <img src="<?= asset('images/partner-scoop.webp') ?>" alt="Scoop" loading="lazy">
      <img src="<?= asset('images/partner-teraco.webp') ?>" alt="Teraco" loading="lazy">
    </div>
  </div>
</section>

<section class="section" id="contact">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Get in touch</span>
      <h2>Let's get you connected.</h2>
      <p>Drop us a message and we'll come back to you with availability and pricing for your address.</p>
    </div>
    <div class="contact-grid">
      <div class="contact-info">
        <h3>Reach out directly</h3>
        <ul>
          <li><span>Phone</span><a href="tel:<?= $site['phone_link'] ?>"><?= htmlspecialchars($site['phone']) ?></a></li>
          <li><span>Admin</span><a href="mailto:<?= htmlspecialchars($site['email_admin']) ?>"><?= htmlspecialchars($site['email_admin']) ?></a></li>
          <li><span>Accounts</span><a href="mailto:<?= htmlspecialchars($site['email_accounts']) ?>"><?= htmlspecialchars($site['email_accounts']) ?></a></li>
          <li><span>Support</span><a href="mailto:<?= htmlspecialchars($site['email_support']) ?>"><?= htmlspecialchars($site['email_support']) ?></a></li>
          <li><span>Address</span><?= htmlspecialchars($site['address_line1']) ?>, <?= htmlspecialchars($site['address_line2']) ?></li>
        </ul>
      </div>
      <form class="form" action="/contact.php" method="post" id="contactForm">
        <div id="formAlert"></div>
        <div class="field">
          <label for="name">Your name</label>
          <input type="text" id="name" name="name" required maxlength="100">
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required maxlength="120">
        </div>
        <div class="field">
          <label for="phone">Phone (optional)</label>
          <input type="tel" id="phone" name="phone" maxlength="30">
        </div>
        <div class="field">
          <label for="message">How can we help?</label>
          <textarea id="message" name="message" required maxlength="2000"></textarea>
        </div>
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
        <button type="submit" class="btn btn-primary btn-block">Send message</button>
      </form>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
