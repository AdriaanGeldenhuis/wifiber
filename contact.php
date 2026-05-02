<?php
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /#contact');
    exit;
}

// Honeypot — silently drop bots that fill the hidden field
if (!empty($_POST['website'] ?? '')) {
    header('Location: /?sent=1');
    exit;
}

$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$phone   = trim($_POST['phone']   ?? '');
$address = trim($_POST['address'] ?? '');
$message = trim($_POST['message'] ?? '');
$subject = trim($_POST['subject'] ?? 'Website enquiry');

$errors = [];
if ($name === '' || mb_strlen($name) > 100)            $errors[] = 'Please enter your name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = 'Please enter a valid email address.';
if ($message === '' && $address === '')                 $errors[] = 'Please tell us how we can help.';
if (mb_strlen($message) > 2000)                         $errors[] = 'Message is too long.';

if ($errors) {
    $page_title = 'Message not sent';
    $page_slug = '/';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container" style="max-width:600px;">';
    echo '<div class="alert alert-error"><strong>We could not send your message:</strong><ul>';
    foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
    echo '</ul></div><p><a href="/#contact" class="btn btn-ghost">&larr; Back to the form</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$to      = $site['email_admin'];
$body    = "New message from the WiFIBER website\n\n"
         . "Name:    {$name}\n"
         . "Email:   {$email}\n"
         . ($phone   ? "Phone:   {$phone}\n"   : '')
         . ($address ? "Address: {$address}\n" : '')
         . "\n----------------------------------------\n"
         . ($message ?: '(no additional message)') . "\n";

$headers = "From: WiFIBER website <no-reply@wifiber.co.za>\r\n"
         . "Reply-To: {$name} <{$email}>\r\n"
         . "X-Mailer: WiFIBER-Site\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($to, '[Website] ' . $subject, $body, $headers);

$page_title = $sent ? 'Message sent' : 'Message not sent';
$page_slug  = '/';
require __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container" style="max-width:600px; text-align:center;">
    <?php if ($sent): ?>
      <span class="eyebrow">Thanks!</span>
      <h1>Message sent.</h1>
      <p>We've got it &mdash; we'll be in touch shortly. In the meantime feel free to give us a call on <a href="tel:<?= $site['phone_link'] ?>"><?= htmlspecialchars($site['phone']) ?></a>.</p>
      <p><a href="/" class="btn btn-primary">Back to home</a></p>
    <?php else: ?>
      <div class="alert alert-error">We couldn't send your message right now. Please email <a href="mailto:<?= htmlspecialchars($site['email_admin']) ?>"><?= htmlspecialchars($site['email_admin']) ?></a> directly or call <a href="tel:<?= $site['phone_link'] ?>"><?= htmlspecialchars($site['phone']) ?></a>.</div>
      <p><a href="/#contact" class="btn btn-ghost">&larr; Try again</a></p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
