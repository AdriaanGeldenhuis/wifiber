<?php
/**
 * Single-site detail page — assets at this tower, photos / docs, and
 * contacts. The list view at /admin/sites.php still hosts the bulk-edit
 * grid; this page is for everything else (uploads, contacts, the device
 * roster) that's too clunky to cram into a row's <details> drawer.
 */
$page_title = 'Site';
$active_key = 'sites';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/sites.php';
require_once __DIR__ . '/../auth/devices.php';

$id   = (int)($_GET['id'] ?? 0);
$site = $id ? site_find($id) : null;
if (!$site) {
    flash('error', 'Site not found.');
    header('Location: /admin/sites.php');
    exit;
}
$self = '/admin/site-view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'site_save':
                site_save([
                    'parent_id'         => $_POST['parent_id']         ?? null,
                    'type'              => $_POST['type']              ?? 'tower',
                    'name'              => $_POST['name']              ?? '',
                    'lat'               => $_POST['lat']               ?? null,
                    'lng'               => $_POST['lng']               ?? null,
                    'height_m'          => $_POST['height_m']          ?? null,
                    'coverage_radius_m' => $_POST['coverage_radius_m'] ?? null,
                    'color'             => $_POST['color']             ?? '',
                    'notes'             => $_POST['notes']             ?? '',
                    'is_active'         => !empty($_POST['is_active']),
                ], $id);
                audit_log('site.save', ['target_type' => 'site', 'target_id' => $id]);
                flash('success', 'Site updated.');
                break;

            case 'attach_upload':
                $kind   = (string)($_POST['kind']    ?? 'photo');
                $cap    = (string)($_POST['caption'] ?? '');
                $newId  = site_attachment_save($id, $_FILES['file'] ?? null, $cap, $kind, (int)$user['id']);
                audit_log('site_attachment.create', ['target_type' => 'site_attachment', 'target_id' => $newId]);
                flash('success', 'Attachment uploaded.');
                break;

            case 'attach_delete':
                $aid = (int)($_POST['attachment_id'] ?? 0);
                if ($aid > 0) {
                    site_attachment_delete($aid);
                    audit_log('site_attachment.delete', ['target_type' => 'site_attachment', 'target_id' => $aid]);
                    flash('success', 'Attachment deleted.');
                }
                break;

            case 'contact_save':
                $cid = (int)($_POST['contact_id'] ?? 0);
                $newId = site_contact_save([
                    'site_id'    => $id,
                    'role'       => $_POST['role']       ?? 'other',
                    'name'       => $_POST['name']       ?? '',
                    'phone'      => $_POST['phone']      ?? '',
                    'email'      => $_POST['email']      ?? '',
                    'notes'      => $_POST['notes']      ?? '',
                    'is_primary' => !empty($_POST['is_primary']),
                ], $cid ?: null);
                audit_log('site_contact.save', ['target_type' => 'site_contact', 'target_id' => $newId]);
                flash('success', $cid ? 'Contact updated.' : 'Contact added.');
                break;

            case 'contact_delete':
                $cid = (int)($_POST['contact_id'] ?? 0);
                if ($cid > 0) {
                    site_contact_delete($cid);
                    audit_log('site_contact.delete', ['target_type' => 'site_contact', 'target_id' => $cid]);
                    flash('success', 'Contact deleted.');
                }
                break;
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    header('Location: ' . $self);
    exit;
}

// Reload after potential save above.
$site        = site_find($id);
$attachments = site_attachments_for($id);
$contacts    = site_contacts_for($id);
$devices     = devices_all(['site_id' => $id]);

// Group devices by role for the assets card.
$devices_by_role = [];
foreach ($devices as $d) {
    $devices_by_role[$d['role']][] = $d;
}

// Site links touching this site, joined to the other endpoint's name.
$stmt = pdo()->prepare(
    "SELECT sl.*,
            fs.name AS from_name, ts.name AS to_name
       FROM site_links sl
       JOIN sites fs ON fs.id = sl.from_site_id
       JOIN sites ts ON ts.id = sl.to_site_id
      WHERE sl.from_site_id = :id OR sl.to_site_id = :id
      ORDER BY sl.id ASC"
);
$stmt->execute([':id' => $id]);
$links = $stmt->fetchAll();

// Sectors anchored on this site (only meaningful for towers).
$stmt = pdo()->prepare(
    "SELECT s.*, d.name AS ap_name, d.status AS ap_status,
            (SELECT COUNT(*) FROM users u
              WHERE u.sector_id = s.id AND u.role = 'client') AS customer_count
       FROM sectors s
  LEFT JOIN devices d ON d.id = s.ap_device_id
      WHERE s.tower_id = ? ORDER BY s.name ASC"
);
$stmt->execute([$id]);
$sectors_here = $stmt->fetchAll();

