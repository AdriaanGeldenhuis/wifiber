<?php
$page_title = 'Legal';
$page_desc  = 'WiFIBER legal documents &mdash; POPI policy, terms and conditions, code of conduct and cookie policy.';
$page_slug  = '/legal';
require __DIR__ . '/includes/header.php';
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
        <button type="button" class="active" data-legal="popi">POPI Policy</button>
        <button type="button" data-legal="terms">Terms &amp; Conditions</button>
        <button type="button" data-legal="conduct">Code of Conduct</button>
        <button type="button" data-legal="cookies">Cookie Policy</button>
      </nav>

      <div class="legal-content">

        <article class="legal-panel active" data-legal-panel="popi">
          <h2>Protection of Personal Information (POPI)</h2>
          <p>WiFIBER is committed to protecting your personal information in accordance with South Africa's Protection of Personal Information Act (POPIA). We will continuously ensure that we abide by the regulations of the POPI Act.</p>

          <h3>What we collect</h3>
          <p>We collect personal information necessary to provide our internet services, including your name, contact details, address, ID/registration numbers and payment information.</p>

          <h3>How we use it</h3>
          <p>To provision your service, manage your account, process payments, provide support, and communicate operational information about your service. We do not sell your personal information.</p>

          <h3>Sharing</h3>
          <p>We share information only with service providers who help us deliver our services (e.g. payment processors, infrastructure partners) and only as required by law.</p>

          <h3>Your rights</h3>
          <ul>
            <li>Access the personal information we hold about you</li>
            <li>Request correction of inaccurate information</li>
            <li>Request deletion, subject to legal and contractual retention requirements</li>
            <li>Opt out of marketing communications at any time</li>
          </ul>

          <h3>Retention &amp; security</h3>
          <p>We retain personal information only as long as necessary for the purposes for which it was collected, or as required by law. We use appropriate technical and organisational measures to keep your information secure.</p>

          <h3>Contact</h3>
          <p>For any privacy enquiries, contact us at <a href="mailto:admin@wifiber.co.za">admin@wifiber.co.za</a>.</p>
        </article>

        <article class="legal-panel" data-legal-panel="terms">
          <h2>Terms and Conditions</h2>
          <p>These terms govern your use of WiFIBER's wireless internet services. By signing up, you agree to them.</p>

          <h3>Service description</h3>
          <p>WiFIBER provides wireless broadband internet on an "as is" and "as available" basis. While we use top-tier equipment and multiple backup systems, we cannot guarantee uninterrupted service in all circumstances.</p>

          <h3>Customer obligations</h3>
          <ul>
            <li>Keep your account details up to date</li>
            <li>Pay subscription fees by the due date</li>
            <li>Use the service lawfully and in line with our fair-use and acceptable-use policies</li>
            <li>Allow reasonable access for installation and maintenance</li>
          </ul>

          <h3>Payment</h3>
          <p>Subscription fees are billed monthly in advance. Late payment may result in service suspension. Reconnection fees may apply.</p>

          <h3>Fair usage</h3>
          <p>All packages are uncapped and unshaped. We reserve the right to manage traffic on our network to maintain quality of service for all customers.</p>

          <h3>Suspension &amp; termination</h3>
          <p>We may suspend or terminate your service for non-payment, breach of these terms, or unlawful use. You may cancel a month-to-month service with 30 days' written notice; contract services are subject to the agreed term.</p>

          <h3>Liability</h3>
          <p>To the extent permitted by law, our liability is limited to the value of the affected service. We are not liable for indirect or consequential losses.</p>

          <h3>Changes</h3>
          <p>We may amend these terms from time to time. Material changes will be communicated to you in advance.</p>
        </article>

        <article class="legal-panel" data-legal-panel="conduct">
          <h2>Code of Conduct</h2>
          <p>Our commitments to customers, partners and the community.</p>

          <h3>Fair business practices</h3>
          <p>We deal honestly with our customers and partners. Pricing is transparent, contracts are clear, and we honour our commitments.</p>

          <h3>Service reliability</h3>
          <p>We invest in redundant infrastructure and proactive monitoring so that your connection stays up.</p>

          <h3>Customer support</h3>
          <p>Real people answer the phone. We aim to resolve most issues on the first call.</p>

          <h3>Privacy &amp; security</h3>
          <p>We protect your data with technical and organisational measures, and we never sell personal information.</p>

          <h3>Network management</h3>
          <p>We are transparent about how we manage our network. We do not block or throttle lawful content.</p>

          <h3>Lawful service use</h3>
          <p>We expect customers to use our services lawfully. We cooperate with authorities where required by law.</p>

          <h3>Employees</h3>
          <p>Our team is expected to uphold these standards in all dealings with customers and the public.</p>
        </article>

        <article class="legal-panel" data-legal-panel="cookies">
          <h2>Cookie Policy</h2>
          <p>This site uses cookies to make it work and to help us understand how it's used.</p>

          <h3>Types of cookies we use</h3>
          <ul>
            <li><strong>Essential cookies</strong> &mdash; needed for the site to function</li>
            <li><strong>Performance cookies</strong> &mdash; help us measure and improve the site</li>
            <li><strong>Functional cookies</strong> &mdash; remember your preferences</li>
            <li><strong>Advertising cookies</strong> &mdash; only if you opt in</li>
          </ul>

          <h3>Managing cookies</h3>
          <p>You can control cookies through your browser settings. Disabling some cookies may affect site functionality.</p>

          <h3>Third-party cookies</h3>
          <p>Some pages may include content from third parties (e.g. embedded maps or analytics) which set their own cookies. Their use is governed by their own policies.</p>

          <h3>Contact</h3>
          <p>Questions about cookies? Email <a href="mailto:admin@wifiber.co.za">admin@wifiber.co.za</a>.</p>
        </article>

      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
