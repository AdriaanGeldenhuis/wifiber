</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-brand">
      <img src="<?= asset('images/footer-logo-2x.webp') ?>" alt="<?= htmlspecialchars($site['name']) ?>" class="footer-logo">
      <p class="footer-mission">Wireless internet for the Vaal Triangle. Built on top-tier equipment, backed by people who actually answer the phone.</p>
      <div class="socials">
        <a href="<?= htmlspecialchars($site['social']['facebook']) ?>" aria-label="Facebook">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M13 22v-8h3l1-4h-4V7.5c0-1.1.3-1.9 2-1.9h2V2.1C16.7 2 15.7 2 14.6 2 12 2 10 3.7 10 6.9V10H7v4h3v8h3z"/></svg>
        </a>
        <a href="<?= htmlspecialchars($site['social']['linkedin']) ?>" aria-label="LinkedIn">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M4.98 3.5C4.98 4.88 3.87 6 2.5 6S0 4.88 0 3.5 1.12 1 2.5 1s2.48 1.12 2.48 2.5zM.22 8h4.56v14H.22V8zm7.4 0h4.37v1.92h.06c.61-1.15 2.1-2.36 4.32-2.36 4.62 0 5.47 3.04 5.47 7v7.44h-4.55v-6.6c0-1.57-.03-3.6-2.2-3.6-2.2 0-2.54 1.72-2.54 3.49V22H7.62V8z"/></svg>
        </a>
        <a href="<?= htmlspecialchars($site['social']['youtube']) ?>" aria-label="YouTube">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>
        </a>
      </div>
    </div>

    <div>
      <h4>Navigate</h4>
      <ul class="footer-links">
        <li><a href="/">Home</a></li>
        <li><a href="/pricing">Pricing</a></li>
        <li><a href="/coverage">Coverage Map</a></li>
        <li><a href="/legal">Legal</a></li>
      </ul>
    </div>

    <div>
      <h4>Contact</h4>
      <ul class="footer-contact">
        <li><strong>Phone:</strong> <a href="tel:<?= $site['phone_link'] ?>"><?= htmlspecialchars($site['phone']) ?></a></li>
        <li><strong>Admin:</strong> <a href="mailto:<?= htmlspecialchars($site['email_admin']) ?>"><?= htmlspecialchars($site['email_admin']) ?></a></li>
        <li><strong>Accounts:</strong> <a href="mailto:<?= htmlspecialchars($site['email_accounts']) ?>"><?= htmlspecialchars($site['email_accounts']) ?></a></li>
        <li><strong>Support:</strong> <a href="mailto:<?= htmlspecialchars($site['email_support']) ?>"><?= htmlspecialchars($site['email_support']) ?></a></li>
      </ul>
    </div>

    <div>
      <h4>Visit</h4>
      <address>
        <?= htmlspecialchars($site['address_line1']) ?><br>
        <?= htmlspecialchars($site['address_line2']) ?><br>
        South Africa
      </address>
    </div>
  </div>
  <div class="container footer-bottom">
    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($site['name']) ?>.co.za &mdash; All rights reserved.</p>
  </div>
</footer>
<script src="<?= asset('js/main.js') ?>" defer></script>
<script src="<?= asset('js/portal.js') ?>" defer></script>
</body>
</html>
