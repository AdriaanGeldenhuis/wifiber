<?php
/**
 * Bulk bank-CSV reconciliation UI.
 *
 * Operator uploads a downloaded statement (FNB / ABSA / Standard /
 * Capitec / Nedbank), the worker auto-detects the bank, parses each
 * credit line, and writes a payments row per matched customer.
 * Unmatched lines surface back to the operator for manual handling.
 */
$page_title = 'Bank CSV import';
$active_key = 'payments_import';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../auth/payments.php';
require_once __DIR__ . '/../bin/recon-bank-csv.php';

$result = null;
$bank_pick = $_POST['bank'] ?? 'auto';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_admin_write();
    try {
        $f = $_FILES['file'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No file uploaded.');
        }
        if ((int)$f['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('CSV is too big (max 10 MB).');
        }
        $tmp = (string)$f['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload was tampered with.');
        }
        $write = empty($_POST['dry_run']);
        $result = recon_bank_csv_process($tmp, $bank_pick, $write);
        audit_log('payments_import.run', [
            'meta' => [
                'bank' => $result['bank'], 'rows' => $result['rows_total'],
                'matched' => $result['matched'], 'on_account' => $result['on_account'],
                'duplicates' => $result['duplicates'], 'dry_run' => !$write,
            ],
        ]);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        header('Location: /admin/payments-import.php');
        exit;
    }
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="portal-head">
  <h1>Bank CSV import</h1>
  <p class="portal-sub">Upload a downloaded SA-bank statement. Each credit line that matches a customer becomes a payments row; ones tagged with a recognised invoice number are auto-allocated. Use Dry-run on the first pass to see what would happen without writing.</p>
</div>

<div class="portal-card">
  <h2>Upload</h2>
  <form method="post" enctype="multipart/form-data" class="form form-grid">
    <?= csrf_field() ?>
    <div class="field"><label>Bank</label>
      <select name="bank">
        <?php foreach (['auto'=>'Auto-detect','fnb'=>'FNB','absa'=>'ABSA','standard'=>'Standard Bank','capitec'=>'Capitec','nedbank'=>'Nedbank','generic'=>'Generic CSV'] as $k=>$lbl): ?>
          <option value="<?= $k ?>" <?= $bank_pick===$k?'selected':'' ?>><?= $h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Statement CSV</label>
      <input type="file" name="file" accept=".csv,text/csv" required>
    </div>
    <div class="field-check" style="grid-column:1/-1;">
      <input type="checkbox" id="dry_run" name="dry_run" value="1" checked>
      <label for="dry_run">Dry run (don't write payments — preview only)</label>
    </div>
    <div class="form-actions" style="grid-column:1/-1;">
      <button class="btn btn-primary" type="submit">Process</button>
    </div>
  </form>
</div>

<?php if ($result): ?>
  <div class="portal-card">
    <h2>Results <small class="muted">— bank=<?= $h($result['bank']) ?>, rows=<?= (int)$result['rows_total'] ?></small></h2>
    <p>
      <strong style="color:#0c8;"><?= (int)$result['matched'] ?></strong> matched ·
      <strong style="color:#fbbf24;"><?= (int)$result['on_account'] ?></strong> on account ·
      <strong style="color:#888;"><?= (int)$result['duplicates'] ?></strong> duplicates ·
      <strong style="color:#888;"><?= (int)$result['skipped'] ?></strong> skipped ·
      <strong style="color:#d44;"><?= (int)$result['errors'] ?></strong> errors
    </p>
    <?php if ($result['rows']): ?>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Outcome</th><th>Date</th><th style="text-align:right;">Amount</th><th>Reference</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($result['rows'] as $r):
              $colour = match ($r['outcome']) {
                  'matched'    => '#0c8',
                  'on_account' => '#fbbf24',
                  'duplicate'  => '#888',
                  'skipped'    => '#888',
                  'error'      => '#d44',
                  default      => '#d44',
              }; ?>
              <tr>
                <td><span class="status-pill" style="background:<?= $colour ?>;color:#fff;"><?= $h($r['outcome']) ?></span></td>
                <td><small><?= $h($r['date']) ?></small></td>
                <td style="text-align:right;">R <?= number_format((float)$r['amount'], 2) ?></td>
                <td><small><?= $h($r['reference']) ?></small></td>
                <td><small><?= $h($r['note']) ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../auth/portal-footer.php'; ?>
