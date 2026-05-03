<?php
$page_title = 'Image library';
$active_key = 'images';
require __DIR__ . '/_layout.php';

$dir = __DIR__ . '/../assets/images/library';
$rel = '/assets/images/library';
@mkdir($dir, 0755, true);

function img_safe_filename(string $name): string {
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
    $base = preg_replace('/[^a-z0-9_-]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME) ?: 'image'));
    $base = trim($base, '-') ?: 'image';
    return substr($base, 0, 40) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $files = $_FILES['files'] ?? null;
        $count_ok = 0; $errors = [];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/gif' => 'gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $orig_name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if ($files['size'][$i] > 10 * 1024 * 1024) { $errors[] = "{$orig_name}: too large (10 MB max)"; continue; }
                $mime = $finfo->file($files['tmp_name'][$i]) ?: '';
                if (!isset($allowed[$mime])) { $errors[] = "{$orig_name}: unsupported file type ({$mime})"; continue; }
                $name = preg_replace('/\.[^.]+$/', '.' . $allowed[$mime], img_safe_filename($orig_name));
                if (@move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
                    $count_ok++;
                } else {
                    $errors[] = "{$orig_name}: failed to save";
                }
            }
        }
        if ($count_ok) flash('success', "{$count_ok} image" . ($count_ok > 1 ? 's' : '') . " uploaded." . ($errors ? ' (' . count($errors) . ' failed)' : ''));
        elseif ($errors) flash('error', implode('; ', $errors));
        header('Location: /admin/images.php');
        exit;
    }

    if ($action === 'delete') {
        $name = basename((string)($_POST['name'] ?? ''));
        $path = $dir . '/' . $name;
        if ($name !== '' && is_file($path)) {
            @unlink($path);
            flash('success', "Deleted {$name}.");
        }
        header('Location: /admin/images.php');
        exit;
    }
}

$files = [];
foreach (glob($dir . '/*') ?: [] as $p) {
    if (!is_file($p)) continue;
    $files[] = [
        'name' => basename($p),
        'size' => filesize($p),
        'time' => filemtime($p),
    ];
}
usort($files, fn($a, $b) => $b['time'] <=> $a['time']);

function fmt_bytes(int $b): string {
    if ($b < 1024)        return $b . ' B';
    if ($b < 1024 * 1024) return number_format($b / 1024, 1) . ' KB';
    return number_format($b / 1024 / 1024, 1) . ' MB';
}
?>

<div class="portal-head">
  <h1>Image library</h1>
  <p class="portal-sub">Upload images here, then copy the URL to use anywhere on the site (or paste into the slider/legal editor). 10 MB max per file. JPG, PNG, WEBP, SVG and GIF supported.</p>
</div>

<div class="portal-card">
  <h2>Upload images</h2>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="field">
      <input type="file" name="files[]" accept="image/jpeg,image/png,image/webp,image/svg+xml,image/gif" multiple required>
      <small class="muted">Tip: hold Ctrl/Cmd to select multiple files at once.</small>
    </div>
    <button type="submit" class="btn btn-primary">Upload</button>
  </form>
</div>

<div class="portal-card">
  <h2>Library (<?= count($files) ?>)</h2>
  <?php if (empty($files)): ?>
    <p class="muted">Empty &mdash; upload your first image above.</p>
  <?php else: ?>
    <div class="image-grid">
      <?php foreach ($files as $f):
        $url = $rel . '/' . htmlspecialchars($f['name']);
      ?>
        <div class="image-tile">
          <a href="<?= $url ?>" target="_blank" class="image-thumb" style="background-image:url('<?= $url ?>');"></a>
          <div class="image-meta">
            <div class="muted small"><?= fmt_bytes($f['size']) ?> &middot; <?= date('Y-m-d', $f['time']) ?></div>
            <input type="text" readonly class="copy-url" value="<?= $url ?>" onclick="this.select();" title="Click to select, then copy">
          </div>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete <?= htmlspecialchars($f['name'], ENT_QUOTES) ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="name" value="<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
