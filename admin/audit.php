<?php
$page_title = 'Audit log';
$active_key = 'audit';
require __DIR__ . '/_layout.php';

$action_filter = trim((string)($_GET['action'] ?? ''));
$user_filter   = (int)($_GET['user_id'] ?? 0);
$limit         = (int)($_GET['limit'] ?? 200);
if ($limit < 25)   $limit = 25;
if ($limit > 1000) $limit = 1000;

$rows = audit_recent($limit, $action_filter ?: null, $user_filter ?: null);

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
      <label>Limit</label>
      <select name="limit">
        <?php foreach ([100, 200, 500, 1000] as $n): ?>
          <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/admin/audit.php" class="btn btn-ghost btn-sm">Clear</a>
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
    <p class="muted">Nothing yet.</p>
  <?php else: ?>
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
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
