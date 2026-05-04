<?php
$page_title = 'Site settings';
$active_key = 'settings';
require __DIR__ . '/_layout.php';

$file = __DIR__ . '/../data/site.json';
$data = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    acl_require('settings.write');

    // Logo upload — saved under /assets/uploads/branding/ with a stable
    // filename so the public site doesn't need to know which extension
    // the admin chose. Existing logo URL is preserved when no upload.
    $brand_logo_url   = trim((string)($_POST['brand_logo_url'] ?? ($data['brand']['logo_url'] ?? '')));
    if (!empty($_FILES['brand_logo_file']['tmp_name']) && is_uploaded_file($_FILES['brand_logo_file']['tmp_name'])) {
        $f    = $_FILES['brand_logo_file'];
        $size = (int)$f['size'];
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            $errors[] = 'Logo must be under 2 MB.';
        } else {
            $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '';
            $ext_by_mime = [
                'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
            ];
            $ext = $ext_by_mime[$mime] ?? null;
            if (!$ext) {
                $errors[] = 'Logo must be PNG, JPG, WEBP or SVG.';
            } else {
                $dir = __DIR__ . '/../assets/uploads/branding';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $dest_name = 'logo-' . date('YmdHis') . '.' . $ext;
                $dest_path = $dir . '/' . $dest_name;
                if (@move_uploaded_file($f['tmp_name'], $dest_path)) {
                    @chmod($dest_path, 0644);
                    $brand_logo_url = '/assets/uploads/branding/' . $dest_name;
                } else {
                    $errors[] = 'Could not save the uploaded logo.';
                }
            }
        }
    }

    // Brand colour — store as #RRGGBB, used as --accent override.
    $brand_colour = strtolower(trim((string)($_POST['brand_colour'] ?? '')));
    if ($brand_colour !== '' && !preg_match('/^#[0-9a-f]{6}$/', $brand_colour)) {
        $errors[] = 'Brand colour must be a #RRGGBB hex value.';
        $brand_colour = '';
    }

    $new = [
        'name'           => trim($_POST['name']           ?? ''),
        'tagline'        => trim($_POST['tagline']        ?? ''),
        'brand'          => [
            'logo_url' => $brand_logo_url,
            'colour'   => $brand_colour ?: ($data['brand']['colour'] ?? ''),
        ],
        'phone'          => trim($_POST['phone']          ?? ''),
        'phone_link'     => preg_replace('/[^0-9+]/', '', $_POST['phone_link'] ?? ''),
        'email_admin'    => trim($_POST['email_admin']    ?? ''),
        'email_accounts' => trim($_POST['email_accounts'] ?? ''),
        'email_support'  => trim($_POST['email_support']  ?? ''),
        'address_line1'  => trim($_POST['address_line1']  ?? ''),
        'address_line2'  => trim($_POST['address_line2']  ?? ''),
        'social' => [
            'facebook' => trim($_POST['social_facebook'] ?? '#'),
            'linkedin' => trim($_POST['social_linkedin'] ?? '#'),
            'youtube'  => trim($_POST['social_youtube']  ?? '#'),
        ],
        'billing' => [
            'vat_rate'              => is_numeric($_POST['vat_rate'] ?? null) ? 0 + $_POST['vat_rate'] : 15,
            'currency_symbol'       => trim($_POST['currency_symbol']       ?? 'R '),
            'payment_terms_days'    => max(1, (int)($_POST['payment_terms_days']  ?? 7)),
            'bank_name'             => trim($_POST['bank_name']             ?? ''),
            'bank_account_holder'   => trim($_POST['bank_account_holder']   ?? ''),
            'bank_account_number'   => trim($_POST['bank_account_number']   ?? ''),
            'bank_branch_code'      => trim($_POST['bank_branch_code']      ?? ''),
            'bank_reference_format' => trim($_POST['bank_reference_format'] ?? '{number}'),
            'payment_instructions'  => trim($_POST['payment_instructions']  ?? ''),
        ],
        'seo' => [
            'default_image'        => trim((string)($_POST['seo_default_image']  ?? '')),
            'twitter_handle'       => trim((string)($_POST['seo_twitter_handle'] ?? '')),
            'schema_geo'           => [
                'lat' => is_numeric($_POST['schema_geo_lat'] ?? null) ? 0 + $_POST['schema_geo_lat'] : null,
                'lng' => is_numeric($_POST['schema_geo_lng'] ?? null) ? 0 + $_POST['schema_geo_lng'] : null,
            ],
            'schema_opening_hours' => trim((string)($_POST['schema_opening_hours'] ?? '')),
            'pages'                => [],
        ],
        'analytics' => [
            'provider'         => in_array($_POST['analytics_provider'] ?? '', ['none','plausible','google'], true) ? $_POST['analytics_provider'] : 'none',
            'plausible_domain' => trim((string)($_POST['plausible_domain'] ?? '')),
            'google_id'        => preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['google_id'] ?? '')),
        ],
    ];
    foreach (['home','pricing','coverage','legal','contact','status'] as $slug) {
        $title = trim((string)($_POST['seo_pages'][$slug]['title']       ?? ''));
        $desc  = trim((string)($_POST['seo_pages'][$slug]['description'] ?? ''));
        if ($title !== '' || $desc !== '') {
            $new['seo']['pages'][$slug] = array_filter([
                'title'       => $title,
                'description' => $desc,
            ], fn($v) => $v !== '');
        }
    }

    if ($new['name']    === '') $errors[] = 'Site name cannot be empty.';
    if ($new['tagline'] === '') $errors[] = 'Tagline cannot be empty.';
    foreach (['email_admin', 'email_accounts', 'email_support'] as $f) {
        if ($new[$f] !== '' && !filter_var($new[$f], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' is not a valid email.';
        }
    }

    if (!$errors) {
        if (json_save($file, $new)) {
            flash('success', 'Site settings saved.');
        } else {
            flash('error', 'Could not write data/site.json. Check permissions.');
        }
        header('Location: /admin/settings.php');
        exit;
    }
    $data = $new;
}

