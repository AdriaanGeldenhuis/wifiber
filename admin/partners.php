<?php
$page_title = 'Partner logos';
$active_key = 'partners';
require __DIR__ . '/_layout.php';

$file       = __DIR__ . '/../data/partners.json';
$upload_dir = __DIR__ . '/../assets/images/partners';
$rel_dir    = 'partners';
@mkdir($upload_dir, 0755, true);

$data   = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
$label  = $data['label'] ?? 'Powered by industry-leading partners';
$logos  = $data['logos'] ?? [];

function partners_save(string $file, string $label, array $logos): bool {
    return json_save($file, [
        'label' => $label,
        'logos' => array_values($logos),
    ]);
}

function partner_safe_filename(string $name): string {
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
    $base = preg_replace('/[^a-z0-9_-]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME) ?: 'logo'));
    $base = trim($base, '-') ?: 'logo';
    return substr($base, 0, 40) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
}

function partner_handle_upload(?array $f, string $dir): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'name' => null];
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error code ' . $f['error']];
    }
    if ($f['size'] > 4 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large (max 4 MB).'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']) ?: '';
    $ext_from_mime = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($ext_from_mime[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WebP and SVG are allowed.'];
    }
    $name = partner_safe_filename($f['name']);
    $name = preg_replace('/\.[^.]+$/', '.' . $ext_from_mime[$mime], $name);
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.'];
    }
    return ['ok' => true, 'name' => $name];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_label') {
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') $label = 'Powered by industry-leading partners';
        partners_save($file, $label, $logos);
        flash('success', 'Heading updated.');
        header('Location: /admin/partners.php');
        exit;
    }

    if ($action === 'save') {
        $idx = isset($_POST['idx']) && $_POST['idx'] !== '' ? (int)$_POST['idx'] : null;
        $alt = trim((string)($_POST['alt'] ?? ''));
        $cur_image = trim((string)($_POST['image'] ?? ''));

        $upload = partner_handle_upload($_FILES['logo_file'] ?? null, $upload_dir);
        if (!$upload['ok']) {
            flash('error', $upload['error']);
            header('Location: /admin/partners.php');
            exit;
        }
        $image = $upload['name'] ? ($rel_dir . '/' . $upload['name']) : $cur_image;

        if ($alt === '')   { flash('error', 'Alt text is required.'); }
        elseif ($image === '') { flash('error', 'Please upload a logo image.'); }
        else {
            $payload = ['image' => $image, 'alt' => $alt, 'external' => false];
            if ($idx !== null && isset($logos[$idx])) {
                if (!empty($logos[$idx]['external'])) $payload['external'] = !$upload['name'];
                $logos[$idx] = $payload;
                flash('success', 'Logo updated.');
            } else {
                $logos[] = $payload;
                flash('success', 'Logo added.');
            }
            partners_save($file, $label, $logos);
        }
        header('Location: /admin/partners.php');
        exit;
    }

    if ($action === 'delete') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($logos[$idx])) {
            $img = (string)($logos[$idx]['image'] ?? '');
            if (!empty($logos[$idx]['external']) === false && str_starts_with($img, $rel_dir . '/')) {
                $path = __DIR__ . '/../assets/images/' . $img;
                if (is_file($path)) @unlink($path);
            }
            array_splice($logos, $idx, 1);
            partners_save($file, $label, $logos);
            flash('success', 'Logo removed.');
        }
        header('Location: /admin/partners.php');
        exit;
    }

    if ($action === 'move') {
        $idx = (int)($_POST['idx'] ?? -1);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        if (isset($logos[$idx]) && isset($logos[$idx + $dir])) {
            [$logos[$idx], $logos[$idx + $dir]] = [$logos[$idx + $dir], $logos[$idx]];
            partners_save($file, $label, $logos);
        }
        header('Location: /admin/partners.php');
        exit;
    }
}

$editing_idx = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editing     = $editing_idx !== null && isset($logos[$editing_idx]) ? $logos[$editing_idx] : null;
$show_form   = isset($_GET['edit']) || isset($_GET['add']);
?>

