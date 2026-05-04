<?php
$page_title = 'Coverage';
$active_key = 'coverage';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/coverage.php';
require_once __DIR__ . '/../auth/sites.php';

$tab = (string)($_GET['tab'] ?? 'areas');
if (!in_array($tab, ['areas', 'waitlist', 'lookup'], true)) $tab = 'areas';

$cov = coverage_load();

// Address-picker AJAX endpoints — return JSON and exit before the page
// chrome renders. Auth has already been enforced by _layout.php.
if (isset($_GET['suggest'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $results = nominatim_search((string)$_GET['suggest'], 5);
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}
if (isset($_GET['reverse_lat'], $_GET['reverse_lng'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $name = nominatim_reverse((float)$_GET['reverse_lat'], (float)$_GET['reverse_lng']);
    echo json_encode(['ok' => true, 'display_name' => $name]);
    exit;
}
if (isset($_GET['coverage_check'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    $r = coverage_check((string)$_GET['coverage_check']);
    echo json_encode(['ok' => true, 'result' => $r]);
    exit;
}

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
    <a href="/admin/coverage.php?tab=lookup"   class="btn btn-ghost btn-sm" <?= $tab === 'lookup'   ? 'aria-current="page"' : '' ?>>Address lookup</a>
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

<?php elseif ($tab === 'lookup'): ?>

  <div class="portal-card" data-addr-picker="/admin/coverage.php?tab=lookup" data-addr-coverage>
    <h2>Address lookup</h2>
    <p class="muted">Start typing an address &mdash; suggestions are pulled from OpenStreetMap (South Africa). Pick a suggestion, click the map, or drag the pin to place a point. We'll tell you whether it falls inside one of your coverage areas.</p>
    <div class="form form-grid">
      <div class="field" style="grid-column:1/-1; position:relative;">
        <label>Address <span class="muted small">(start typing for suggestions)</span></label>
        <input type="text" maxlength="200" placeholder="e.g. 12 Main Street, Vanderbijlpark" autocomplete="new-password" data-addr-input data-1p-ignore data-lpignore="true">
        <div class="addr-suggestions" hidden data-addr-suggestions></div>
      </div>
      <div class="field"><label>Latitude</label>
        <input type="number" step="any" placeholder="-26.7100000" data-addr-lat>
      </div>
      <div class="field"><label>Longitude</label>
        <input type="number" step="any" placeholder="27.8300000" data-addr-lng>
      </div>
    </div>
    <div class="addr-map" aria-label="Click or drag the pin to set GPS coordinates" data-addr-map></div>
    <p class="muted small" data-addr-hint style="margin:8px 0 0;">
      Click anywhere on the map to drop a pin, drag it to fine-tune, or pick a suggestion above.
    </p>
    <div class="form-actions" style="margin-top:8px; flex-wrap:wrap;">
      <button type="button" class="btn btn-ghost btn-sm" data-addr-locate>Use my location</button>
      <button type="button" class="btn btn-ghost btn-sm" data-addr-reverse>Fill address from pin</button>
      <button type="button" class="btn btn-ghost btn-sm" data-addr-clear>Clear</button>
    </div>
    <div class="cov-result" hidden data-addr-result></div>
  </div>

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

<?php if ($tab === 'lookup'): ?>
<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="anonymous">
<style>
  .addr-suggestions {
    position: absolute;
    top: 100%; left: 0; right: 0;
    background: var(--bg-card, #1a1d24);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    z-index: 1000;
    max-height: 240px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.4);
    margin-top: 2px;
  }
  .addr-suggestion {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
    color: var(--text-dim);
  }
  .addr-suggestion:last-child { border-bottom: none; }
  .addr-suggestion:hover,
  .addr-suggestion.is-active {
    background: var(--accent-soft);
    color: var(--accent);
  }
  .addr-map {
    height: 360px;
    margin-top: 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: #0a0d12;
  }
  .leaflet-container { font-family: inherit; }
  .cov-result {
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    font-size: 14px;
  }
  .cov-result.is-yes { border-color: #2dbf73; background: rgba(45,191,115,.08); }
  .cov-result.is-no  { border-color: #ff7a8c; background: rgba(255,122,140,.08); }
  .cov-result strong { display: block; margin-bottom: 4px; }
</style>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous" defer></script>
<script src="/assets/js/admin-addr-picker.js" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