$v = function (string $key, $default = '') use ($data) {
    return htmlspecialchars((string)($data[$key] ?? $default), ENT_QUOTES);
};
$social = $data['social'] ?? [];
?>

<div class="portal-head">
  <h1>Site settings</h1>
  <p class="portal-sub">Edit the contact details, brand info and social links shown on the public site. Changes go live immediately on save.</p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul style="margin:0;padding-left:18px;">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
  </ul></div>
<?php endif; ?>

<form method="post" class="form" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <?php
    $brand        = $data['brand'] ?? [];
    $brand_logo   = $brand['logo_url'] ?? '';
    $brand_colour = $brand['colour']   ?? '';
  ?>

  <div class="portal-card">
    <h2>Brand</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Site name</label>
        <input type="text" name="name" required maxlength="60" value="<?= $v('name') ?>">
      </div>
      <div class="field">
        <label>Tagline</label>
        <input type="text" name="tagline" required maxlength="120" value="<?= $v('tagline') ?>">
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label>Logo</label>
        <?php if ($brand_logo): ?>
          <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;padding:10px 14px;background:var(--bg-elev);border:1px solid var(--border);border-radius:var(--radius-sm);">
            <img src="<?= htmlspecialchars($brand_logo) ?>" alt="Current logo" style="height:48px;width:auto;background:#0008;padding:4px;border-radius:6px;">
            <small class="muted"><?= htmlspecialchars($brand_logo) ?></small>
          </div>
        <?php endif; ?>
        <input type="file" name="brand_logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        <small class="muted">PNG, JPG, WEBP or SVG. Max 2 MB.</small>
      </div>
      <div class="field">
        <label>Or paste a logo URL</label>
        <input type="text" name="brand_logo_url" maxlength="500" value="<?= htmlspecialchars($brand_logo, ENT_QUOTES) ?>" placeholder="/assets/images/logo.webp">
      </div>
      <div class="field">
        <label>Brand colour <small class="muted">(accent on portals)</small></label>
        <div style="display:flex;gap:8px;align-items:center;">
          <input type="color" name="brand_colour" value="<?= htmlspecialchars($brand_colour ?: '#05dafd', ENT_QUOTES) ?>" style="height:40px;width:60px;padding:2px;cursor:pointer;">
          <input type="text" id="brand_colour_text" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" value="<?= htmlspecialchars($brand_colour ?: '#05dafd', ENT_QUOTES) ?>" style="flex:1;font-family:ui-monospace,monospace;" placeholder="#05dafd">
        </div>
        <small class="muted">Sets <code>--accent</code> for the admin and client portals.</small>
      </div>
    </div>
    <script>
    // Two-way bind the colour swatch to the hex text field.
    (function () {
      var c = document.querySelector('input[name="brand_colour"]');
      var t = document.getElementById('brand_colour_text');
      if (!c || !t) return;
      c.addEventListener('input', function () { t.value = c.value; });
      t.addEventListener('input',  function () {
        if (/^#[0-9a-fA-F]{6}$/.test(t.value)) c.value = t.value;
      });
    })();
    </script>
  </div>

  <div class="portal-card">
    <h2>Contact</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Phone (display)</label>
        <input type="text" name="phone" maxlength="40" value="<?= $v('phone') ?>">
      </div>
      <div class="field">
        <label>Phone (dial link)</label>
        <input type="text" name="phone_link" maxlength="20" value="<?= $v('phone_link') ?>" placeholder="0800111222">
        <small class="muted">Digits only &mdash; used for the click-to-dial link.</small>
      </div>
      <div class="field">
        <label>Admin email</label>
        <input type="email" name="email_admin" maxlength="120" value="<?= $v('email_admin') ?>">
      </div>
      <div class="field">
        <label>Accounts email</label>
        <input type="email" name="email_accounts" maxlength="120" value="<?= $v('email_accounts') ?>">
      </div>
      <div class="field">
        <label>Support email</label>
        <input type="email" name="email_support" maxlength="120" value="<?= $v('email_support') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Address</h2>
    <div class="form form-grid">
      <div class="field">
        <label>Address line 1</label>
        <input type="text" name="address_line1" maxlength="120" value="<?= $v('address_line1') ?>">
      </div>
      <div class="field">
        <label>Address line 2</label>
        <input type="text" name="address_line2" maxlength="120" value="<?= $v('address_line2') ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Social links</h2>
    <p class="muted">Use <code>#</code> to hide the icon, or paste a full URL.</p>
    <div class="form form-grid">
      <div class="field">
        <label>Facebook</label>
        <input type="text" name="social_facebook" maxlength="200" value="<?= htmlspecialchars($social['facebook'] ?? '#', ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>LinkedIn</label>
        <input type="text" name="social_linkedin" maxlength="200" value="<?= htmlspecialchars($social['linkedin'] ?? '#', ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>YouTube</label>
        <input type="text" name="social_youtube" maxlength="200" value="<?= htmlspecialchars($social['youtube'] ?? '#', ENT_QUOTES) ?>">
      </div>
    </div>
  </div>

  <?php $billing = $data['billing'] ?? []; ?>
  <div class="portal-card">
    <h2>Billing</h2>
    <p class="muted">Used on invoices and the monthly auto-billing cron. Treat the
      <code>/pricing</code> prices as VAT-inclusive — the system back-calculates the
      ex-VAT line price when generating subscription invoices.</p>
    <div class="form form-grid">
      <div class="field">
        <label>VAT rate (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="vat_rate" value="<?= htmlspecialchars((string)($billing['vat_rate'] ?? 15), ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Currency symbol</label>
        <input type="text" name="currency_symbol" maxlength="6" value="<?= htmlspecialchars((string)($billing['currency_symbol'] ?? 'R '), ENT_QUOTES) ?>" placeholder="R ">
      </div>
      <div class="field">
        <label>Payment terms (days)</label>
        <input type="number" min="1" max="120" name="payment_terms_days" value="<?= htmlspecialchars((string)($billing['payment_terms_days'] ?? 7), ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Bank name</label>
        <input type="text" name="bank_name" maxlength="80" value="<?= htmlspecialchars((string)($billing['bank_name'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Account holder</label>
        <input type="text" name="bank_account_holder" maxlength="120" value="<?= htmlspecialchars((string)($billing['bank_account_holder'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Account number</label>
        <input type="text" name="bank_account_number" maxlength="40" value="<?= htmlspecialchars((string)($billing['bank_account_number'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Branch code</label>
        <input type="text" name="bank_branch_code" maxlength="20" value="<?= htmlspecialchars((string)($billing['bank_branch_code'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Payment reference format</label>
        <input type="text" name="bank_reference_format" maxlength="60" value="<?= htmlspecialchars((string)($billing['bank_reference_format'] ?? '{number}'), ENT_QUOTES) ?>" placeholder="{number}">
        <small class="muted">Placeholders: <code>{number}</code>, <code>{username}</code>, <code>{id}</code>.</small>
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label>Extra payment instructions</label>
        <textarea name="payment_instructions" rows="3" maxlength="600"><?= htmlspecialchars((string)($billing['payment_instructions'] ?? ''), ENT_QUOTES) ?></textarea>
        <small class="muted">Shown at the bottom of the invoice email.</small>
      </div>
    </div>
  </div>

  <?php $seo = $data['seo'] ?? []; $seo_pages = $seo['pages'] ?? []; $geo = $seo['schema_geo'] ?? []; ?>
  <div class="portal-card">
    <h2>SEO</h2>
    <p class="muted">Per-page meta titles &amp; descriptions, Open-Graph + Twitter share image, Schema.org LocalBusiness data.</p>
    <div class="form form-grid">
      <div class="field">
        <label>Default share image (URL or /assets path)</label>
        <input type="text" name="seo_default_image" maxlength="200" value="<?= htmlspecialchars((string)($seo['default_image'] ?? ''), ENT_QUOTES) ?>" placeholder="/assets/images/og-default.png">
      </div>
      <div class="field">
        <label>Twitter handle</label>
        <input type="text" name="seo_twitter_handle" maxlength="40" value="<?= htmlspecialchars((string)($seo['twitter_handle'] ?? ''), ENT_QUOTES) ?>" placeholder="@wifiber">
      </div>
      <div class="field">
        <label>Office latitude</label>
        <input type="text" name="schema_geo_lat" maxlength="20" value="<?= htmlspecialchars((string)($geo['lat'] ?? ''), ENT_QUOTES) ?>" placeholder="-26.7058">
      </div>
      <div class="field">
        <label>Office longitude</label>
        <input type="text" name="schema_geo_lng" maxlength="20" value="<?= htmlspecialchars((string)($geo['lng'] ?? ''), ENT_QUOTES) ?>" placeholder="27.8388">
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label>Opening hours (Schema.org format)</label>
        <input type="text" name="schema_opening_hours" maxlength="120" value="<?= htmlspecialchars((string)($seo['schema_opening_hours'] ?? ''), ENT_QUOTES) ?>" placeholder="Mo-Fr 08:00-17:00, Sa 09:00-13:00">
      </div>
    </div>

    <h3 style="margin-top:24px;color:var(--text);font-size:1rem;">Per-page overrides</h3>
    <p class="muted small">Leave blank to fall back to the default the page itself sets.</p>
    <table class="data-table">
      <thead>
        <tr><th style="width:120px;">Page</th><th>Title</th><th>Description</th></tr>
      </thead>
      <tbody>
        <?php foreach (['home'=>'/','pricing'=>'/pricing','coverage'=>'/coverage','legal'=>'/legal','contact'=>'/contact','status'=>'/status'] as $slug => $path):
          $pp = $seo_pages[$slug] ?? [];
        ?>
          <tr>
            <td><code><?= htmlspecialchars($path) ?></code></td>
            <td><input type="text" name="seo_pages[<?= $slug ?>][title]"       maxlength="120" value="<?= htmlspecialchars((string)($pp['title']       ?? ''), ENT_QUOTES) ?>"></td>
            <td><input type="text" name="seo_pages[<?= $slug ?>][description]" maxlength="200" value="<?= htmlspecialchars((string)($pp['description'] ?? ''), ENT_QUOTES) ?>"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php $an = $data['analytics'] ?? ['provider'=>'none']; ?>
  <div class="portal-card">
    <h2>Analytics</h2>
    <p class="muted">Pick one provider, or "None" to skip. Analytics scripts are only injected on public pages — never the admin or account portals.</p>
    <div class="form form-grid">
      <div class="field">
        <label>Provider</label>
        <select name="analytics_provider">
          <option value="none"      <?= ($an['provider'] ?? '') === 'none'      ? 'selected' : '' ?>>None</option>
          <option value="plausible" <?= ($an['provider'] ?? '') === 'plausible' ? 'selected' : '' ?>>Plausible (privacy-friendly, recommended)</option>
          <option value="google"    <?= ($an['provider'] ?? '') === 'google'    ? 'selected' : '' ?>>Google Analytics 4</option>
        </select>
      </div>
      <div class="field">
        <label>Plausible domain</label>
        <input type="text" name="plausible_domain" maxlength="120" value="<?= htmlspecialchars((string)($an['plausible_domain'] ?? ''), ENT_QUOTES) ?>" placeholder="wifiber.co.za">
      </div>
      <div class="field">
        <label>Google Measurement ID</label>
        <input type="text" name="google_id" maxlength="40" value="<?= htmlspecialchars((string)($an['google_id'] ?? ''), ENT_QUOTES) ?>" placeholder="G-XXXXXXXXXX">
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Save settings</button>
</form>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
