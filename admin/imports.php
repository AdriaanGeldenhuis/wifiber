<?php
/**
 * Imports — UISP / Splynx / FreeRADIUS migration cockpit.
 *
 *   • History tab: every run logged via importer_run_begin/end.
 *   • Run tab: kick a CLI importer in the background. Long imports
 *     can take minutes; the worker writes to import_runs so the
 *     history view shows progress / outcome on next refresh.
 *
 * Settings (base URLs, API keys) are persisted in data/site.json under
 * the 'integrations' object so we don't ask the operator to retype
 * them on every run.
 */
$page_title = 'Imports';
$active_key = 'imports';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/importers.php';

$self = '/admin/imports.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_settings') {
            $site = load_site_settings();
            $site['integrations'] = [
                'uisp' => [
                    'base_url' => trim((string)($_POST['uisp_base_url'] ?? '')),
                    'token'    => trim((string)($_POST['uisp_token']    ?? '')),
                ],
                'splynx' => [
                    'base_url' => trim((string)($_POST['splynx_base_url'] ?? '')),
                    'user'     => trim((string)($_POST['splynx_user']     ?? '')),
                    'pass'     => trim((string)($_POST['splynx_pass']     ?? '')),
                ],
            ];
            file_put_contents(DATA_DIR . '/site.json', json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            audit_log('imports.settings_saved', ['meta' => ['has_uisp' => !empty($site['integrations']['uisp']['token']), 'has_splynx' => !empty($site['integrations']['splynx']['user'])]]);
            flash('success', 'Integration settings saved.');
        }

        if ($action === 'run') {
            $source   = $_POST['source']  ?? '';
            $only     = trim((string)($_POST['only']  ?? ''));
            $dry_run  = !empty($_POST['dry_run']);
            $limit    = (int)($_POST['limit'] ?? 0);
            if (!in_array($source, ['uisp','splynx','radius'], true)) {
                throw new InvalidArgumentException('Pick a valid source.');
            }

            $args = [];
            if ($dry_run)        $args[] = '--dry-run';
            if ($limit > 0)      $args[] = '--limit=' . $limit;
            if ($only !== '')    $args[] = '--only='  . preg_replace('/[^\w,]/', '', $only);

            $integrations = (load_site_settings()['integrations'] ?? []);

            if ($source === 'uisp') {
                $cfg = $integrations['uisp'] ?? [];
                if (empty($cfg['base_url']) || empty($cfg['token'])) {
                    throw new RuntimeException('Save UISP base URL + token before running.');
                }
                $args[] = '--base-url=' . $cfg['base_url'];
                $args[] = '--token='    . $cfg['token'];
            } elseif ($source === 'splynx') {
                $cfg = $integrations['splynx'] ?? [];
                if (empty($cfg['base_url']) || empty($cfg['user']) || empty($cfg['pass'])) {
                    throw new RuntimeException('Save Splynx base URL + API key/secret before running.');
                }
                $args[] = '--base-url=' . $cfg['base_url'];
                $args[] = '--user='     . $cfg['user'];
                $args[] = '--pass='     . $cfg['pass'];
            } elseif ($source === 'radius') {
                // No URL needed; reads from data/db.php → 'import_radius'.
            }

            $script = __DIR__ . '/../bin/import-' . $source . '.php';
            if (!is_file($script)) throw new RuntimeException("Importer script missing: $script");

            // Fire-and-forget: log the start of the run, spawn the
            // worker, return to the page. The worker writes its own
            // import_runs rows.
            audit_log('imports.start', ['meta' => ['source' => $source, 'args' => $args]]);
            $cmd = 'nohup php ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args))
                 . ' >> ' . escapeshellarg(DATA_DIR . '/import-' . $source . '.log') . ' 2>&1 &';
            @exec($cmd);
            flash('success', 'Import started in the background. Reload to see progress.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    header('Location: ' . $self);
    exit;
}

$runs    = importer_runs_recent(100);
$site    = load_site_settings();
$cfg     = $site['integrations'] ?? [];
$uisp    = $cfg['uisp']   ?? [];
$splynx  = $cfg['splynx'] ?? [];

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Imports</h1>
  <p class="portal-sub">UISP, Splynx, and FreeRADIUS migration. Each run is logged below; failures don't roll back partial successes (idempotent re-runs heal in place).</p>
</div>

<div class="portal-card">
  <h2>Settings</h2>
  <p class="muted">Stored in <code>data/site.json</code> → <code>integrations</code>. Tokens are kept in plain text for now — restrict shell access on the host.</p>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <h3 style="grid-column:1/-1;margin:12px 0 4px;">UISP</h3>
    <div class="field"><label>Base URL</label>
      <input type="url" name="uisp_base_url" value="<?= $h($uisp['base_url'] ?? '') ?>" placeholder="https://uisp.example.com">
    </div>
    <div class="field"><label>X-Auth-Token</label>
      <input type="text" name="uisp_token" value="<?= $h($uisp['token'] ?? '') ?>">
    </div>
    <h3 style="grid-column:1/-1;margin:12px 0 4px;">Splynx</h3>
    <div class="field"><label>Base URL</label>
      <input type="url" name="splynx_base_url" value="<?= $h($splynx['base_url'] ?? '') ?>" placeholder="https://splynx.example.com">
    </div>
    <div class="field"><label>API key</label>
      <input type="text" name="splynx_user" value="<?= $h($splynx['user'] ?? '') ?>">
    </div>
    <div class="field"><label>API secret</label>
      <input type="text" name="splynx_pass" value="<?= $h($splynx['pass'] ?? '') ?>">
    </div>
    <h3 style="grid-column:1/-1;margin:12px 0 4px;">FreeRADIUS source DB</h3>
    <p class="muted" style="grid-column:1/-1;margin:0;">Configured in <code>data/db.php</code> under the <code>'import_radius'</code> key (host / db / user / pass / port). No web form — credentials shouldn't ride through the browser.</p>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary btn-sm" type="submit">Save settings</button>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Run an import</h2>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="run">
    <div class="field"><label>Source</label>
      <select name="source">
        <option value="uisp">UISP — sites, devices, links</option>
        <option value="splynx">Splynx — customers, tariffs, services, invoices, payments</option>
        <option value="radius">FreeRADIUS — radcheck, radreply, radusergroup, radacct</option>
      </select>
    </div>
    <div class="field"><label>Only (comma-separated, optional)</label>
      <input type="text" name="only" placeholder="sites,devices  /  tariffs,customers  /  radcheck,radreply">
    </div>
    <div class="field"><label>Limit (per resource)</label>
      <input type="number" name="limit" min="0" placeholder="0 = all">
    </div>
    <div class="field-check" style="grid-column:1/-1;">
      <input type="checkbox" id="dry_run" name="dry_run" value="1" checked>
      <label for="dry_run">Dry run (no writes)</label>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary" type="submit">Start import</button>
    </div>
  </form>
</div>

<div class="portal-card">
  <h2>Run history <span class="muted" style="font-weight:400;font-size:.85em;">(<?= count($runs) ?>)</span></h2>
  <?php if (!$runs): ?>
    <p class="muted">No imports yet.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Started</th><th>Source</th><th>Resource</th>
          <th style="text-align:right;">Total</th>
          <th style="text-align:right;">Created</th>
          <th style="text-align:right;">Updated</th>
          <th style="text-align:right;">Skipped</th>
          <th style="text-align:right;">Failed</th>
          <th>Mode</th><th>Done</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($runs as $r):
          $finished = $r['finished_at'] ?: '— still running —';
          $colour   = ((int)$r['rows_failed']) > 0 ? '#d44' : '#0c8';
        ?>
          <tr>
            <td><small><?= $h($r['started_at']) ?></small></td>
            <td><strong><?= $h($r['source']) ?></strong></td>
            <td><?= $h($r['resource']) ?></td>
            <td style="text-align:right;"><?= (int)$r['rows_total'] ?></td>
            <td style="text-align:right;color:#0c8;"><?= (int)$r['rows_created'] ?></td>
            <td style="text-align:right;color:#08e;"><?= (int)$r['rows_updated'] ?></td>
            <td style="text-align:right;"><?= (int)$r['rows_skipped'] ?></td>
            <td style="text-align:right;color:<?= $colour ?>;"><?= (int)$r['rows_failed'] ?></td>
            <td><small><?= $r['dry_run'] ? 'dry-run' : 'live' ?></small></td>
            <td><small><?= $h($finished) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
