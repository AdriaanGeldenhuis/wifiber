<?php
$page_title = 'Hero slider';
$active_key = 'slides';
require __DIR__ . '/_layout.php';

$file       = __DIR__ . '/../data/slides.json';
$upload_dir = __DIR__ . '/../assets/images/slider';
@mkdir($upload_dir, 0755, true);

$data   = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
$slides = $data['slides'] ?? [];

/* ----- helpers ----- */

function slide_save_all(string $file, array $slides): bool {
    return json_save($file, ['slides' => array_values($slides)]);
}

function safe_filename(string $name): string {
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
    $base = preg_replace('/[^a-z0-9_-]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME) ?: 'image'));
    $base = trim($base, '-') ?: 'image';
    return substr($base, 0, 40) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
}

function handle_upload(?array $f, string $dir): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'name' => null];
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error code ' . $f['error']];
    }
    if ($f['size'] > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large (max 8 MB).'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']) ?: '';
    $ext_from_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($ext_from_mime[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG and WebP are allowed.'];
    }
    $name = safe_filename($f['name']);
    $name = preg_replace('/\.[^.]+$/', '.' . $ext_from_mime[$mime], $name);
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.'];
    }
    return ['ok' => true, 'name' => $name];
}

function read_slide_input(): array {
    $focal_options = ['center center','center top','center bottom','left center','right center','left top','right top','left bottom','right bottom'];
    return [
        'image'          => trim($_POST['image']          ?? ''),
        'image_mobile'   => trim($_POST['image_mobile']   ?? ''),
        'eyebrow'        => trim($_POST['eyebrow']        ?? ''),
        'heading'        => trim($_POST['heading']        ?? ''),
        'heading_accent' => trim($_POST['heading_accent'] ?? ''),
        'subtext'        => trim($_POST['subtext']        ?? ''),
        'cta_label'      => trim($_POST['cta_label']      ?? ''),
        'cta_link'       => trim($_POST['cta_link']       ?? ''),
        'position'       => in_array($_POST['position'] ?? 'left', ['left', 'center'], true) ? $_POST['position'] : 'left',
        'overlay'        => max(0, min(100, (int)($_POST['overlay'] ?? 55))),
        'overlay_style'  => in_array($_POST['overlay_style'] ?? 'left', ['left','bottom','even'], true) ? $_POST['overlay_style'] : 'left',
        'focal_mobile'   => in_array($_POST['focal_mobile'] ?? '', $focal_options, true) ? $_POST['focal_mobile'] : 'center center',
    ];
}

/* ----- POST handlers ----- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $idx     = isset($_POST['idx']) && $_POST['idx'] !== '' ? (int)$_POST['idx'] : null;
        $payload = read_slide_input();

        // Handle image upload (overrides $payload['image'] if a file was sent)
        $upload = handle_upload($_FILES['image_file'] ?? null, $upload_dir);
        if (!$upload['ok']) {
            flash('error', $upload['error']);
            header('Location: /admin/slides.php');
            exit;
        }
        if ($upload['name']) $payload['image'] = $upload['name'];

        // Handle mobile image upload
        $upload_m = handle_upload($_FILES['image_mobile_file'] ?? null, $upload_dir);
        if (!$upload_m['ok']) {
            flash('error', $upload_m['error']);
            header('Location: /admin/slides.php');
            exit;
        }
        if ($upload_m['name']) $payload['image_mobile'] = $upload_m['name'];

        $errors = [];
        if ($payload['heading'] === '') $errors[] = 'Heading is required.';
        if ($payload['image']   === '') $errors[] = 'An image is required.';
        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            if ($idx !== null && isset($slides[$idx])) {
                $slides[$idx] = $payload;
                flash('success', 'Slide updated.');
            } else {
                $slides[] = $payload;
                flash('success', 'Slide added.');
            }
            slide_save_all($file, $slides);
        }
        header('Location: /admin/slides.php');
        exit;
    }

    if ($action === 'delete') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($slides[$idx])) {
            array_splice($slides, $idx, 1);
            slide_save_all($file, $slides);
            flash('success', 'Slide removed.');
        }
        header('Location: /admin/slides.php');
        exit;
    }

    if ($action === 'move') {
        $idx = (int)($_POST['idx'] ?? -1);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        if (isset($slides[$idx]) && isset($slides[$idx + $dir])) {
            [$slides[$idx], $slides[$idx + $dir]] = [$slides[$idx + $dir], $slides[$idx]];
            slide_save_all($file, $slides);
        }
        header('Location: /admin/slides.php');
        exit;
    }
}

/* ----- render ----- */
$editing_idx  = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editing      = $editing_idx !== null && isset($slides[$editing_idx]) ? $slides[$editing_idx] : null;
$show_form    = isset($_GET['edit']) || isset($_GET['add']);
?>

