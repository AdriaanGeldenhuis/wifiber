<?php
$page_title = 'Coverage';
$active_key = 'coverage';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/coverage.php';

$tab = (string)($_GET['tab'] ?? 'areas');
if (!in_array($tab, ['areas', 'waitlist'], true)) $tab = 'areas';

$cov = coverage_load();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_areas') {
        $new = [
            'intro' => (string)($_POST['intro'] ?? ''),
            'areas' => [],
        ];
        foreach ($_POST['area'] ?? [] as $a) {
            $new['areas'][] = [
                'name'    => (string)($a['name']    ?? ''),
                'aliases' => (string)($a['aliases'] ?? ''),
                'suburbs' => (string)($a['suburbs'] ?? ''),
            ];
        }
        if (coverage_save($new)) flash('success', 'Coverage areas saved.');
        else                      flash('error',   'Could not write data/coverage.json.');
        header('Location: /admin/coverage.php?tab=areas');
        exit;
    }

    if ($action === 'set_status') {
        try {
            waitlist_set_status((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''));
            flash('success', 'Lead status updated.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        header('Location: /admin/coverage.php?tab=waitlist');
        exit;
    }

    if ($action === 'delete_lead') {
        if (waitlist_delete((int)($_POST['id'] ?? 0))) flash('success', 'Lead removed.');
        else                                            flash('error',   'Could not remove lead.');
        header('Location: /admin/coverage.php?tab=waitlist');
        exit;
    }
}

$leads = $tab === 'waitlist' ? waitlist_all() : [];
?>

<div class="portal-head">
  <h1>Coverage</h1>
  <p class="portal-sub">Define the towns / suburbs you serve and review who's asked to be added to the waitlist.</p>
</div>

<div class="portal-card">
  <p class="inline-form" style="margin:0;">
    <a href="/admin/coverage.php?tab=areas"    class="btn btn-ghost btn-sm" <?= $tab === 'areas'    ? 'aria-current="page"' : '' ?>>Areas</a>
    <a href="/admin/coverage.php?tab=waitlist" class="btn btn-ghost btn-sm" <?= $tab === 'waitlist' ? 'aria-current="page"' : '' ?>>Waitlist (<?= count(waitlist_all()) ?>)</a>
  </p>
</div>

<?php if ($tab === 'areas'):
  $areas = $cov['areas'];
  $rows  = max(count($areas) + 2, 6);
?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_areas">

    <div class="portal-card">
      <h2>Public intro text</h2>
      <p class="muted">Shown above the search box on <a href="/coverage" target="_blank">/coverage</a>.</p>
      <div class="field">
        <textarea name="intro" rows="2" maxlength="400" placeholder="Type your address and we'll tell you in a second whether we can hook you up."><?= htmlspecialchars((string)$cov['intro']) ?></textarea>
      </div>
    </div>

    <div class="portal-card">
      <h2>Areas you serve</h2>
      <p class="muted">Aliases and suburbs are matched as substrings against the visitor's input. Separate multiple values with commas. Leave the name blank to remove a row.</p>
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:24%;">Name</th>
            <th style="width:32%;">Aliases (comma-separated)</th>
            <th>Suburbs (comma-separated)</th>
          </tr>
        </thead>
        <tbody>
          <?php for ($i = 0; $i < $rows; $i++):
            $a = $areas[$i] ?? ['name'=>'', 'aliases'=>[], 'suburbs'=>[]];
            $aliases = is_array($a['aliases'] ?? null) ? implode(', ', $a['aliases']) : (string)($a['aliases'] ?? '');
            $suburbs = is_array($a['suburbs'] ?? null) ? implode(', ', $a['suburbs']) : (string)($a['suburbs'] ?? '');
          ?>
            <tr>
              <td><input type="text" name="area[<?= $i ?>][name]"    maxlength="80"  value="<?= htmlspecialchars((string)($a['name'] ?? ''), ENT_QUOTES) ?>"></td>
              <td><input type="text" name="area[<?= $i ?>][aliases]" maxlength="400" value="<?= htmlspecialchars($aliases, ENT_QUOTES) ?>"></td>
              <td><input type="text" name="area[<?= $i ?>][suburbs]" maxlength="800" value="<?= htmlspecialchars($suburbs, ENT_QUOTES) ?>"></td>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <button type="submit" class="btn btn-primary">Save coverage areas</button>
  </form>

<?php else: /* waitlist tab */ ?>

  <div class="portal-card">
    <h2>Waitlist leads</h2>
    <?php if (empty($leads)): ?>
      <p class="muted">No waitlist signups yet.</p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr><th>Captured</th><th>Address</th><th>Contact</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $l): ?>
            <tr>
              <td class="muted small"><?= htmlspecialchars(substr((string)$l['created_at'], 0, 16)) ?></td>
              <td>
                <strong><?= htmlspecialchars($l['address']) ?></strong>
                <?php if (!empty($l['name'])): ?>
                  <br><span class="muted small"><?= htmlspecialchars($l['name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($l['notes'])): ?>
                  <br><span class="muted small"><?= htmlspecialchars($l['notes']) ?></span>
                <?php endif; ?>
              </td>
              <td class="muted small">
                <?php if (!empty($l['email'])): ?>
                  <a href="mailto:<?= htmlspecialchars($l['email']) ?>"><?= htmlspecialchars($l['email']) ?></a><br>
                <?php endif; ?>
                <?php if (!empty($l['phone'])): ?>
                  <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/','',$l['phone'])) ?>"><?= htmlspecialchars($l['phone']) ?></a>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <select name="status" data-auto-submit>
                    <?php foreach (WAITLIST_STATUSES as $s): ?>
                      <option value="<?= htmlspecialchars($s) ?>" <?= $l['status'] === $s ? 'selected' : '' ?>>
                        <?= htmlspecialchars(WAITLIST_STATUS_LABELS[$s]) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td class="row-actions">
                <form method="post" class="inline-form" data-confirm="Delete this lead?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_lead">
                  <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
