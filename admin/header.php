<?php
$page_title = 'Header';
$active_key = 'header';
require __DIR__ . '/_layout.php';

$file       = __DIR__ . '/../data/header.json';
$upload_dir = __DIR__ . '/../assets/uploads/branding';
@mkdir($upload_dir, 0755, true);

$ICON_OPTIONS = ['clock', 'pin', 'mail', 'phone', 'signal', 'globe', 'check', 'shield', 'bolt', 'info', 'star', 'user'];
$STATUS_COLORS = ['green' => 'Green', 'amber' => 'Amber', 'red' => 'Red', 'cyan' => 'Cyan'];

$defaults = [
    'logo' => [
        'url' => '',
        'height' => 96,
        'padding_y' => 22,
        'wordmark_show' => true,
        'wordmark_text' => '',
    ],
    'top_bar' => [
        'enabled' => true,
        'status_enabled' => true,
        'status_label' => 'All systems operational',
        'status_link' => '/status',
        'status_color' => 'green',
        'items' => [
            ['icon' => 'clock', 'text' => '24/7 Local Support', 'link' => ''],
            ['icon' => 'pin',   'text' => 'Vaal Triangle, ZA', 'link' => ''],
        ],
    ],
    'nav_links' => [
        ['label' => 'Home',         'href' => '/'],
        ['label' => 'Pricing',      'href' => '/pricing'],
        ['label' => 'Coverage Map', 'href' => '/coverage'],
        ['label' => 'Legal',        'href' => '/legal'],
    ],
    'account' => ['enabled' => true, 'label' => 'My Account', 'href' => '/account/'],
    'cta'     => ['enabled' => true, 'label' => '', 'href' => '', 'show_pulse' => true],
];

$cfg = $defaults;
if (is_file($file)) {
    $loaded = json_decode((string)@file_get_contents($file), true);
    if (is_array($loaded)) $cfg = array_replace_recursive($defaults, $loaded);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $logo_url   = trim((string)($_POST['logo_url'] ?? $cfg['logo']['url']));
    if (!empty($_FILES['logo_file']['tmp_name']) && is_uploaded_file($_FILES['logo_file']['tmp_name'])) {
        $f = $_FILES['logo_file'];
        if ($f['size'] > 4 * 1024 * 1024) {
            $errors[] = 'Logo must be under 4 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']) ?: '';
            $ext_by_mime = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            if (!isset($ext_by_mime[$mime])) {
                $errors[] = 'Logo must be PNG, JPG, WebP or SVG.';
            } else {
                $name = 'header-logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext_by_mime[$mime];
                if (@move_uploaded_file($f['tmp_name'], $upload_dir . '/' . $name)) {
                    @chmod($upload_dir . '/' . $name, 0644);
                    $logo_url = '/assets/uploads/branding/' . $name;
                } else {
                    $errors[] = 'Could not save the uploaded logo.';
                }
            }
        }
    }

    $items_in = $_POST['items'] ?? [];
    $items = [];
    if (is_array($items_in)) {
        foreach ($items_in as $row) {
            $text = trim((string)($row['text'] ?? ''));
            if ($text === '') continue;
            $icon = (string)($row['icon'] ?? 'info');
            if (!in_array($icon, $ICON_OPTIONS, true)) $icon = 'info';
            $items[] = [
                'icon' => $icon,
                'text' => mb_substr($text, 0, 80),
                'link' => trim((string)($row['link'] ?? '')),
            ];
        }
    }

    $links_in = $_POST['nav'] ?? [];
    $nav = [];
    if (is_array($links_in)) {
        foreach ($links_in as $row) {
            $label = trim((string)($row['label'] ?? ''));
            $href  = trim((string)($row['href']  ?? ''));
            if ($label === '' || $href === '') continue;
            $nav[] = [
                'label' => mb_substr($label, 0, 40),
                'href'  => mb_substr($href, 0, 200),
            ];
        }
    }

    $status_color = (string)($_POST['top_status_color'] ?? 'green');
    if (!array_key_exists($status_color, $STATUS_COLORS)) $status_color = 'green';

    $new = [
        'logo' => [
            'url'           => $logo_url,
            'height'        => max(32, min(220, (int)($_POST['logo_height']    ?? 96))),
            'padding_y'     => max(0,  min(80,  (int)($_POST['logo_padding_y'] ?? 22))),
            'wordmark_show' => !empty($_POST['logo_wordmark_show']),
            'wordmark_text' => mb_substr(trim((string)($_POST['logo_wordmark_text'] ?? '')), 0, 40),
        ],
        'top_bar' => [
            'enabled'        => !empty($_POST['top_enabled']),
            'status_enabled' => !empty($_POST['top_status_enabled']),
            'status_label'   => mb_substr(trim((string)($_POST['top_status_label'] ?? '')), 0, 60),
            'status_link'    => mb_substr(trim((string)($_POST['top_status_link']  ?? '')), 0, 200),
            'status_color'   => $status_color,
            'items'          => $items,
        ],
        'nav_links' => $nav,
        'account' => [
            'enabled' => !empty($_POST['account_enabled']),
            'label'   => mb_substr(trim((string)($_POST['account_label'] ?? '')), 0, 40),
            'href'    => mb_substr(trim((string)($_POST['account_href']  ?? '')), 0, 200),
        ],
        'cta' => [
            'enabled'    => !empty($_POST['cta_enabled']),
            'label'      => mb_substr(trim((string)($_POST['cta_label'] ?? '')), 0, 40),
            'href'       => mb_substr(trim((string)($_POST['cta_href']  ?? '')), 0, 200),
            'show_pulse' => !empty($_POST['cta_show_pulse']),
        ],
    ];

    if (!$errors) {
        if (json_save($file, $new)) {
            flash('success', 'Header settings saved.');
            header('Location: /admin/header.php');
            exit;
        }
        $errors[] = 'Could not write data/header.json. Check permissions.';
    }
    $cfg = array_replace_recursive($defaults, $new);
}

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
?>

