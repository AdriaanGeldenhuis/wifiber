<?php
$page_title = 'Legal pages';
$active_key = 'legal';
require __DIR__ . '/_layout.php';

$file = __DIR__ . '/../data/legal.json';
$data = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
$sections = $data['sections'] ?? [];

function legal_save_all(string $file, array $sections): bool {
    return json_save($file, ['sections' => array_values($sections)]);
}

function legal_safe_key(string $s): string {
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));
    return trim($s, '-') ?: 'section';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $idx = isset($_POST['idx']) && $_POST['idx'] !== '' ? (int)$_POST['idx'] : null;
        $payload = [
            'key'     => legal_safe_key($_POST['key']    ?? ($_POST['title'] ?? 'section')),
            'title'   => trim($_POST['title']   ?? ''),
            'label'   => trim($_POST['label']   ?? ''),
            'content' => $_POST['content'] ?? '',
        ];
        if ($payload['title'] === '')   { flash('error', 'Title is required.'); }
        elseif ($payload['content'] === '') { flash('error', 'Content cannot be empty.'); }
        else {
            if ($payload['label'] === '') $payload['label'] = $payload['title'];
            if ($idx !== null && isset($sections[$idx])) {
                $sections[$idx] = $payload;
                flash('success', 'Section saved.');
            } else {
                $sections[] = $payload;
                flash('success', 'Section added.');
            }
            legal_save_all($file, $sections);
        }
        header('Location: /admin/legal.php');
        exit;
    }

    if ($action === 'delete') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($sections[$idx])) {
            array_splice($sections, $idx, 1);
            legal_save_all($file, $sections);
            flash('success', 'Section removed.');
        }
        header('Location: /admin/legal.php');
        exit;
    }

    if ($action === 'move') {
        $idx = (int)($_POST['idx'] ?? -1);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        if (isset($sections[$idx]) && isset($sections[$idx + $dir])) {
            [$sections[$idx], $sections[$idx + $dir]] = [$sections[$idx + $dir], $sections[$idx]];
            legal_save_all($file, $sections);
        }
        header('Location: /admin/legal.php');
        exit;
    }
}

$editing_idx = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editing     = $editing_idx !== null && isset($sections[$editing_idx]) ? $sections[$editing_idx] : null;
$show_form   = isset($_GET['edit']) || isset($_GET['add']);
?>

<div class="portal-head">
  <h1>Legal pages</h1>
  <p class="portal-sub">Manage the sections shown on /legal. HTML is allowed in the content (use <code>&lt;h3&gt;</code>, <code>&lt;p&gt;</code>, <code>&lt;ul&gt;&lt;li&gt;</code>, <code>&lt;a href=&quot;...&quot;&gt;</code> &mdash; the same tags the public page renders).</p>
</div>

<?php if (!$show_form): ?>

  <div style="margin-bottom:20px;">
    <a href="?add=1" class="btn btn-primary">+ Add section</a>
  </div>

  <?php if (empty($sections)): ?>
    <div class="portal-card"><p class="muted">No sections yet. <a href="?add=1">Add the first one.</a></p></div>
  <?php else: ?>
    <?php foreach ($sections as $i => $s): ?>
      <div class="portal-card slide-row">
        <div class="slide-meta" style="grid-column: 1 / 3;">
          <div class="muted small">SECTION <?= $i + 1 ?> &middot; key: <code><?= htmlspecialchars($s['key'] ?? '') ?></code></div>
          <h2 style="margin:6px 0;"><?= htmlspecialchars($s['title'] ?? '(untitled)') ?></h2>
          <div class="muted small"><?= htmlspecialchars(mb_substr(strip_tags((string)($s['content'] ?? '')), 0, 140)) ?>&hellip;</div>
        </div>
        <div class="slide-actions">
          <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="idx" value="<?= $i ?>">
            <button name="dir" value="up"   class="btn btn-ghost btn-sm" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
            <button name="dir" value="down" class="btn btn-ghost btn-sm" <?= $i === count($sections) - 1 ? 'disabled' : '' ?>>&darr;</button>
          </form>
          <a href="?edit=<?= $i ?>" class="btn btn-ghost btn-sm">Edit</a>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this section?');">
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
    <h2><?= $editing ? 'Edit section' : 'Add section' ?></h2>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($editing_idx !== null): ?>
        <input type="hidden" name="idx" value="<?= (int)$editing_idx ?>">
      <?php endif; ?>

      <div class="form form-grid">
        <div class="field"><label>Title <span class="muted">(shown as &lt;h2&gt;)</span></label>
          <input type="text" name="title" required maxlength="120" value="<?= htmlspecialchars($editing['title'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field"><label>Sidebar label</label>
          <input type="text" name="label" maxlength="60" value="<?= htmlspecialchars($editing['label'] ?? '', ENT_QUOTES) ?>" placeholder="defaults to title">
        </div>
        <div class="field"><label>Key <span class="muted">(URL-safe, used internally)</span></label>
          <input type="text" name="key" maxlength="40" value="<?= htmlspecialchars($editing['key'] ?? '', ENT_QUOTES) ?>" placeholder="auto-generated from title">
        </div>
      </div>

      <div class="field">
        <label>Content (HTML)</label>
        <textarea name="content" rows="22" required><?= htmlspecialchars($editing['content'] ?? '') ?></textarea>
        <small class="muted">Allowed tags: <code>&lt;p&gt;</code>, <code>&lt;h3&gt;</code>, <code>&lt;ul&gt;&lt;li&gt;</code>, <code>&lt;ol&gt;</code>, <code>&lt;a href=&quot;&quot;&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;br&gt;</code>. The page already wraps the title in &lt;h2&gt; for you.</small>
      </div>

      <div class="form-actions" style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add section' ?></button>
        <a href="/admin/legal.php" class="btn btn-ghost">Cancel</a>
        <a href="/legal" target="_blank" class="btn btn-ghost" style="margin-left:auto;">View public page &rarr;</a>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
