<?php
$page_title = 'Audit log';
$active_key = 'audit';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/csv.php';

$action_filter = trim((string)($_GET['action'] ?? ''));
$user_filter   = (int)($_GET['user_id'] ?? 0);
$limit         = (int)($_GET['limit'] ?? 200);
if ($limit < 25)   $limit = 25;
if ($limit > 5000) $limit = 5000;

// Date range. Defaults to last 30 days; both bounds are optional.
$from_filter = trim((string)($_GET['from'] ?? ''));
$to_filter   = trim((string)($_GET['to']   ?? ''));
$valid_date  = fn($s) => $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$from_iso = $valid_date($from_filter) ? $from_filter : null;
$to_iso   = $valid_date($to_filter)   ? $to_filter   : null;

// CSV export — same filter set, capped at 50 000 rows.
if (($_GET['export'] ?? '') === 'csv') {
    $export_rows = audit_recent(50000, $action_filter ?: null, $user_filter ?: null, $from_iso, $to_iso);
    audit_log('audit.export', ['target_type' => 'audit_log', 'meta' => ['rows' => count($export_rows)]]);
    csv_download('audit-log', $export_rows, [
        'created_at', 'username', 'user_id', 'action',
        'target_type', 'target_id', 'meta', 'ip_address',
    ]);
}

$rows = audit_recent($limit, $action_filter ?: null, $user_filter ?: null, $from_iso, $to_iso);

// Build a list of distinct actions seen recently for the filter dropdown.
$actions_seen = pdo()->query(
    "SELECT action, COUNT(*) AS c
     FROM audit_log
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
     GROUP BY action
     ORDER BY action ASC"
)->fetchAll();

$users_seen = pdo()->query(
    "SELECT DISTINCT user_id, username
     FROM audit_log
     WHERE user_id IS NOT NULL
     ORDER BY username ASC"
)->fetchAll();
?>

<div class="portal-head">
  <h1>Audit log</h1>
  <p class="portal-sub">Who did what, when. Logins, password changes, 2FA activity, account mutations and admin actions land here.</p>
</div>

<div class="portal-card">
  <form method="get" class="form form-grid">
    <div class="field">
      <label>Action contains</label>
      <input type="text" name="action" value="<?= htmlspecialchars($action_filter, ENT_QUOTES) ?>" placeholder="e.g. login, password, user.delete">
    </div>
    <div class="field">
      <label>User</label>
      <select name="user_id">
        <option value="0">— anyone —</option>
        <?php foreach ($users_seen as $u): ?>
          <option value="<?= (int)$u['user_id'] ?>" <?= $user_filter === (int)$u['user_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($u['username'] ?? '#' . $u['user_id']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from_filter, ENT_QUOTES) ?>">
    </div>
    <div class="field">
      <label>To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to_filter, ENT_QUOTES) ?>">
    </div>
    <div class="field">
      <label>Limit</label>
      <select name="limit">
        <?php foreach ([100, 200, 500, 1000, 5000] as $n): ?>
          <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/admin/audit.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php
        // Build the export URL with the same filters
        $export_qs = http_build_query(array_filter([
          'action'  => $action_filter,
          'user_id' => $user_filter ?: null,
          'from'    => $from_filter,
          'to'      => $to_filter,
          'export'  => 'csv',
        ]));
      ?>
      <a href="/admin/audit.php?<?= htmlspecialchars($export_qs) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
    </div>
  </form>
</div>

<?php if ($action_filter === '' && $user_filter === 0 && !empty($actions_seen)): ?>
  <div class="portal-card">
    <h2>Recent actions (last 90 days)</h2>
    <p class="inline-form" style="margin:0;flex-wrap:wrap;">
      <?php foreach ($actions_seen as $a): ?>
        <a href="/admin/audit.php?action=<?= urlencode($a['action']) ?>" class="btn btn-ghost btn-sm">
          <?= htmlspecialchars($a['action']) ?>
          <span class="muted small">(<?= (int)$a['c'] ?>)</span>
        </a>
      <?php endforeach; ?>
    </p>
  </div>
<?php endif; ?>

<div class="portal-card">
  <h2><?= count($rows) ?> entries <?= $action_filter ? '(filter: ' . htmlspecialchars($action_filter) . ')' : '' ?></h2>
  <?php if (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-icon">∅</div>
      <h3>Nothing matches</h3>
      <p>No audit entries for that filter combination. Try widening the date range or clearing the filters.</p>
      <a class="btn btn-ghost" href="/admin/audit.php">Clear filters</a>
    </div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $meta = $r['meta'] ? (json_decode($r['meta'], true) ?: $r['meta']) : null;
        ?>
          <tr>
            <td class="muted small"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
            <td>
              <?php if (!empty($r['username'])): ?>
                <strong><?= htmlspecialchars($r['username']) ?></strong>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><code><?= htmlspecialchars($r['action']) ?></code></td>
            <td class="muted small">
              <?php if (!empty($r['target_type'])): ?>
                <?= htmlspecialchars($r['target_type']) ?>#<?= (int)$r['target_id'] ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="muted small">
              <?php if (is_array($meta)):
                $bits = [];
                foreach ($meta as $k => $v) {
                  if (is_scalar($v)) $bits[] = htmlspecialchars((string)$k) . '=' . htmlspecialchars((string)$v);
                }
                echo implode(' &middot; ', $bits);
              else: ?>—<?php endif; ?>
            </td>
            <td class="muted small"><?= htmlspecialchars((string)($r['ip_address'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