<div class="portal-head">
  <h1>Header</h1>
  <p class="portal-sub">Fully customise the public site header &mdash; logo, top utility bar, navigation links, account button and the phone CTA. Changes go live on save.</p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul style="margin:0;padding-left:18px;">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
  </ul></div>
<?php endif; ?>

<style>
  .row-list { display: flex; flex-direction: column; gap: 10px; }
  .row-card {
    display: grid;
    grid-template-columns: auto 1fr 1fr auto;
    gap: 10px;
    align-items: center;
    padding: 12px;
    background: var(--bg-elev);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
  }
  .row-card.row-card-3 { grid-template-columns: 1fr 1fr auto; }
  .row-card .row-handle { color: var(--text-muted); font-family: ui-monospace, monospace; font-size: .8rem; padding: 0 4px; }
  .row-card .row-controls { display: flex; gap: 4px; }
  .row-card input, .row-card select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    font-size: .9rem;
  }
  .row-card .btn-row {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text-dim);
    padding: 6px 9px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: .8rem;
    transition: color .15s, border-color .15s;
  }
  .row-card .btn-row:hover { color: var(--accent); border-color: var(--accent); }
  .row-card .btn-row.danger:hover { color: var(--danger); border-color: var(--danger); }
  .toggle-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; }
  .toggle-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--accent); }
  @media (max-width: 700px) {
    .row-card { grid-template-columns: 1fr; }
    .row-card.row-card-3 { grid-template-columns: 1fr; }
    .row-card .row-handle { display: none; }
  }
</style>

