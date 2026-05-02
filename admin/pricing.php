<?php
$page_title = 'Pricing';
$active_key = 'pricing';
require __DIR__ . '/_layout.php';

$file = __DIR__ . '/../data/pricing.json';
$data = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $new = [
        'install_24mo_label' => trim($_POST['install_24mo_label'] ?? ''),
        'install_24mo_value' => trim($_POST['install_24mo_value'] ?? ''),
        'install_mtm_label'  => trim($_POST['install_mtm_label']  ?? ''),
        'install_mtm_value'  => trim($_POST['install_mtm_value']  ?? ''),
        'tiers' => [],
    ];

    foreach ($_POST['tier'] ?? [] as $key => $tier) {
        $key = preg_replace('/[^a-z0-9_-]/i', '', (string)$key);
        if ($key === '') continue;
        $plans = [];
        foreach (($tier['plans'] ?? []) as $p) {
            if (trim((string)($p['price'] ?? '')) === '') continue;
            $plans[] = [
                'down'     => is_numeric($p['down'] ?? null) ? 0 + $p['down'] : 0,
                'up'       => is_numeric($p['up']   ?? null) ? 0 + $p['up']   : 0,
                'price'    => is_numeric($p['price']) ? 0 + $p['price'] : 0,
                'featured' => !empty($p['featured']),
            ];
        }
        $new['tiers'][$key] = [
            'name'       => trim($tier['name']       ?? ''),
            'contention' => trim($tier['contention'] ?? ''),
            'tagline'    => trim($tier['tagline']    ?? ''),
            'plans'      => $plans,
        ];
    }

    if (json_save($file, $new)) {
        flash('success', 'Pricing saved.');
    } else {
        flash('error', 'Could not write data/pricing.json. Check permissions.');
    }
    header('Location: /admin/pricing.php');
    exit;
}

$tiers = $data['tiers'] ?? [];
?>

<div class="portal-head">
  <h1>Pricing</h1>
  <p class="portal-sub">Edit the price tables shown on /pricing. Set the price to blank to remove a row. Tick "Featured" to highlight a plan as "Most popular".</p>
</div>

<form method="post" class="form">
  <?= csrf_field() ?>

  <div class="portal-card">
    <h2>Installation fees</h2>
    <div class="form form-grid">
      <div class="field"><label>24-month label</label>
        <input type="text" name="install_24mo_label" value="<?= htmlspecialchars($data['install_24mo_label'] ?? '', ENT_QUOTES) ?>">
      </div>
      <div class="field"><label>24-month text</label>
        <input type="text" name="install_24mo_value" value="<?= htmlspecialchars($data['install_24mo_value'] ?? '', ENT_QUOTES) ?>">
      </div>
      <div class="field"><label>Month-to-month label</label>
        <input type="text" name="install_mtm_label"  value="<?= htmlspecialchars($data['install_mtm_label']  ?? '', ENT_QUOTES) ?>">
      </div>
      <div class="field"><label>Month-to-month text</label>
        <input type="text" name="install_mtm_value"  value="<?= htmlspecialchars($data['install_mtm_value']  ?? '', ENT_QUOTES) ?>">
      </div>
    </div>
  </div>

  <?php foreach ($tiers as $key => $tier): ?>
    <div class="portal-card">
      <h2>Tier: <?= htmlspecialchars($tier['name'] ?? $key) ?></h2>
      <div class="form form-grid">
        <div class="field"><label>Display name</label>
          <input type="text" name="tier[<?= htmlspecialchars($key) ?>][name]" value="<?= htmlspecialchars($tier['name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field"><label>Contention</label>
          <input type="text" name="tier[<?= htmlspecialchars($key) ?>][contention]" value="<?= htmlspecialchars($tier['contention'] ?? '', ENT_QUOTES) ?>" placeholder="5:1">
        </div>
        <div class="field" style="grid-column:1/-1;"><label>Tagline</label>
          <input type="text" name="tier[<?= htmlspecialchars($key) ?>][tagline]" value="<?= htmlspecialchars($tier['tagline'] ?? '', ENT_QUOTES) ?>">
        </div>
      </div>

      <h3 style="margin-top:20px;color:var(--text);font-size:1rem;">Plans</h3>
      <table class="data-table">
        <thead>
          <tr><th>Down (Mbps)</th><th>Up (Mbps)</th><th>Price (R/m)</th><th>Featured</th></tr>
        </thead>
        <tbody>
          <?php
          $plans = $tier['plans'] ?? [];
          // Always render 8 rows (pad with blanks so users can add new ones)
          $rows = max(8, count($plans));
          for ($i = 0; $i < $rows; $i++):
              $p = $plans[$i] ?? ['down' => '', 'up' => '', 'price' => '', 'featured' => false];
          ?>
            <tr>
              <td><input type="number" step="0.5" name="tier[<?= htmlspecialchars($key) ?>][plans][<?= $i ?>][down]"  value="<?= htmlspecialchars((string)($p['down'] ?? '')) ?>" style="width:100px;"></td>
              <td><input type="number" step="0.5" name="tier[<?= htmlspecialchars($key) ?>][plans][<?= $i ?>][up]"    value="<?= htmlspecialchars((string)($p['up']   ?? '')) ?>" style="width:100px;"></td>
              <td><input type="number" step="1"   name="tier[<?= htmlspecialchars($key) ?>][plans][<?= $i ?>][price]" value="<?= htmlspecialchars((string)($p['price'] ?? '')) ?>" style="width:120px;"></td>
              <td><input type="checkbox" name="tier[<?= htmlspecialchars($key) ?>][plans][<?= $i ?>][featured]" value="1" <?= !empty($p['featured']) ? 'checked' : '' ?>></td>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>

  <button type="submit" class="btn btn-primary">Save pricing</button>
</form>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
