<?php
/**
 * Product catalogue editor — list, create, edit, soft-disable.
 *
 * Drives the dropdowns on /admin/client-edit.php and the line-item
 * picker on /admin/invoice-edit.php. Public /pricing page still reads
 * data/pricing.json — those are managed in /admin/pricing.php.
 */
$page_title = 'Products';
$active_key = 'products';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/products.php';

$self = '/admin/products.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $saved = product_save([
                'tier_key'      => $_POST['tier_key']      ?? '',
                'name'          => $_POST['name']          ?? '',
                'down_mbps'     => $_POST['down_mbps']     ?? 0,
                'up_mbps'       => $_POST['up_mbps']       ?? 0,
                'monthly_price' => $_POST['monthly_price'] ?? 0,
                'install_24mo'  => $_POST['install_24mo']  ?? 0,
                'install_mtm'   => $_POST['install_mtm']   ?? 0,
                'contention'    => $_POST['contention']    ?? '',
                'description'   => $_POST['description']   ?? '',
                'is_active'     => !empty($_POST['is_active']),
                'sort_order'    => $_POST['sort_order']    ?? 0,
            ], $id ?: null);
            audit_log('product.save', ['target_type' => 'product', 'target_id' => $saved]);
            flash('success', $id ? 'Product updated.' : 'Product created.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            product_delete($id);
            audit_log('product.delete', ['target_type' => 'product', 'target_id' => $id]);
            flash('success', 'Product deleted.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$products  = products_all(false);
$tiers     = ['home' => 'Home', 'business' => 'Business', 'gaming' => 'Gaming', 'other' => 'Other'];
?>

<div class="portal-head">
  <h1>Products</h1>
  <p class="portal-sub">The billable catalogue. Used to set a client's package and pre-fill invoice lines. Edits here don't change the public <a href="/admin/pricing.php">pricing page</a>.</p>
</div>

<div class="portal-card">
  <h2>Catalogue</h2>
  <?php if (!$products): ?>
    <p class="muted">No products yet — run the Phase 2 migration to seed from <code>pricing.json</code>, or add one below.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th><th>Tier</th><th style="text-align:right;">Mbps</th>
          <th style="text-align:right;">Monthly</th><th style="text-align:right;">Install (24mo / MTM)</th>
          <th>Active</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr<?= $p['is_active'] ? '' : ' style="opacity:.5;"' ?>>
            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
            <td><?= htmlspecialchars($p['tier_key'] ?: '—') ?></td>
            <td style="text-align:right;"><?= rtrim(rtrim(number_format($p['down_mbps'],2), '0'),'.') ?>/<?= rtrim(rtrim(number_format($p['up_mbps'],2), '0'),'.') ?></td>
            <td style="text-align:right;">R<?= number_format($p['monthly_price'], 2) ?></td>
            <td style="text-align:right;">R<?= number_format($p['install_24mo'], 2) ?> / R<?= number_format($p['install_mtm'], 2) ?></td>
            <td><?= $p['is_active'] ? 'yes' : 'no' ?></td>
            <td>
              <details style="display:inline-block;">
                <summary class="btn btn-ghost btn-sm">Edit</summary>
                <form method="post" class="form form-grid" style="margin-top:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <div class="field"><label>Name</label>
                    <input type="text" name="name" required maxlength="120" value="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Tier</label>
                    <select name="tier_key">
                      <?php foreach ($tiers as $k=>$lbl): ?>
                        <option value="<?= $k ?>" <?= $p['tier_key']===$k?'selected':'' ?>><?= $lbl ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Down Mbps</label>
                    <input type="number" step="0.01" name="down_mbps" value="<?= $p['down_mbps'] ?>">
                  </div>
                  <div class="field"><label>Up Mbps</label>
                    <input type="number" step="0.01" name="up_mbps" value="<?= $p['up_mbps'] ?>">
                  </div>
                  <div class="field"><label>Monthly (R)</label>
                    <input type="number" step="0.01" name="monthly_price" value="<?= $p['monthly_price'] ?>">
                  </div>
                  <div class="field"><label>Install 24-mo (R)</label>
                    <input type="number" step="0.01" name="install_24mo" value="<?= $p['install_24mo'] ?>">
                  </div>
                  <div class="field"><label>Install MTM (R)</label>
                    <input type="number" step="0.01" name="install_mtm" value="<?= $p['install_mtm'] ?>">
                  </div>
                  <div class="field"><label>Contention</label>
                    <input type="text" name="contention" maxlength="20" value="<?= htmlspecialchars($p['contention'], ENT_QUOTES) ?>">
                  </div>
                  <div class="field"><label>Sort order</label>
                    <input type="number" name="sort_order" value="<?= $p['sort_order'] ?>">
                  </div>
                  <div class="field" style="grid-column:1/-1;"><label>Description</label>
                    <textarea name="description" rows="2"><?= htmlspecialchars((string)($p['description'] ?? '')) ?></textarea>
                  </div>
                  <div class="field-check" style="grid-column:1/-1;">
                    <input type="checkbox" id="active_<?= (int)$p['id'] ?>" name="is_active" value="1" <?= $p['is_active']?'checked':'' ?>>
                    <label for="active_<?= (int)$p['id'] ?>">Active (show in dropdowns)</label>
                  </div>
                  <div class="form-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </div>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>? Clients on this product will be unlinked.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="portal-card">
  <h2>Add product</h2>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Name</label>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. Home 10/5 Mbps">
    </div>
    <div class="field"><label>Tier</label>
      <select name="tier_key">
        <?php foreach ($tiers as $k=>$lbl): ?>
          <option value="<?= $k ?>"><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Down Mbps</label>
      <input type="number" step="0.01" name="down_mbps" required>
    </div>
    <div class="field"><label>Up Mbps</label>
      <input type="number" step="0.01" name="up_mbps" required>
    </div>
    <div class="field"><label>Monthly (R)</label>
      <input type="number" step="0.01" name="monthly_price" required>
    </div>
    <div class="field"><label>Install 24-mo (R)</label>
      <input type="number" step="0.01" name="install_24mo" value="0">
    </div>
    <div class="field"><label>Install MTM (R)</label>
      <input type="number" step="0.01" name="install_mtm" value="2799">
    </div>
    <div class="field"><label>Contention</label>
      <input type="text" name="contention" maxlength="20" placeholder="5:1">
    </div>
    <div class="field"><label>Sort order</label>
      <input type="number" name="sort_order" value="500">
    </div>
    <div class="field" style="grid-column:1/-1;"><label>Description</label>
      <textarea name="description" rows="2"></textarea>
    </div>
    <div class="field-check" style="grid-column:1/-1;">
      <input type="checkbox" id="new_active" name="is_active" value="1" checked>
      <label for="new_active">Active</label>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-primary">Add product</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