$h = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$sites_for_parent = sites_all(false);

$role_labels = [
    'ap' => 'AP', 'cpe' => 'CPE', 'router' => 'Router', 'switch' => 'Switch',
    'backhaul' => 'Backhaul', 'ups' => 'UPS', 'other' => 'Other',
];
$contact_role_labels = [
    'landlord'  => 'Landlord',  'key_holder' => 'Key holder', 'security' => 'Security',
    'technical' => 'Technical', 'municipal'  => 'Municipal',  'other'    => 'Other',
];
$kind_labels = [
    'photo' => 'Photo', 'contract' => 'Contract', 'deed' => 'Deed',
    'diagram' => 'Diagram', 'permit' => 'Permit', 'other' => 'Other',
];
?>

<div class="portal-head">
  <h1><?= $h($site['name']) ?> <small class="muted" style="font-size:14px;">· <?= $h($site['type']) ?></small></h1>
  <p class="portal-sub">
    <a href="/admin/sites.php">← All sites</a>
    &nbsp;·&nbsp;
    <a href="/admin/map.php">Open on map</a>
    &nbsp;·&nbsp;
    <?= number_format($site['lat'], 5) ?>, <?= number_format($site['lng'], 5) ?>
    <?= $site['height_m'] !== null ? ' · ' . number_format($site['height_m'], 1) . ' m' : '' ?>
    <?= $site['coverage_radius_m'] !== null ? ' · ' . (int)$site['coverage_radius_m'] . ' m radius' : '' ?>
  </p>
</div>

<!-- ===== Tower assets ===== -->
<div class="portal-card">
  <h2>Tower assets <span class="muted">(<?= count($devices) ?>)</span>
    <a href="/admin/devices.php?site_id=<?= $id ?>" class="btn btn-ghost btn-sm" style="float:right;">Open in devices</a>
  </h2>
  <?php if (!$devices): ?>
    <div class="empty-state">
      <p>No devices placed on this site yet. Add one from <a href="/admin/devices.php">Devices</a>
         or via the map popup.</p>
    </div>
  <?php else: ?>
    <?php foreach ($role_labels as $role => $label):
            $list = $devices_by_role[$role] ?? [];
            if (!$list) continue; ?>
      <h3 style="margin-top:18px;"><?= $h($label) ?> <span class="muted">(<?= count($list) ?>)</span></h3>
      <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Vendor / model</th><th>MAC</th><th>Mgmt IP</th><th>Status</th><th>Last seen</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($list as $d):
                  $sc = ['online' => '#0c8', 'offline' => '#d44', 'unknown' => '#888', 'retired' => '#555'][$d['status']] ?? '#888'; ?>
            <tr>
              <td><strong><?= $h($d['name']) ?></strong></td>
              <td><small><?= $h($d['vendor']) ?><?= $d['model'] ? ' · ' . $h($d['model']) : '' ?></small></td>
              <td><small><code><?= $h($d['mac']) ?></code></small></td>
              <td><small><code><?= $h($d['mgmt_ip']) ?></code></small></td>
              <td><span style="display:inline-block;background:<?= $sc ?>;color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;text-transform:uppercase;"><?= $h($d['status']) ?></span></td>
              <td><small><?= $h($d['last_seen_at'] ?? '—') ?></small></td>
              <td><a href="/admin/device-view.php?id=<?= (int)$d['id'] ?>" class="btn btn-ghost btn-sm">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===== Sectors anchored on this tower ===== -->
<?php if ($site['type'] === 'tower' && $sectors_here): ?>
<div class="portal-card">
  <h2>Sectors <span class="muted">(<?= count($sectors_here) ?>)</span>
    <a href="/admin/sectors.php?search=<?= urlencode($site['name']) ?>" class="btn btn-ghost btn-sm" style="float:right;">Open in sectors</a>
  </h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Name</th><th>Band / freq</th><th>Az / BW</th><th>AP</th><th>Capacity</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($sectors_here as $s):
              $cap_pct = ($s['max_clients'] && $s['max_clients'] > 0)
                       ? min(100, round(((int)$s['customer_count'] / (int)$s['max_clients']) * 100))
                       : null; ?>
        <tr>
          <td><strong><?= $h($s['name']) ?></strong></td>
          <td><small><?= $h($s['band']) ?><?= $s['frequency_mhz'] ? ' · ' . (int)$s['frequency_mhz'] . ' MHz' : '' ?><?= $s['channel_width_mhz'] ? ' @ ' . (int)$s['channel_width_mhz'] : '' ?></small></td>
          <td><small><?= $s['azimuth_deg'] !== null ? (int)$s['azimuth_deg'] . '°' : '—' ?> / <?= $s['beamwidth_deg'] !== null ? (int)$s['beamwidth_deg'] . '°' : '—' ?></small></td>
          <td><small><?= $h($s['ap_name'] ?? '—') ?><?= $s['ap_status'] ? ' (' . $h($s['ap_status']) . ')' : '' ?></small></td>
          <td><?= (int)$s['customer_count'] ?><?= $s['max_clients'] ? ' / ' . (int)$s['max_clients'] . ' · ' . $cap_pct . '%' : '' ?></td>
          <td><a href="/admin/sector-edit.php?id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ===== Site links touching this site ===== -->