<form method="post" enctype="multipart/form-data" class="form" id="headerForm">
  <?= csrf_field() ?>

  <div class="portal-card">
    <h2>Logo &amp; sizing</h2>
    <div class="form form-grid">
      <div class="field" style="grid-column:1/-1;">
        <label>Logo image</label>
        <?php if (!empty($cfg['logo']['url'])): ?>
          <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;padding:12px;background:#0a0d12;border:1px solid var(--border);border-radius:var(--radius-sm);">
            <img src="<?= $h($cfg['logo']['url']) ?>" alt="Current logo" style="height:60px;width:auto;">
            <small class="muted"><?= $h($cfg['logo']['url']) ?></small>
          </div>
        <?php endif; ?>
        <input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        <small class="muted">PNG, JPG, WebP or SVG. Max 4 MB. Leave blank to keep the current logo (or fall back to the default in <code>/assets/images</code>).</small>
        <input type="hidden" name="logo_url" value="<?= $h($cfg['logo']['url']) ?>">
      </div>
      <div class="field">
        <label>Logo height (px)</label>
        <input type="number" name="logo_height" min="32" max="220" value="<?= (int)$cfg['logo']['height'] ?>">
        <small class="muted">Default 96. Recommended 64&ndash;128.</small>
      </div>
      <div class="field">
        <label>Header padding top/bottom (px)</label>
        <input type="number" name="logo_padding_y" min="0" max="80" value="<?= (int)$cfg['logo']['padding_y'] ?>">
        <small class="muted">Space above and below the logo. Default 22.</small>
      </div>
      <div class="field">
        <label class="toggle-row" style="padding:0;">
          <input type="checkbox" name="logo_wordmark_show" <?= !empty($cfg['logo']['wordmark_show']) ? 'checked' : '' ?>>
          <span>Show wordmark text next to logo</span>
        </label>
      </div>
      <div class="field">
        <label>Wordmark text <span class="muted">(blank = site name)</span></label>
        <input type="text" name="logo_wordmark_text" maxlength="40" value="<?= $h($cfg['logo']['wordmark_text'] ?? '') ?>" placeholder="WiFIBER">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Top utility bar</h2>
    <label class="toggle-row">
      <input type="checkbox" name="top_enabled" <?= !empty($cfg['top_bar']['enabled']) ? 'checked' : '' ?>>
      <span>Show top utility bar above the header</span>
    </label>

    <h3 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.15em;color:var(--text-muted);margin-top:18px;">Status pill (left)</h3>
    <label class="toggle-row">
      <input type="checkbox" name="top_status_enabled" <?= !empty($cfg['top_bar']['status_enabled']) ? 'checked' : '' ?>>
      <span>Show status pill</span>
    </label>
    <div class="form form-grid">
      <div class="field">
        <label>Status label</label>
        <input type="text" name="top_status_label" maxlength="60" value="<?= $h($cfg['top_bar']['status_label'] ?? '') ?>" placeholder="All systems operational">
      </div>
      <div class="field">
        <label>Status link</label>
        <input type="text" name="top_status_link" maxlength="200" value="<?= $h($cfg['top_bar']['status_link'] ?? '') ?>" placeholder="/status">
      </div>
      <div class="field">
        <label>Status dot colour</label>
        <select name="top_status_color">
          <?php foreach ($STATUS_COLORS as $val => $label): ?>
            <option value="<?= $h($val) ?>" <?= ($cfg['top_bar']['status_color'] ?? 'green') === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h3 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.15em;color:var(--text-muted);margin-top:22px;">Items (right)</h3>
    <p class="muted small">Each item shows an icon + label, optionally linked. Drag with the up/down buttons; remove with &times;.</p>
    <div class="row-list" id="itemsList">
      <?php foreach (($cfg['top_bar']['items'] ?? []) as $i => $it): ?>
        <div class="row-card">
          <select name="items[<?= $i ?>][icon]">
            <?php foreach ($ICON_OPTIONS as $opt): ?>
              <option value="<?= $h($opt) ?>" <?= ($it['icon'] ?? '') === $opt ? 'selected' : '' ?>><?= $h(ucfirst($opt)) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="items[<?= $i ?>][text]" maxlength="80" value="<?= $h($it['text'] ?? '') ?>" placeholder="Display text">
          <input type="text" name="items[<?= $i ?>][link]" maxlength="200" value="<?= $h($it['link'] ?? '') ?>" placeholder="Optional URL">
          <div class="row-controls">
            <button type="button" class="btn-row" data-row-up>&uarr;</button>
            <button type="button" class="btn-row" data-row-down>&darr;</button>
            <button type="button" class="btn-row danger" data-row-remove>&times;</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" data-row-add="items" style="margin-top:10px;">+ Add item</button>

    <template id="itemTemplate">
      <div class="row-card">
        <select name="items[__INDEX__][icon]">
          <?php foreach ($ICON_OPTIONS as $opt): ?>
            <option value="<?= $h($opt) ?>"><?= $h(ucfirst($opt)) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="items[__INDEX__][text]" maxlength="80" placeholder="Display text">
        <input type="text" name="items[__INDEX__][link]" maxlength="200" placeholder="Optional URL">
        <div class="row-controls">
          <button type="button" class="btn-row" data-row-up>&uarr;</button>
          <button type="button" class="btn-row" data-row-down>&darr;</button>
          <button type="button" class="btn-row danger" data-row-remove>&times;</button>
        </div>
      </div>
    </template>
  </div>

  <div class="portal-card">
    <h2>Navigation links</h2>
    <p class="muted small">Shown in the order below. Empty rows are skipped on save.</p>
    <div class="row-list" id="navList">
      <?php foreach (($cfg['nav_links'] ?? []) as $i => $nl): ?>
        <div class="row-card row-card-3">
          <input type="text" name="nav[<?= $i ?>][label]" maxlength="40" value="<?= $h($nl['label'] ?? '') ?>" placeholder="Label">
          <input type="text" name="nav[<?= $i ?>][href]"  maxlength="200" value="<?= $h($nl['href']  ?? '') ?>" placeholder="/path or https://...">
          <div class="row-controls">
            <button type="button" class="btn-row" data-row-up>&uarr;</button>
            <button type="button" class="btn-row" data-row-down>&darr;</button>
            <button type="button" class="btn-row danger" data-row-remove>&times;</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" data-row-add="nav" style="margin-top:10px;">+ Add link</button>

    <template id="navTemplate">
      <div class="row-card row-card-3">
        <input type="text" name="nav[__INDEX__][label]" maxlength="40" placeholder="Label">
        <input type="text" name="nav[__INDEX__][href]"  maxlength="200" placeholder="/path or https://...">
        <div class="row-controls">
          <button type="button" class="btn-row" data-row-up>&uarr;</button>
          <button type="button" class="btn-row" data-row-down>&darr;</button>
          <button type="button" class="btn-row danger" data-row-remove>&times;</button>
        </div>
      </div>
    </template>
  </div>

  <div class="portal-card">
    <h2>Account button</h2>
    <label class="toggle-row">
      <input type="checkbox" name="account_enabled" <?= !empty($cfg['account']['enabled']) ? 'checked' : '' ?>>
      <span>Show "My Account" button</span>
    </label>
    <div class="form form-grid">
      <div class="field">
        <label>Label</label>
        <input type="text" name="account_label" maxlength="40" value="<?= $h($cfg['account']['label'] ?? '') ?>" placeholder="My Account">
      </div>
      <div class="field">
        <label>Link</label>
        <input type="text" name="account_href" maxlength="200" value="<?= $h($cfg['account']['href'] ?? '') ?>" placeholder="/account/">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>Phone CTA</h2>
    <label class="toggle-row">
      <input type="checkbox" name="cta_enabled" <?= !empty($cfg['cta']['enabled']) ? 'checked' : '' ?>>
      <span>Show phone CTA button</span>
    </label>
    <label class="toggle-row">
      <input type="checkbox" name="cta_show_pulse" <?= !empty($cfg['cta']['show_pulse']) ? 'checked' : '' ?>>
      <span>Show the pulsing &ldquo;live&rdquo; dot in the corner</span>
    </label>
    <div class="form form-grid">
      <div class="field">
        <label>Button label <span class="muted">(blank = site phone)</span></label>
        <input type="text" name="cta_label" maxlength="40" value="<?= $h($cfg['cta']['label'] ?? '') ?>" placeholder="<?= $h($site['phone'] ?? '0800 111 222') ?>">
      </div>
      <div class="field">
        <label>Button link <span class="muted">(blank = tel: site phone)</span></label>
        <input type="text" name="cta_href" maxlength="200" value="<?= $h($cfg['cta']['href'] ?? '') ?>" placeholder="tel:<?= $h($site['phone_link'] ?? '0800111222') ?>">
      </div>
    </div>
  </div>

  <div class="form-actions" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <button type="submit" class="btn btn-primary">Save header</button>
    <a href="/" target="_blank" class="btn btn-ghost">View public site &rarr;</a>
  </div>
