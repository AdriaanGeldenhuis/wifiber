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
    return [
        'image'          => trim($_POST['image']          ?? ''),
        'eyebrow'        => trim($_POST['eyebrow']        ?? ''),
        'heading'        => trim($_POST['heading']        ?? ''),
        'heading_accent' => trim($_POST['heading_accent'] ?? ''),
        'subtext'        => trim($_POST['subtext']        ?? ''),
        'cta_label'      => trim($_POST['cta_label']      ?? ''),
        'cta_link'       => trim($_POST['cta_link']       ?? ''),
        'position'       => in_array($_POST['position'] ?? 'left', ['left', 'center'], true) ? $_POST['position'] : 'left',
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
          <div class="muted small">SLIDE <?= $i + 1 ?> &middot; <code><?= htmlspecialchars($s['image'] ?? '?') ?></code></div>
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

  <div class="portal-card">
    <h2><?= $editing ? 'Edit slide' : 'Add a new slide' ?></h2>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($editing_idx !== null): ?>
        <input type="hidden" name="idx" value="<?= (int)$editing_idx ?>">
      <?php endif; ?>

      <div class="field">
        <label>Image</label>
        <?php if ($editing && !empty($editing['image'])): ?>
          <div style="margin-bottom:10px;">
            <img src="/assets/images/slider/<?= htmlspecialchars($editing['image']) ?>" alt="" style="max-height:120px;border-radius:8px;border:1px solid var(--border);">
            <div class="muted small" style="margin-top:4px;">Current: <code><?= htmlspecialchars($editing['image']) ?></code></div>
          </div>
        <?php endif; ?>
        <input type="file" name="image_file" accept="image/jpeg,image/png,image/webp">
        <small class="muted">Upload a new image (max 8 MB, JPG/PNG/WEBP). Leave empty to keep the current one. 1920&times;1080 works best.</small>
        <input type="hidden" name="image" value="<?= htmlspecialchars($editing['image'] ?? '', ENT_QUOTES) ?>">
      </div>

      <div class="form form-grid">
        <div class="field">
          <label>Eyebrow <span class="muted">(small label above heading)</span></label>
          <input type="text" name="eyebrow" maxlength="60" value="<?= htmlspecialchars($editing['eyebrow'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label>Position</label>
          <select name="position">
            <option value="left"   <?= ($editing['position'] ?? '') === 'left'   ? 'selected' : '' ?>>Left aligned</option>
            <option value="center" <?= ($editing['position'] ?? '') === 'center' ? 'selected' : '' ?>>Center aligned</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label>Heading <span class="muted">(white)</span></label>
        <input type="text" name="heading" required maxlength="100" value="<?= htmlspecialchars($editing['heading'] ?? '', ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Accent heading <span class="muted">(cyan, shown on next line)</span></label>
        <input type="text" name="heading_accent" maxlength="100" value="<?= htmlspecialchars($editing['heading_accent'] ?? '', ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label>Subtext</label>
        <textarea name="subtext" rows="3" maxlength="500"><?= htmlspecialchars($editing['subtext'] ?? '') ?></textarea>
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

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