<?php if ($links): ?>
<div class="portal-card">
  <h2>Site links <span class="muted">(<?= count($links) ?>)</span></h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Type</th><th>From</th><th>To</th><th>Label</th><th>Capacity</th><th>Frequency</th></tr></thead>
    <tbody>
      <?php foreach ($links as $l): ?>
        <tr>
          <td><?= $h($l['type']) ?></td>
          <td><?= $h($l['from_name']) ?></td>
          <td><?= $h($l['to_name']) ?></td>
          <td><small><?= $h($l['label']) ?></small></td>
          <td><?= $l['capacity_mbps'] !== null ? (float)$l['capacity_mbps'] . ' Mbps' : '—' ?></td>
          <td><?= $h($l['frequency'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ===== Photos & documents ===== -->
<div class="portal-card">
  <h2>Photos &amp; documents <span class="muted">(<?= count($attachments) ?>)</span></h2>
  <?php if ($attachments): ?>
    <div class="site-attach-grid">
      <?php foreach ($attachments as $a):
              $is_image = str_starts_with((string)$a['mime'], 'image/');
              $href     = '/admin/site-attachment.php?id=' . (int)$a['id'];
              $kb       = round($a['file_size'] / 1024); ?>
        <div class="site-attach-card">
          <div class="site-attach-thumb">
            <?php if ($is_image): ?>
              <a href="<?= $href ?>" target="_blank"><img src="<?= $href ?>" alt=""></a>
            <?php else: ?>
              <a href="<?= $href ?>" target="_blank" class="site-attach-icon"><?= strtoupper(pathinfo($a['file_name'], PATHINFO_EXTENSION) ?: '·') ?></a>
            <?php endif; ?>
          </div>
          <div class="site-attach-meta">
            <strong><?= $h($kind_labels[$a['kind']] ?? $a['kind']) ?></strong>
            <?php if ($a['caption']): ?><div><?= $h($a['caption']) ?></div><?php endif; ?>
            <small class="muted"><?= $h($a['file_name']) ?> · <?= $kb ?> KB · <?= $h($a['created_at']) ?></small>
            <form method="post" class="inline-form" data-confirm="Delete this attachment?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="attach_delete">
              <input type="hidden" name="attachment_id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h3>Upload</h3>
  <form method="post" enctype="multipart/form-data" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="attach_upload">
    <div class="field"><label>Kind</label>
      <select name="kind">
        <?php foreach ($kind_labels as $k => $kl): ?>
          <option value="<?= $k ?>"><?= $h($kl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Caption (optional)</label>
      <input type="text" name="caption" maxlength="255" placeholder="e.g. North-side mast bracket"></div>
    <div class="field" style="grid-column:1/-1;"><label>File <span class="muted">(max 10 MB; jpg/png/webp/pdf/docx/xlsx)</span></label>
      <input type="file" name="file" required accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.txt"></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Upload</button>
    </div>
  </form>
</div>

<!-- ===== Contacts ===== -->
<div class="portal-card">
  <h2>Contacts <span class="muted">(<?= count($contacts) ?>)</span></h2>
  <?php if ($contacts): ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Role</th><th>Name</th><th>Phone</th><th>Email</th><th>Notes</th><th>Primary</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($contacts as $c): ?>
          <tr<?= $c['is_primary'] ? ' style="background:rgba(5,218,253,.06);"' : '' ?>>
            <td><?= $h($contact_role_labels[$c['role']] ?? $c['role']) ?></td>
            <td><strong><?= $h($c['name']) ?></strong></td>
            <td>
              <?php if ($c['phone']): ?>
                <a href="tel:<?= $h($c['phone']) ?>"><?= $h($c['phone']) ?></a>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php if ($c['email']): ?>
                <a href="mailto:<?= $h($c['email']) ?>"><?= $h($c['email']) ?></a>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td><small><?= $h($c['notes'] ?? '') ?></small></td>
            <td><?= $c['is_primary'] ? '★' : '<span class="muted">—</span>' ?></td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm">Edit</summary>
                <form method="post" class="form form-grid" style="margin-top:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="contact_save">
                  <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                  <div class="field"><label>Role</label>
                    <select name="role">
                      <?php foreach ($contact_role_labels as $rk => $rl): ?>
                        <option value="<?= $rk ?>" <?= $c['role'] === $rk ? 'selected' : '' ?>><?= $h($rl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Name</label>
                    <input type="text" name="name" required value="<?= $h($c['name']) ?>"></div>
                  <div class="field"><label>Phone</label>
                    <input type="tel" name="phone" value="<?= $h($c['phone']) ?>"></div>
                  <div class="field"><label>Email</label>
                    <input type="email" name="email" value="<?= $h($c['email']) ?>"></div>
                  <div class="field" style="grid-column:1/-1;"><label>Notes</label>
                    <textarea name="notes" rows="2"><?= $h($c['notes'] ?? '') ?></textarea></div>
                  <div class="field"><label><input type="checkbox" name="is_primary" value="1" <?= $c['is_primary'] ? 'checked' : '' ?>> Primary contact</label></div>
                  <div class="form-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </div>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete this contact?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="contact_delete">
                  <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <h3>Add contact</h3>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="contact_save">
    <div class="field"><label>Role</label>
      <select name="role">
        <?php foreach ($contact_role_labels as $rk => $rl): ?>
          <option value="<?= $rk ?>"><?= $h($rl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Name</label>
      <input type="text" name="name" required maxlength="120"></div>
    <div class="field"><label>Phone</label>
      <input type="tel" name="phone" maxlength="40" placeholder="+27 ..."></div>
    <div class="field"><label>Email</label>
      <input type="email" name="email" maxlength="120"></div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2" placeholder="Office hours, gate code, alternate phone…"></textarea></div>
    <div class="field"><label><input type="checkbox" name="is_primary" value="1"> Primary contact</label></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Add contact</button>
    </div>
  </form>
</div>

<!-- ===== Edit site (parity with sites.php so the operator never needs to bounce) ===== -->
<div class="portal-card">
  <h2>Edit site</h2>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="site_save">
    <div class="field"><label>Name</label>
      <input type="text" name="name" required maxlength="120" value="<?= $h($site['name']) ?>"></div>
    <div class="field"><label>Type</label>
      <select name="type">
        <?php foreach (SITE_TYPES as $t): ?>
          <option value="<?= $t ?>" <?= $site['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Parent</label>
      <select name="parent_id">
        <option value="">— none —</option>
        <?php foreach ($sites_for_parent as $p):
                if ((int)$p['id'] === $id) continue; ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$site['parent_id'] === (int)$p['id'] ? 'selected' : '' ?>>
            <?= $h($p['name']) ?> (<?= $h($p['type']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Latitude</label>
      <input type="number" step="0.000001" name="lat" required value="<?= $h($site['lat']) ?>"></div>
    <div class="field"><label>Longitude</label>
      <input type="number" step="0.000001" name="lng" required value="<?= $h($site['lng']) ?>"></div>
    <div class="field"><label>Height (m)</label>
      <input type="number" step="0.1" name="height_m" value="<?= $h($site['height_m']) ?>"></div>
    <div class="field"><label>Coverage radius (m)</label>
      <input type="number" name="coverage_radius_m" value="<?= $h($site['coverage_radius_m']) ?>"></div>
    <div class="field"><label>Color</label>
      <input type="text" name="color" maxlength="20" value="<?= $h($site['color']) ?>"></div>
    <div class="field"><label><input type="checkbox" name="is_active" value="1" <?= $site['is_active'] ? 'checked' : '' ?>> Active</label></div>
    <div class="field" style="grid-column:1/-1;"><label>Notes</label>
      <textarea name="notes" rows="2"><?= $h($site['notes'] ?? '') ?></textarea></div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </div>
  </form>
</div>

<style>
  .site-attach-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 18px;
  }
  .site-attach-card {
    background: var(--bg-elev);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .site-attach-thumb {
    aspect-ratio: 4 / 3;
    background: #0a0d12;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .site-attach-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .site-attach-icon {
    color: var(--accent);
    font-family: 'Space Grotesk', sans-serif;
    font-size: 32px;
    font-weight: 700;
    letter-spacing: .04em;
    text-decoration: none;
  }
  .site-attach-meta {
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
  }
  .site-attach-meta strong { font-size: 13px; }
  .site-attach-meta form { margin-top: 6px; }
</style>
