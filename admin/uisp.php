<?php
/**
 * UISP integration settings, sync controls, and adoption table.
 *
 * - Save form is a regular POST + redirect (settings-page style)
 * - "Test connection" / "Sync now" / "Link record" buttons are AJAX (?ajax=1)
 *   and reuse the same $reply() pattern as admin/map.php
 */

$page_title = 'UISP integration';
$active_key = 'uisp';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/uisp_sync.php';

$is_ajax = !empty($_GET['ajax']);
$reply   = function (array $payload) use ($is_ajax) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!$is_ajax) {
        flash($payload['ok'] ? 'success' : 'error',
              (string)($payload['message'] ?? $payload['error'] ?? 'OK'));
        header('Location: /admin/uisp.php');
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'save_config';
    try {
        switch ($action) {
            case 'save_config': {
                $base = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
                if ($base !== '' && !preg_match('#^https?://#i', $base)) {
                    throw new InvalidArgumentException('Base URL must start with http:// or https://');
                }
                $patch = [
                    'base_url'              => $base,
                    'api_token'             => trim((string)($_POST['api_token'] ?? '')),
                    'verify_ssl'            => !empty($_POST['verify_ssl']),
                    'enabled'               => [
                        'sites'   => !empty($_POST['enable_sites']),
                        'devices' => !empty($_POST['enable_devices']),
                        'links'   => !empty($_POST['enable_links']),
                        'clients' => !empty($_POST['enable_clients']),
                    ],
                    'sync_interval_minutes' => max(1, (int)($_POST['sync_interval_minutes'] ?? 15)),
                ];
                if (!uisp_save_config($patch)) {
                    throw new RuntimeException('Could not write data/uisp.json. Check permissions.');
                }
                audit_log('uisp.config.save', ['meta' => [
                    'base_url'   => $base,
                    'has_token'  => !empty($patch['api_token']),
                    'verify_ssl' => $patch['verify_ssl'],
                ]]);
                $reply(['ok' => true, 'message' => 'UISP settings saved.']);
                break;
            }
            case 'test_connection': {
                $r = uisp_test_connection();
                $reply([
                    'ok'      => !empty($r['ok']),
                    'message' => $r['message'] ?? '',
                    'version' => $r['version'] ?? null,
                ]);
                break;
            }
            case 'sync_now': {
                $r = uisp_sync_all();
                $reply([
                    'ok'      => !empty($r['ok']),
                    'message' => $r['ok']
                        ? 'Synced.'
                        : ('Sync failed: ' . implode(' | ', $r['errors'] ?? [])),
                    'counts'  => $r['counts']  ?? null,
                    'errors'  => $r['errors']  ?? null,
                    'version' => $r['version'] ?? null,
                ]);
                break;
            }
            case 'link_site': {
                $site_id = (int)($_POST['site_id'] ?? 0);
                $uisp_id = trim((string)($_POST['uisp_id'] ?? ''));
                if ($site_id <= 0 || $uisp_id === '') {
                    throw new InvalidArgumentException('Pick a site and a UISP id.');
                }
                pdo()->prepare("UPDATE sites SET uisp_id = ? WHERE id = ?")
                     ->execute([$uisp_id, $site_id]);
                audit_log('uisp.link_site', [
                    'target_type' => 'site', 'target_id' => $site_id,
                    'meta'        => ['uisp_id' => $uisp_id],
                ]);
                $reply(['ok' => true, 'message' => 'Linked site to UISP.']);
                break;
            }
            case 'unlink_site': {
                $site_id = (int)($_POST['site_id'] ?? 0);
                if ($site_id <= 0) throw new InvalidArgumentException('No site id.');
                pdo()->prepare("UPDATE sites SET uisp_id = NULL WHERE id = ?")->execute([$site_id]);
                audit_log('uisp.unlink_site', ['target_type' => 'site', 'target_id' => $site_id]);
                $reply(['ok' => true, 'message' => 'Unlinked.']);
                break;
            }
            case 'link_client': {
                $user_id = (int)($_POST['user_id'] ?? 0);
                $uisp_id = trim((string)($_POST['uisp_id'] ?? ''));
                if ($user_id <= 0 || $uisp_id === '') {
                    throw new InvalidArgumentException('Pick a client and a UISP id.');
                }
                pdo()->prepare("UPDATE users SET uisp_client_id = ? WHERE id = ?")
                     ->execute([$uisp_id, $user_id]);
                audit_log('uisp.link_client', [
                    'target_type' => 'user', 'target_id' => $user_id,
                    'meta'        => ['uisp_client_id' => $uisp_id],
                ]);
                $reply(['ok' => true, 'message' => 'Linked client to UISP.']);
                break;
            }
            case 'unlink_client': {
                $user_id = (int)($_POST['user_id'] ?? 0);
                if ($user_id <= 0) throw new InvalidArgumentException('No user id.');
                pdo()->prepare("UPDATE users SET uisp_client_id = NULL WHERE id = ?")->execute([$user_id]);
                audit_log('uisp.unlink_client', ['target_type' => 'user', 'target_id' => $user_id]);
                $reply(['ok' => true, 'message' => 'Unlinked.']);
                break;
            }
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        $reply(['ok' => false, 'error' => $e->getMessage()]);
    }
}

$cfg       = uisp_config();
$has_creds = uisp_is_configured();

function uisp_table_count(string $table): int {
    try {
        return (int)pdo()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
$cache_counts = [
    'sites'      => uisp_table_count('uisp_sites'),
    'devices'    => uisp_table_count('uisp_devices'),
    'data_links' => uisp_table_count('uisp_data_links'),
    'clients'    => uisp_table_count('uisp_clients'),
];

$unlinked_sites = $has_creds && $cache_counts['sites'] ? pdo()->query(
    "SELECT us.uisp_id, us.name, us.address
       FROM uisp_sites us
      WHERE us.is_stale = 0
        AND NOT EXISTS (SELECT 1 FROM sites s WHERE s.uisp_id = us.uisp_id)
      ORDER BY us.name ASC
      LIMIT 200"
)->fetchAll() : [];

$manual_sites_unlinked = pdo()->query(
    "SELECT id, name, type FROM sites WHERE uisp_id IS NULL ORDER BY name ASC"
)->fetchAll();

$unlinked_clients = $has_creds && $cache_counts['clients'] ? pdo()->query(
    "SELECT uc.uisp_id, uc.name, uc.email, uc.address_full
       FROM uisp_clients uc
      WHERE uc.is_stale = 0
        AND NOT EXISTS (SELECT 1 FROM users u WHERE u.uisp_client_id = uc.uisp_id)
      ORDER BY uc.name ASC
      LIMIT 200"
)->fetchAll() : [];

$manual_clients_unlinked = pdo()->query(
    "SELECT id, account_no, name, email
       FROM users
      WHERE role = 'client' AND uisp_client_id IS NULL
      ORDER BY name ASC"
)->fetchAll();

$linked_sites = pdo()->query(
    "SELECT s.id, s.name, s.type, s.uisp_id, us.name AS uisp_name
       FROM sites s
       LEFT JOIN uisp_sites us ON us.uisp_id = s.uisp_id
      WHERE s.uisp_id IS NOT NULL
      ORDER BY s.name ASC"
)->fetchAll();

$linked_clients = pdo()->query(
    "SELECT u.id, u.account_no, u.name, u.uisp_client_id, uc.name AS uisp_name
       FROM users u
       LEFT JOIN uisp_clients uc ON uc.uisp_id = u.uisp_client_id
      WHERE u.role = 'client' AND u.uisp_client_id IS NOT NULL
      ORDER BY u.name ASC"
)->fetchAll();
?>

<div class="portal-head">
  <h1>UISP integration</h1>
  <p class="portal-sub">Pull live network and client data from UISP onto the network map. Read-only — UISP stays the source of truth.</p>
</div>

<form method="post" class="form" id="uisp-config-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_config">

  <div class="portal-card">
    <h2>Connection</h2>
    <div class="form form-grid">
      <div class="field">
        <label>UISP base URL</label>
        <input type="url" name="base_url" maxlength="200"
               value="<?= htmlspecialchars((string)$cfg['base_url'], ENT_QUOTES) ?>"
               placeholder="https://uisp.example.com">
        <small class="muted">Just the host. Paths like <code>/nms/api/v2.1</code> are added automatically.</small>
      </div>
      <div class="field">
        <label>API token (App key)</label>
        <input type="password" name="api_token" maxlength="200" autocomplete="off"
               value="<?= htmlspecialchars((string)$cfg['api_token'], ENT_QUOTES) ?>"
               placeholder="Generated under UISP &rarr; Settings &rarr; Integrations">
      </div>
      <div class="field">
        <label class="inline-check" style="display:flex;align-items:center;gap:6px;">
          <input type="checkbox" name="verify_ssl" value="1" <?= !empty($cfg['verify_ssl']) ? 'checked' : '' ?>>
          Verify SSL certificate
        </label>
        <small class="muted">Disable only for self-hosted UISP with a self-signed cert.</small>
      </div>
      <div class="field">
        <label>Auto-sync interval (minutes)</label>
        <input type="number" min="1" max="1440" name="sync_interval_minutes"
               value="<?= (int)($cfg['sync_interval_minutes'] ?? 15) ?>">
      </div>
    </div>
  </div>

  <div class="portal-card">
    <h2>What to sync</h2>
    <div class="form form-grid">
      <label class="inline-check" style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="enable_sites"   value="1" <?= !empty($cfg['enabled']['sites'])   ? 'checked' : '' ?>> Sites (towers / PoPs)</label>
      <label class="inline-check" style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="enable_devices" value="1" <?= !empty($cfg['enabled']['devices']) ? 'checked' : '' ?>> Devices (APs, radios, switches)</label>
      <label class="inline-check" style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="enable_links"   value="1" <?= !empty($cfg['enabled']['links'])   ? 'checked' : '' ?>> Data-links (PtP / PtMP)</label>
      <label class="inline-check" style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="enable_clients" value="1" <?= !empty($cfg['enabled']['clients']) ? 'checked' : '' ?>> CRM clients</label>
    </div>
  </div>

  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:18px;">
    <button type="submit" class="btn btn-primary">Save settings</button>
    <button type="button" class="btn btn-ghost" id="uisp-test-btn"  <?= $has_creds ? '' : 'disabled' ?>>Test connection</button>
    <button type="button" class="btn btn-ghost" id="uisp-sync-btn"  <?= $has_creds ? '' : 'disabled' ?>>Sync now</button>
    <span id="uisp-status" class="muted small"></span>
  </div>
</form>

<div class="portal-card">
  <h2>Status</h2>
  <table class="data-table">
    <tbody>
      <tr><th style="width:200px;">Last sync</th>
          <td><?= !empty($cfg['last_sync_at']) ? htmlspecialchars((string)$cfg['last_sync_at']) : '<span class="muted">never</span>' ?></td></tr>
      <tr><th>Status</th>      <td><?= htmlspecialchars((string)($cfg['last_sync_status']  ?? '—')) ?></td></tr>
      <tr><th>UISP version</th><td><?= htmlspecialchars((string)($cfg['last_sync_version'] ?? '—')) ?></td></tr>
      <tr><th>Cache: sites</th>      <td><?= (int)$cache_counts['sites'] ?></td></tr>
      <tr><th>Cache: devices</th>    <td><?= (int)$cache_counts['devices'] ?></td></tr>
      <tr><th>Cache: data-links</th> <td><?= (int)$cache_counts['data_links'] ?></td></tr>
      <tr><th>Cache: clients</th>    <td><?= (int)$cache_counts['clients'] ?></td></tr>
      <?php if (!empty($cfg['last_sync_error'])): ?>
        <tr><th>Last error</th><td class="muted"><?= htmlspecialchars((string)$cfg['last_sync_error']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($has_creds && ($unlinked_sites || $unlinked_clients || $linked_sites || $linked_clients)): ?>
<div class="portal-card">
  <h2>Adopt UISP records</h2>
  <p class="muted">Link a UISP entity to an existing manual record so they merge into one marker on the map. Linking is reversible.</p>

  <?php if ($unlinked_sites): ?>
    <h3 style="font-size:1rem;margin-top:16px;color:var(--text);">UISP sites to link (<?= count($unlinked_sites) ?>)</h3>
    <table class="data-table">
      <thead><tr><th>UISP site</th><th>Address</th><th style="width:340px;">Link to manual site</th></tr></thead>
      <tbody>
        <?php foreach ($unlinked_sites as $us): ?>
          <tr>
            <td><strong><?= htmlspecialchars((string)$us['name']) ?></strong><br><small class="muted"><?= htmlspecialchars((string)$us['uisp_id']) ?></small></td>
            <td><?= htmlspecialchars((string)($us['address'] ?? '')) ?></td>
            <td>
              <form method="post" data-uisp-link style="display:flex;gap:6px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="link_site">
                <input type="hidden" name="uisp_id" value="<?= htmlspecialchars((string)$us['uisp_id'], ENT_QUOTES) ?>">
                <select name="site_id" required style="flex:1;">
                  <option value="">— pick a manual site —</option>
                  <?php foreach ($manual_sites_unlinked as $ms): ?>
                    <option value="<?= (int)$ms['id'] ?>"><?= htmlspecialchars($ms['name'] . ' (' . $ms['type'] . ')') ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-ghost btn-sm">Link</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($linked_sites): ?>
    <h3 style="font-size:1rem;margin-top:24px;color:var(--text);">Linked sites (<?= count($linked_sites) ?>)</h3>
    <table class="data-table">
      <thead><tr><th>Manual site</th><th>UISP site</th><th style="width:120px;">Action</th></tr></thead>
      <tbody>
        <?php foreach ($linked_sites as $ls): ?>
          <tr>
            <td><?= htmlspecialchars($ls['name'] . ' (' . $ls['type'] . ')') ?></td>
            <td>
              <?= htmlspecialchars((string)($ls['uisp_name'] ?? '(missing)')) ?>
              <br><small class="muted"><?= htmlspecialchars((string)$ls['uisp_id']) ?></small>
            </td>
            <td>
              <form method="post" data-uisp-link>
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="unlink_site">
                <input type="hidden" name="site_id" value="<?= (int)$ls['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Unlink</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($unlinked_clients): ?>
    <h3 style="font-size:1rem;margin-top:24px;color:var(--text);">UISP clients to link (<?= count($unlinked_clients) ?>)</h3>
    <table class="data-table">
      <thead><tr><th>UISP client</th><th>Email / Address</th><th style="width:340px;">Link to portal client</th></tr></thead>
      <tbody>
        <?php foreach ($unlinked_clients as $uc): ?>
          <tr>
            <td><strong><?= htmlspecialchars((string)$uc['name']) ?></strong><br><small class="muted"><?= htmlspecialchars((string)$uc['uisp_id']) ?></small></td>
            <td>
              <?= htmlspecialchars((string)($uc['email'] ?? '')) ?>
              <?php if (!empty($uc['address_full'])): ?><br><small class="muted"><?= htmlspecialchars((string)$uc['address_full']) ?></small><?php endif; ?>
            </td>
            <td>
              <form method="post" data-uisp-link style="display:flex;gap:6px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="link_client">
                <input type="hidden" name="uisp_id" value="<?= htmlspecialchars((string)$uc['uisp_id'], ENT_QUOTES) ?>">
                <select name="user_id" required style="flex:1;">
                  <option value="">— pick a portal client —</option>
                  <?php foreach ($manual_clients_unlinked as $mu): ?>
                    <option value="<?= (int)$mu['id'] ?>">
                      <?= htmlspecialchars(($mu['account_no'] ?: '#'.$mu['id']) . ' · ' . $mu['name'] . ($mu['email'] ? ' · '.$mu['email'] : '')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-ghost btn-sm">Link</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($linked_clients): ?>
    <h3 style="font-size:1rem;margin-top:24px;color:var(--text);">Linked clients (<?= count($linked_clients) ?>)</h3>
    <table class="data-table">
      <thead><tr><th>Portal client</th><th>UISP client</th><th style="width:120px;">Action</th></tr></thead>
      <tbody>
        <?php foreach ($linked_clients as $lc): ?>
          <tr>
            <td><?= htmlspecialchars(($lc['account_no'] ?: '#'.$lc['id']) . ' · ' . $lc['name']) ?></td>
            <td>
              <?= htmlspecialchars((string)($lc['uisp_name'] ?? '(missing)')) ?>
              <br><small class="muted"><?= htmlspecialchars((string)$lc['uisp_client_id']) ?></small>
            </td>
            <td>
              <form method="post" data-uisp-link>
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="unlink_client">
                <input type="hidden" name="user_id" value="<?= (int)$lc['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Unlink</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';
  const csrf = <?= json_encode(csrf_token()) ?>;
  const status = document.getElementById('uisp-status');

  function setStatus(msg, ok) {
    if (!status) return;
    status.textContent = msg;
    status.style.color = ok === true  ? '#4caf50'
                       : ok === false ? '#ef5350'
                       : '';
  }

  async function call(action) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', csrf);
    const res = await fetch('/admin/uisp.php?ajax=1', { method:'POST', body:fd, credentials:'same-origin' });
    try { return await res.json(); } catch (e) { return { ok:false, error:'Server error' }; }
  }

  document.getElementById('uisp-test-btn')?.addEventListener('click', async () => {
    setStatus('Testing connection…');
    const r = await call('test_connection');
    if (r.ok) setStatus('OK' + (r.version ? (' · UISP ' + r.version) : ''), true);
    else      setStatus('Failed: ' + (r.error || r.message || 'unknown'), false);
  });

  document.getElementById('uisp-sync-btn')?.addEventListener('click', async () => {
    setStatus('Syncing…');
    const r = await call('sync_now');
    if (r.ok) {
      const c = r.counts || {};
      setStatus(`Synced — sites=${c.sites||0} devices=${c.devices||0} links=${c.data_links||0} clients=${c.clients||0}`, true);
      setTimeout(() => location.reload(), 1200);
    } else {
      setStatus('Sync failed: ' + ((r.errors && r.errors.join(' | ')) || r.error || r.message || 'unknown'), false);
    }
  });

  document.querySelectorAll('form[data-uisp-link]').forEach(f => {
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(f);
      const res = await fetch('/admin/uisp.php?ajax=1', { method:'POST', body:fd, credentials:'same-origin' });
      const r = await res.json().catch(() => ({ ok:false, error:'Server error' }));
      if (r.ok) location.reload();
      else      alert(r.error || r.message || 'Action failed');
    });
  });
})();
</script>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