<div class="portal-head">
  <h1>Partner logos</h1>
  <p class="portal-sub">Logos shown in the &ldquo;Powered by industry-leading partners&rdquo; strip on the homepage. Upload PNG, WebP or SVG with a transparent background &mdash; the homepage tints them white automatically.</p>
</div>

<?php if (!$show_form): ?>

  <div class="portal-card">
    <h2 style="margin-top:0;">Section heading</h2>
    <form method="post" class="form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_label">
      <div class="field" style="flex:1;min-width:260px;margin:0;">
        <label>Heading text</label>
        <input type="text" name="label" maxlength="80" value="<?= htmlspecialchars($label, ENT_QUOTES) ?>">
      </div>
      <button type="submit" class="btn btn-primary">Save heading</button>
    </form>
  </div>

  <div style="margin:20px 0;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
    <a href="?add=1" class="btn btn-primary">+ Add logo</a>
    <a href="/" target="_blank" class="btn btn-ghost">View public page &rarr;</a>
  </div>

  <?php if (empty($logos)): ?>
    <div class="portal-card"><p class="muted">No partner logos yet. <a href="?add=1">Add the first one.</a></p></div>
  <?php else: ?>
    <?php foreach ($logos as $i => $l):
      $img_rel = (string)($l['image'] ?? '');
      $img_url = '/assets/images/' . htmlspecialchars($img_rel);
    ?>
      <div class="portal-card slide-row">
        <div class="slide-thumb" style="background:#0a0d12 center/contain no-repeat;background-image:url('<?= $img_url ?>');"></div>
        <div class="slide-meta">
          <div class="muted small">LOGO <?= $i + 1 ?> &middot; <code><?= htmlspecialchars($img_rel) ?></code></div>
          <h2 style="margin:6px 0;"><?= htmlspecialchars($l['alt'] ?? '(no alt text)') ?></h2>
        </div>
        <div class="slide-actions">
          <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="idx" value="<?= $i ?>">
            <button name="dir" value="up"   class="btn btn-ghost btn-sm" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
            <button name="dir" value="down" class="btn btn-ghost btn-sm" <?= $i === count($logos) - 1 ? 'disabled' : '' ?>>&darr;</button>
          </form>
          <a href="?edit=<?= $i ?>" class="btn btn-ghost btn-sm">Edit</a>
          <form method="post" class="inline-form" data-confirm="Delete this logo?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="idx" value="<?= $i ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php else:
  $cur_image = $editing['image'] ?? '';
  $cur_alt   = $editing['alt']   ?? '';
?>

  <div class="portal-card">
    <h2><?= $editing ? 'Edit logo' : 'Add a partner logo' ?></h2>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($editing_idx !== null): ?>
        <input type="hidden" name="idx" value="<?= (int)$editing_idx ?>">
      <?php endif; ?>

      <div class="field">
        <label>Logo image</label>
        <?php if ($cur_image !== ''): ?>
          <div style="margin-bottom:10px;background:#0a0d12;padding:14px;border-radius:8px;border:1px solid var(--border);display:inline-block;">
            <img src="/assets/images/<?= htmlspecialchars($cur_image) ?>" alt="" style="max-height:80px;display:block;">
          </div>
          <div class="muted small" style="margin-bottom:10px;">Current: <code><?= htmlspecialchars($cur_image) ?></code></div>
        <?php endif; ?>
        <input type="file" name="logo_file" accept="image/jpeg,image/png,image/webp,image/svg+xml">
        <small class="muted">Max 4 MB. PNG, WebP or SVG with a transparent background works best &mdash; the homepage will tint it white. Aim for ~400px wide.</small>
        <input type="hidden" name="image" value="<?= htmlspecialchars($cur_image, ENT_QUOTES) ?>">
      </div>

      <div class="field">
        <label>Alt text <span class="muted">(partner name)</span></label>
        <input type="text" name="alt" required maxlength="80" value="<?= htmlspecialchars($cur_alt, ENT_QUOTES) ?>" placeholder="e.g. Ubiquiti">
      </div>

      <div class="form-actions" style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add logo' ?></button>
        <a href="/admin/partners.php" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
