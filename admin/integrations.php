<?php
/**
 * External integrations — webhooks (out) + API tokens (in).
 */
$page_title = 'Integrations';
$active_key = 'integrations';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/webhooks.php';
require_once __DIR__ . '/../auth/api.php';

$self = '/admin/integrations.php';
$flash_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    if ($action === 'webhook_save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            webhook_save([
                'url'        => $_POST['url']    ?? '',
                'events'     => $_POST['events'] ?? '',
                'secret'     => $_POST['secret'] ?? '',
                'is_active'  => !empty($_POST['is_active']),
                'created_by' => $user['id'],
            ], $id ?: null);
            audit_log('webhook.save', ['target_type' => 'webhook', 'target_id' => $id ?: 0]);
            flash('success', $id ? 'Webhook updated.' : 'Webhook added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'webhook_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            webhook_delete($id);
            audit_log('webhook.delete', ['target_type' => 'webhook', 'target_id' => $id]);
            flash('success', 'Webhook deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'token_create') {
        $label  = (string)($_POST['label'] ?? '');
        // Allow read, diag, and any of the Phase 30 write scopes.
        $allowed_scopes = array_merge(['read', 'diag'], array_keys(API_WRITE_SCOPES));
        $scopes  = array_values(array_intersect((array)($_POST['scopes'] ?? []), $allowed_scopes));
        $expires = trim((string)($_POST['expires_at'] ?? ''));
        $r = api_token_create((int)$user['id'], $label, $scopes,
            $expires !== '' ? str_replace('T', ' ', $expires) . ':00' : null);
        audit_log('api_token.create', ['target_type' => 'api_token', 'target_id' => $r['id'], 'meta' => ['scopes' => $scopes]]);
        $flash_token = $r['token']; // shown ONCE below
        flash('success', 'Token created. Copy it now — we never show it again.');
    }

    if ($action === 'token_revoke') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            api_token_revoke($id);
            audit_log('api_token.revoke', ['target_type' => 'api_token', 'target_id' => $id]);
            flash('success', 'Token revoked.');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'inbound_save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            inbound_webhook_save([
                'name'             => $_POST['name']             ?? '',
                'description'      => $_POST['description']      ?? '',
                'secret'           => $_POST['secret']           ?? '',
                'algo'             => $_POST['algo']             ?? 'sha256',
                'signature_header' => $_POST['signature_header'] ?? 'X-Hub-Signature-256',
                'signature_prefix' => $_POST['signature_prefix'] ?? 'sha256=',
                'is_active'        => !empty($_POST['is_active']),
                'created_by'       => $user['id'],
            ], $id ?: null);
            audit_log('inbound_webhook.save', ['target_type' => 'inbound_webhook', 'target_id' => $id ?: 0]);
            flash('success', $id ? 'Inbound source updated.' : 'Inbound source added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'inbound_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            inbound_webhook_delete($id);
            audit_log('inbound_webhook.delete', ['target_type' => 'inbound_webhook', 'target_id' => $id]);
            flash('success', 'Inbound source deleted.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$hooks      = webhooks_all();
$inbound    = inbound_webhooks_all();
$inbound_recent = inbound_deliveries_recent(20);
$tokens     = api_tokens_for_user((int)$user['id']);
$base_url   = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'wifiber.co.za');
$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Integrations</h1>
  <p class="portal-sub">Webhooks fire to external URLs when events happen (Slack, PagerDuty, your own app). API tokens authenticate read-only or diagnostic-issuing clients to <code>/api/v1/*</code>.</p>
</div>

<?php if ($flash_token): ?>
  <div class="portal-card" style="border-color:var(--accent);">
    <h2 style="color:var(--accent);">Save this token now</h2>
    <p>This is the only time it will ever be shown. If you lose it, revoke and re-issue.</p>
    <pre style="user-select:all;background:#111;color:#0f0;padding:10px;border-radius:6px;"><?= $h($flash_token) ?></pre>
  </div>
<?php endif; ?>

<div class="portal-card">
  <h2>Webhooks <span class="muted">(<?= count($hooks) ?>)</span></h2>
  <?php if ($hooks): ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>URL</th><th>Events</th><th>Active</th><th>Last fired</th><th>Status</th><th>Fail count</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($hooks as $w): ?>
          <tr>
            <td><small><code><?= $h($w['url']) ?></code></small></td>
            <td><small><?= $h($w['events_json']) ?></small></td>
            <td><?= $w['is_active'] ? '✓' : '<small class="muted">no</small>' ?></td>
            <td><small><?= $h($w['last_fired_at'] ?? '—') ?></small></td>
            <td><?= $w['last_status'] ? (int)$w['last_status'] : '—' ?></td>
            <td><?= (int)$w['fail_count'] ?></td>
            <td>
              <form method="post" class="inline-form" data-confirm="Delete this webhook?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="webhook_delete">
                <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-danger btn-sm">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <h3>Add webhook</h3>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="webhook_save">
    <div class="field" style="grid-column:1/-1;"><label>URL</label>
      <input type="url" name="url" required placeholder="https://hooks.slack.com/services/..."></div>
    <div class="field"><label>Events <span class="muted">(comma-separated)</span></label>
      <input type="text" name="events" required value="outage.*, wireless.config_applied"></div>
    <div class="field"><label>Secret <span class="muted">(blank = autogenerate)</span></label>
      <input type="text" name="secret" maxlength="80"></div>
    <div class="field"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary btn-sm">Add webhook</button>
    </div>
  </form>
  <p class="muted"><small>Each delivery POSTs the event JSON with an <code>X-Wifiber-Signature: sha256=...</code> HMAC header so subscribers can verify authenticity.</small></p>
</div>

<div class="portal-card">
  <h2>API tokens <span class="muted">(<?= count($tokens) ?>)</span></h2>
  <?php if ($tokens): ?>
    <table class="data-table">
      <thead><tr><th>Label</th><th>Scopes</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tokens as $t): ?>
          <tr>
            <td><strong><?= $h($t['label']) ?></strong></td>
            <td><small><?= $h($t['scopes_json']) ?></small></td>
            <td><small><?= $h($t['created_at']) ?></small></td>
            <td><small><?= $h($t['last_used_at'] ?? 'never') ?></small></td>
            <td><small><?= $h($t['expires_at'] ?? '—') ?></small></td>
            <td>
              <form method="post" class="inline-form" data-confirm="Revoke this token? Any client using it stops working immediately.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="token_revoke">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-danger btn-sm">Revoke</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <h3>Issue a new token</h3>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="token_create">
    <div class="field" style="grid-column:1/-1;"><label>Label</label>
      <input type="text" name="label" required maxlength="80" placeholder="grafana, splynx-bridge…"></div>
    <div class="field" style="grid-column:1/-1;"><label>Read scopes</label>
      <label><input type="checkbox" name="scopes[]" value="read" checked> <code>read</code> — every GET endpoint</label><br>
      <label><input type="checkbox" name="scopes[]" value="diag"> <code>diag</code> — POST /diagnostics</label>
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Write scopes</label>
      <?php foreach (API_WRITE_SCOPES as $scope => $label): ?>
        <label style="display:block;"><input type="checkbox" name="scopes[]" value="<?= $h($scope) ?>"> <code><?= $h($scope) ?></code> — <?= $h($label) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="field"><label>Expires <span class="muted">(optional)</span></label>
      <input type="datetime-local" name="expires_at"></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary btn-sm">Create token</button>
    </div>
  </form>
  <p class="muted"><small>Per-token rate limit: <strong><?= API_RATE_LIMIT_PER_MIN ?> requests/minute</strong>. The full machine-readable spec is at <a href="/api/v1/openapi.yaml"><code>/api/v1/openapi.yaml</code></a>.</small></p>
</div>

<div class="portal-card">
  <h2>Inbound webhooks <span class="muted">(<?= count($inbound) ?>)</span></h2>
  <p class="muted" style="margin-top:-4px;">External systems POST signed payloads to <code><?= $h($base_url) ?>/api/v1/webhooks/in.php?source=NAME</code>. Verified deliveries fire internally as <code>inbound.&lt;name&gt;.&lt;event&gt;</code> so subscribed outbound webhooks can chain on them.</p>
  <?php if ($inbound): ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Description</th><th>Algo</th><th>Header</th><th>Active</th><th style="text-align:right;">Deliveries</th><th>Last received</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($inbound as $w): ?>
          <tr>
            <td><strong><code><?= $h($w['name']) ?></code></strong></td>
            <td><small><?= $h($w['description']) ?></small></td>
            <td><small><?= $h($w['algo']) ?></small></td>
            <td><small><code><?= $h($w['signature_header']) ?></code></small></td>
            <td><?= $w['is_active'] ? '✓' : '<small class="muted">no</small>' ?></td>
            <td style="text-align:right;"><?= (int)$w['delivery_count'] ?></td>
            <td><small><?= $h($w['last_received_at'] ?? 'never') ?></small></td>
            <td>
              <details style="display:inline-block;">
                <summary class="btn btn-ghost btn-sm">Secret</summary>
                <pre style="user-select:all;background:#111;color:#0f0;padding:6px;border-radius:4px;font-size:11px;"><?= $h($w['secret']) ?></pre>
              </details>
              <form method="post" class="inline-form" data-confirm="Delete inbound source &quot;<?= $h($w['name']) ?>&quot;?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="inbound_delete">
                <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-danger btn-sm">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <h3>Add inbound source</h3>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="inbound_save">
    <div class="field"><label>Name <span class="muted">(URL-safe)</span></label>
      <input type="text" name="name" required maxlength="40" placeholder="splynx" pattern="[a-z0-9_-]{2,40}"></div>
    <div class="field"><label>Algo</label>
      <select name="algo">
        <option value="sha256" selected>HMAC-SHA256</option>
        <option value="sha1">HMAC-SHA1</option>
        <option value="md5">HMAC-MD5</option>
      </select>
    </div>
    <div class="field"><label>Signature header</label>
      <input type="text" name="signature_header" value="X-Hub-Signature-256" maxlength="60"></div>
    <div class="field"><label>Signature prefix</label>
      <input type="text" name="signature_prefix" value="sha256=" maxlength="20"></div>
    <div class="field"><label>Secret <span class="muted">(blank = autogenerate)</span></label>
      <input type="text" name="secret" maxlength="200"></div>
    <div class="field"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
    <div class="field" style="grid-column:1/-1;"><label>Description</label>
      <input type="text" name="description" maxlength="200" placeholder="Splynx events bridge"></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary btn-sm">Add source</button>
    </div>
  </form>
  <?php if ($inbound_recent): ?>
    <h3 style="margin-top:24px;">Recent deliveries</h3>
    <table class="data-table">
      <thead><tr><th>When</th><th>Source</th><th>Event</th><th>Status</th><th>Reason</th><th>Remote</th></tr></thead>
      <tbody>
        <?php foreach ($inbound_recent as $d): ?>
          <tr>
            <td><small><?= $h($d['received_at']) ?></small></td>
            <td><small><code><?= $h($d['source_name']) ?></code></small></td>
            <td><small><?= $h($d['event']) ?></small></td>
            <td><span class="status-pill status-<?= $h($d['status']) ?>"><?= $h($d['status']) ?></span></td>
            <td><small><?= $h($d['reason']) ?></small></td>
            <td><small><?= $h($d['remote_ip']) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