<div class="portal-head">
  <h1>Hero slider</h1>
  <p class="portal-sub">The auto-rotating slider on the homepage. Add as many slides as you want &mdash; they're shown in the order below.</p>
</div>

<?php if (!$show_form): ?>

  <div style="margin-bottom:20px;">
    <a href="?add=1" class="btn btn-primary">+ Add slide</a>
  </div>

  <?php if (empty($slides)): ?>
    <div class="portal-card"><p class="muted">No slides yet. <a href="?add=1">Add your first one.</a></p></div>
  <?php else: ?>
    <?php foreach ($slides as $i => $s):
      $img = '/assets/images/slider/' . htmlspecialchars($s['image'] ?? '');
    ?>
      <div class="portal-card slide-row">
        <div class="slide-thumb" style="background-image:url('<?= $img ?>');"></div>
        <div class="slide-meta">
          <div class="muted small">
            SLIDE <?= $i + 1 ?> &middot; <code><?= htmlspecialchars($s['image'] ?? '?') ?></code>
            <?php if (!empty($s['image_mobile'])): ?> &middot; <span style="color:var(--accent);">+ mobile</span><?php endif; ?>
            &middot; overlay <?= isset($s['overlay']) ? (int)$s['overlay'] : 55 ?>%
          </div>
          <h2 style="margin:6px 0;"><?= htmlspecialchars($s['heading'] ?? '(no heading)') ?>
            <?php if (!empty($s['heading_accent'])): ?>
              <span style="color:var(--accent);"><?= htmlspecialchars($s['heading_accent']) ?></span>
            <?php endif; ?>
          </h2>
          <div class="muted small">CTA: <?= htmlspecialchars($s['cta_label'] ?? '') ?> &rarr; <code><?= htmlspecialchars($s['cta_link'] ?? '') ?></code></div>
        </div>
        <div class="slide-actions">
          <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="idx" value="<?= $i ?>">
            <button name="dir" value="up"   class="btn btn-ghost btn-sm" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
            <button name="dir" value="down" class="btn btn-ghost btn-sm" <?= $i === count($slides) - 1 ? 'disabled' : '' ?>>&darr;</button>
          </form>
          <a href="?edit=<?= $i ?>" class="btn btn-ghost btn-sm">Edit</a>
          <form method="post" class="inline-form" data-confirm="Delete this slide?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="idx" value="<?= $i ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php else: ?>

  <?php
    $cur_image    = $editing['image']         ?? '';
    $cur_mobile   = $editing['image_mobile']  ?? '';
    $cur_overlay  = isset($editing['overlay']) ? (int)$editing['overlay'] : 55;
    $cur_ostyle   = $editing['overlay_style'] ?? 'left';
    $cur_focal    = $editing['focal_mobile']  ?? 'center center';
    $cur_heading  = $editing['heading']        ?? 'Heading goes here';
    $cur_accent   = $editing['heading_accent'] ?? '';
    $cur_subtext  = $editing['subtext']        ?? 'Subtext appears here.';
  ?>

  <style>
    .slide-preview-wrap {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 16px;
      margin-bottom: 22px;
    }
    @media (max-width: 800px) { .slide-preview-wrap { grid-template-columns: 1fr; } }
    .slide-preview {
      position: relative;
      border-radius: var(--radius-sm);
      overflow: hidden;
      border: 1px solid var(--border-strong);
      background: #000 center/cover no-repeat;
      min-height: 220px;
      display: flex;
      align-items: center;
    }
    .slide-preview.is-mobile { aspect-ratio: 9/16; max-width: 220px; min-height: 0; }
    .slide-preview .sp-overlay {
      position: absolute; inset: 0; pointer-events: none;
    }
    .sp-overlay.overlay-style-left {
      background:
        linear-gradient(90deg,
          rgba(2,2,2, calc(var(--a) * 1.6)) 0%,
          rgba(2,2,2, calc(var(--a) * 1.18)) 45%,
          rgba(2,2,2, calc(var(--a) * 0.45)) 100%),
        linear-gradient(180deg,
          rgba(2,2,2, calc(var(--a) * 0.7)) 0%,
          transparent 30%,
          rgba(2,2,2, calc(var(--a) * 1.1)) 100%);
    }
    .sp-overlay.overlay-style-bottom {
      background:
        linear-gradient(180deg,
          rgba(2,2,2, calc(var(--a) * 0.25)) 0%,
          rgba(2,2,2, calc(var(--a) * 0.6)) 50%,
          rgba(2,2,2, calc(var(--a) * 1.55)) 100%);
    }
    .sp-overlay.overlay-style-even { background: rgba(2,2,2, var(--a)); }
    .slide-preview .sp-content {
      position: relative; z-index: 2; padding: 18px 22px; max-width: 70%; color: #fff;
      text-shadow: 0 2px 8px rgba(0,0,0,.6);
    }
    .slide-preview.pos-center .sp-content { margin: 0 auto; text-align: center; max-width: 80%; }
    .sp-content .sp-eyebrow {
      display: inline-block; font-size: .65rem; letter-spacing: .15em; text-transform: uppercase;
      color: var(--accent); border: 1px solid rgba(5,218,253,.4); padding: 3px 8px; border-radius: 999px; margin-bottom: 8px;
    }
    .sp-content .sp-heading { font-size: 1.2rem; font-weight: 700; line-height: 1.15; }
    .sp-content .sp-accent  { color: var(--accent); display: block; }
    .sp-content .sp-subtext { font-size: .8rem; opacity: .9; margin-top: 6px; }
    .slide-preview-label {
      font-size: .7rem; letter-spacing: .15em; text-transform: uppercase;
      color: var(--text-muted); margin-bottom: 6px;
    }
    .overlay-row { display: flex; align-items: center; gap: 12px; }
    .overlay-row input[type="range"] { flex: 1; accent-color: var(--accent); }
    .overlay-row .overlay-val {
      min-width: 48px; text-align: right; font-family: ui-monospace, monospace;
      font-size: .9rem; color: var(--accent);
    }
  </style>

  <div class="portal-card">
    <h2><?= $editing ? 'Edit slide' : 'Add a new slide' ?></h2>

    <div class="slide-preview-label">Live preview &mdash; updates as you type</div>
    <div class="slide-preview-wrap">
      <div>
        <div class="slide-preview-label">Desktop</div>
        <div id="spDesktop" class="slide-preview pos-<?= htmlspecialchars($editing['position'] ?? 'left') ?>"
             style="--a: <?= number_format($cur_overlay/100, 2, '.', '') ?>; <?= $cur_image ? "background-image:url('/assets/images/slider/".htmlspecialchars($cur_image)."');" : '' ?>">
          <div id="spDesktopOverlay" class="sp-overlay overlay-style-<?= htmlspecialchars($cur_ostyle) ?>"></div>
          <div class="sp-content">
            <span id="spEyebrow" class="sp-eyebrow" <?= empty($editing['eyebrow']) ? 'style="display:none;"' : '' ?>><?= htmlspecialchars($editing['eyebrow'] ?? '') ?></span>
            <div class="sp-heading">
              <span id="spHeading"><?= htmlspecialchars($cur_heading) ?></span>
              <span id="spAccent" class="sp-accent" <?= empty($cur_accent) ? 'style="display:none;"' : '' ?>><?= htmlspecialchars($cur_accent) ?></span>
            </div>
            <div id="spSubtext" class="sp-subtext"><?= htmlspecialchars($cur_subtext) ?></div>
          </div>
        </div>
      </div>
      <div>
        <div class="slide-preview-label">Mobile</div>
        <div id="spMobile" class="slide-preview is-mobile pos-<?= htmlspecialchars($editing['position'] ?? 'left') ?>"
             style="--a: <?= number_format($cur_overlay/100, 2, '.', '') ?>; background-position:<?= htmlspecialchars($cur_focal) ?>; <?= ($cur_mobile ?: $cur_image) ? "background-image:url('/assets/images/slider/".htmlspecialchars($cur_mobile ?: $cur_image)."');" : '' ?>">
          <div id="spMobileOverlay" class="sp-overlay overlay-style-<?= htmlspecialchars($cur_ostyle) ?>"></div>
        </div>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="form" id="slideForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($editing_idx !== null): ?>
        <input type="hidden" name="idx" value="<?= (int)$editing_idx ?>">
      <?php endif; ?>

      <div class="form form-grid">
        <div class="field">
          <label>Desktop image</label>
          <?php if ($cur_image !== ''): ?>
            <div style="margin-bottom:10px;">
              <img src="/assets/images/slider/<?= htmlspecialchars($cur_image) ?>" alt="" style="max-height:100px;border-radius:8px;border:1px solid var(--border);">
              <div class="muted small" style="margin-top:4px;">Current: <code><?= htmlspecialchars($cur_image) ?></code></div>
            </div>
          <?php endif; ?>
          <input type="file" name="image_file" id="imageFile" accept="image/jpeg,image/png,image/webp">
          <small class="muted">Max 8 MB. 1920&times;1080 works best.</small>
          <input type="hidden" name="image" value="<?= htmlspecialchars($cur_image, ENT_QUOTES) ?>">
        </div>

        <div class="field">
          <label>Mobile image <span class="muted">(optional)</span></label>
          <?php if ($cur_mobile !== ''): ?>
            <div style="margin-bottom:10px;">
              <img src="/assets/images/slider/<?= htmlspecialchars($cur_mobile) ?>" alt="" style="max-height:100px;border-radius:8px;border:1px solid var(--border);">
              <div class="muted small" style="margin-top:4px;">Current: <code><?= htmlspecialchars($cur_mobile) ?></code></div>
            </div>
          <?php endif; ?>
          <input type="file" name="image_mobile_file" id="imageMobileFile" accept="image/jpeg,image/png,image/webp">
          <small class="muted">Used on phones (&le;700px). Portrait 1080&times;1920 or 1080&times;1350 works best. Leave blank to reuse the desktop image.</small>
          <input type="hidden" name="image_mobile" value="<?= htmlspecialchars($cur_mobile, ENT_QUOTES) ?>">
        </div>
      </div>

      <div class="field">
        <label>Overlay darkness <span class="muted">(0% = no overlay, 100% = solid)</span></label>
        <div class="overlay-row">
          <input type="range" name="overlay" id="overlayRange" min="0" max="100" step="5" value="<?= (int)$cur_overlay ?>">
          <span class="overlay-val" id="overlayVal"><?= (int)$cur_overlay ?>%</span>
        </div>
        <small class="muted">Default 55%. Lower for brighter images, higher when text needs to stand out.</small>
      </div>

      <div class="form form-grid">
        <div class="field">
          <label>Overlay style</label>
          <select name="overlay_style" id="overlayStyle">
            <option value="left"   <?= $cur_ostyle === 'left'   ? 'selected' : '' ?>>Heavy left, fades right (default)</option>
            <option value="bottom" <?= $cur_ostyle === 'bottom' ? 'selected' : '' ?>>Heavy bottom, fades up</option>
            <option value="even"   <?= $cur_ostyle === 'even'   ? 'selected' : '' ?>>Even all over</option>
          </select>
        </div>
        <div class="field">
          <label>Mobile focal point <span class="muted">(crop anchor)</span></label>
          <select name="focal_mobile" id="focalMobile">
            <?php foreach (['center center'=>'Center','center top'=>'Top','center bottom'=>'Bottom','left center'=>'Left','right center'=>'Right','left top'=>'Top-left','right top'=>'Top-right','left bottom'=>'Bottom-left','right bottom'=>'Bottom-right'] as $val => $lbl): ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= $cur_focal === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form form-grid">
        <div class="field">
          <label>Eyebrow <span class="muted">(small label above heading)</span></label>
          <input type="text" name="eyebrow" id="fEyebrow" maxlength="60" value="<?= htmlspecialchars($editing['eyebrow'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label>Text position</label>
          <select name="position" id="fPosition">
            <option value="left"   <?= ($editing['position'] ?? '') === 'left'   ? 'selected' : '' ?>>Left aligned</option>
            <option value="center" <?= ($editing['position'] ?? '') === 'center' ? 'selected' : '' ?>>Center aligned</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label>Heading <span class="muted">(white)</span></label>
        <input type="text" name="heading" id="fHeading" required maxlength="100" value="<?= htmlspecialchars($cur_heading, ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Accent heading <span class="muted">(cyan, shown on next line)</span></label>
        <input type="text" name="heading_accent" id="fAccent" maxlength="100" value="<?= htmlspecialchars($cur_accent, ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Subtext</label>
        <textarea name="subtext" id="fSubtext" rows="3" maxlength="500"><?= htmlspecialchars($cur_subtext) ?></textarea>
      </div>

      <div class="form form-grid">
        <div class="field">
          <label>Button label</label>
          <input type="text" name="cta_label" maxlength="40" value="<?= htmlspecialchars($editing['cta_label'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label>Button link</label>
          <input type="text" name="cta_link" maxlength="200" value="<?= htmlspecialchars($editing['cta_link'] ?? '', ENT_QUOTES) ?>" placeholder="/pricing or https://...">
        </div>
      </div>

      <div class="form-actions" style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add slide' ?></button>
        <a href="/admin/slides.php" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>

  <script>
  (function(){
    const desk = document.getElementById('spDesktop');
    const mob  = document.getElementById('spMobile');
    const deskOv = document.getElementById('spDesktopOverlay');
    const mobOv  = document.getElementById('spMobileOverlay');
    const range  = document.getElementById('overlayRange');
    const rangeV = document.getElementById('overlayVal');
    const ostyle = document.getElementById('overlayStyle');
    const focal  = document.getElementById('focalMobile');
    const pos    = document.getElementById('fPosition');
    const head   = document.getElementById('fHeading');
    const acc    = document.getElementById('fAccent');
    const sub    = document.getElementById('fSubtext');
    const eyeF   = document.getElementById('fEyebrow');
    const imgF   = document.getElementById('imageFile');
    const imgMF  = document.getElementById('imageMobileFile');

    const setAlpha = () => {
      const v = (parseInt(range.value, 10) / 100).toFixed(2);
      desk.style.setProperty('--a', v);
      mob.style.setProperty('--a', v);
      rangeV.textContent = range.value + '%';
    };
    const setStyle = () => {
      deskOv.className = 'sp-overlay overlay-style-' + ostyle.value;
      mobOv.className  = 'sp-overlay overlay-style-' + ostyle.value;
    };
    const setFocal = () => { mob.style.backgroundPosition = focal.value; };
    const setPos = () => {
      desk.classList.toggle('pos-center', pos.value === 'center');
      desk.classList.toggle('pos-left',   pos.value !== 'center');
      mob.classList.toggle('pos-center',  pos.value === 'center');
      mob.classList.toggle('pos-left',    pos.value !== 'center');
    };
    const setText = () => {
      document.getElementById('spHeading').textContent = head.value || 'Heading';
      const a = document.getElementById('spAccent');
      a.textContent = acc.value;
      a.style.display = acc.value ? '' : 'none';
      document.getElementById('spSubtext').textContent = sub.value;
      const e = document.getElementById('spEyebrow');
      e.textContent = eyeF.value;
      e.style.display = eyeF.value ? '' : 'none';
    };
    const previewFile = (input, target, fallbackTarget) => {
      const f = input.files && input.files[0];
      if (!f) return;
      const url = URL.createObjectURL(f);
      target.style.backgroundImage = "url('" + url + "')";
      if (fallbackTarget && !imgMF.files[0]) {
        fallbackTarget.style.backgroundImage = "url('" + url + "')";
      }
    };

    range.addEventListener('input', setAlpha);
    ostyle.addEventListener('change', setStyle);
    focal.addEventListener('change', setFocal);
    pos.addEventListener('change', setPos);
    [head, acc, sub, eyeF].forEach(el => el && el.addEventListener('input', setText));
    imgF.addEventListener('change', () => previewFile(imgF, desk, mob));
    imgMF.addEventListener('change', () => previewFile(imgMF, mob, null));
  })();
  </script>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
