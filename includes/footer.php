</main>
<footer class="site-footer">
  <div class="footer-rule" aria-hidden="true"></div>
  <div class="footer-glow" aria-hidden="true"></div>

  <div class="container">
    <div class="footer-cta">
      <div class="footer-cta-text">
        <span class="footer-cta-eyebrow">Ready to switch?</span>
        <h3>Find out if we cover your area in seconds.</h3>
      </div>
      <a href="/coverage" class="btn btn-primary footer-cta-btn">
        Check coverage
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
  </div>

  <div class="container footer-grid">
    <div class="footer-brand">
      <?php $brand_logo_url = !empty($site['brand']['logo_url']) ? $site['brand']['logo_url'] : asset('images/footer-logo-2x.webp'); ?>
      <img src="<?= htmlspecialchars($brand_logo_url) ?>" alt="<?= htmlspecialchars($site['name']) ?>" class="footer-logo">
      <p class="footer-mission">Wireless internet for the Vaal Triangle. Built on top-tier equipment, backed by people who actually answer the phone.</p>
      <a href="/status" class="footer-status">
        <span class="footer-status-dot"></span>
        <span>All systems operational</span>
      </a>
      <div class="socials">
        <a href="<?= htmlspecialchars($site['social']['facebook']) ?>" aria-label="Facebook">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M13 22v-8h3l1-4h-4V7.5c0-1.1.3-1.9 2-1.9h2V2.1C16.7 2 15.7 2 14.6 2 12 2 10 3.7 10 6.9V10H7v4h3v8h3z"/></svg>
        </a>
        <a href="<?= htmlspecialchars($site['social']['linkedin']) ?>" aria-label="LinkedIn">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4.98 3.5C4.98 4.88 3.87 6 2.5 6S0 4.88 0 3.5 1.12 1 2.5 1s2.48 1.12 2.48 2.5zM.22 8h4.56v14H.22V8zm7.4 0h4.37v1.92h.06c.61-1.15 2.1-2.36 4.32-2.36 4.62 0 5.47 3.04 5.47 7v7.44h-4.55v-6.6c0-1.57-.03-3.6-2.2-3.6-2.2 0-2.54 1.72-2.54 3.49V22H7.62V8z"/></svg>
        </a>
        <a href="<?= htmlspecialchars($site['social']['youtube']) ?>" aria-label="YouTube">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>
        </a>
      </div>
    </div>

    <div class="footer-col">
      <h4>Explore</h4>
      <ul class="footer-links">
        <li><a href="/">Home</a></li>
        <li><a href="/pricing">Packages</a></li>
        <li><a href="/coverage">Coverage map</a></li>
        <li><a href="/status">Network status</a></li>
        <li><a href="/legal">Legal</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Contact</h4>
      <ul class="footer-contact">
        <li>
          <span class="footer-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          </span>
          <span class="footer-line">
            <span class="footer-label">Phone</span>
            <a href="tel:<?= $site['phone_link'] ?>"><?= htmlspecialchars($site['phone']) ?></a>
          </span>
        </li>
        <li>
          <span class="footer-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>
          </span>
          <span class="footer-line">
            <span class="footer-label">Admin</span>
            <a href="mailto:<?= htmlspecialchars($site['email_admin']) ?>"><?= htmlspecialchars($site['email_admin']) ?></a>
          </span>
        </li>
        <li>
          <span class="footer-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>
          </span>
          <span class="footer-line">
            <span class="footer-label">Accounts</span>
            <a href="mailto:<?= htmlspecialchars($site['email_accounts']) ?>"><?= htmlspecialchars($site['email_accounts']) ?></a>
          </span>
        </li>
        <li>
          <span class="footer-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          </span>
          <span class="footer-line">
            <span class="footer-label">Support</span>
            <a href="mailto:<?= htmlspecialchars($site['email_support']) ?>"><?= htmlspecialchars($site['email_support']) ?></a>
          </span>
        </li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Visit</h4>
      <address class="footer-address">
        <span class="footer-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        </span>
        <span>
          <?= htmlspecialchars($site['address_line1']) ?><br>
          <?= htmlspecialchars($site['address_line2']) ?><br>
          South Africa
        </span>
      </address>
      <div class="footer-hours">
        <span class="footer-label">Office hours</span>
        <span>Mon&ndash;Fri &middot; 08:00&ndash;17:00</span>
        <span class="footer-label" style="margin-top:8px;">Support</span>
        <span><span class="footer-pulse"></span>24/7 &middot; 365 days</span>
      </div>
    </div>
  </div>

  <div class="container footer-bottom">
    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($site['name']) ?>.co.za &mdash; All rights reserved.</p>
    <ul class="footer-meta">
      <li>POPIA compliant</li>
      <li>1:1 contention</li>
      <li>Uncapped &amp; unshaped</li>
      <li>Vaal Triangle ISP</li>
    </ul>
  </div>
</footer>
<script src="<?= asset('js/main.js') ?>" defer></script>
<script src="<?= asset('js/portal.js') ?>" defer></script>
<?php if (($site['analytics']['provider'] ?? 'none') !== 'none'): ?>
  <script src="<?= asset('js/analytics.php') ?>" defer></script>
<?php endif; ?>
</body>
</html>