</form>

<script>
(function () {
  function reindex(list, fieldPrefix) {
    var rows = list.querySelectorAll('.row-card');
    rows.forEach(function (row, i) {
      row.querySelectorAll('input, select').forEach(function (el) {
        if (!el.name) return;
        el.name = el.name.replace(/\[\d+\]|\[__INDEX__\]/, '[' + i + ']');
      });
    });
  }

  document.querySelectorAll('[data-row-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key  = btn.getAttribute('data-row-add');
      var list = document.getElementById(key === 'nav' ? 'navList' : 'itemsList');
      var tpl  = document.getElementById(key === 'nav' ? 'navTemplate' : 'itemTemplate');
      var node = tpl.content.cloneNode(true);
      list.appendChild(node);
      reindex(list, key);
    });
  });

  document.body.addEventListener('click', function (e) {
    var t = e.target.closest('[data-row-up], [data-row-down], [data-row-remove]');
    if (!t) return;
    var row  = t.closest('.row-card');
    var list = row.parentElement;
    if (t.matches('[data-row-up]') && row.previousElementSibling) {
      list.insertBefore(row, row.previousElementSibling);
    } else if (t.matches('[data-row-down]') && row.nextElementSibling) {
      list.insertBefore(row.nextElementSibling, row);
    } else if (t.matches('[data-row-remove]')) {
      if (!confirm('Remove this row?')) return;
      row.remove();
    }
    reindex(list);
  });
})();
</script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
