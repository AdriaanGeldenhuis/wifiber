<?php
/**
 * Product catalogue helpers.
 *
 * The products table is the source of truth for billing — each row
 * captures a sellable monthly + once-off install combo (e.g.
 * "Home 10/5 Mbps" at R679/m, R0 24-month install, R2799 MTM install).
 *
 * The marketing /pricing page still reads data/pricing.json. The two
 * are intentionally separate so a price can be tweaked on a single
 * client's invoice without changing what the public site advertises.
 */

require_once __DIR__ . '/helpers.php';

function products_all(bool $active_only = false): array {
    $sql = "SELECT * FROM products";
    if ($active_only) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $rows = pdo()->query($sql)->fetchAll();
    foreach ($rows as &$r) $r = product_normalise($r);
    return $rows;
}

function products_find(int $id): ?array {
    if ($id <= 0) return null;
    $stmt = pdo()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? product_normalise($row) : null;
}

function product_normalise(array $r): array {
    $r['id']            = (int)$r['id'];
    $r['down_mbps']     = (float)$r['down_mbps'];
    $r['up_mbps']       = (float)$r['up_mbps'];
    $r['monthly_price'] = (float)$r['monthly_price'];
    $r['install_24mo']  = (float)$r['install_24mo'];
    $r['install_mtm']   = (float)$r['install_mtm'];
    $r['is_active']     = !empty($r['is_active']);
    $r['sort_order']    = (int)$r['sort_order'];
    return $r;
}

function product_save(array $data, ?int $id = null): int {
    $args = [
        'tier_key'      => trim((string)($data['tier_key']      ?? '')),
        'name'          => trim((string)($data['name']          ?? '')),
        'down_mbps'     => (float)($data['down_mbps']     ?? 0),
        'up_mbps'       => (float)($data['up_mbps']       ?? 0),
        'monthly_price' => (float)($data['monthly_price'] ?? 0),
        'install_24mo'  => (float)($data['install_24mo']  ?? 0),
        'install_mtm'   => (float)($data['install_mtm']   ?? 0),
        'contention'    => trim((string)($data['contention']  ?? '')),
        'description'   => trim((string)($data['description'] ?? '')),
        'is_active'     => !empty($data['is_active']) ? 1 : 0,
        'sort_order'    => (int)($data['sort_order']    ?? 0),
    ];
    if ($args['name'] === '') {
        throw new InvalidArgumentException('Product name is required.');
    }
    if ($id) {
        $stmt = pdo()->prepare(
            "UPDATE products
                SET tier_key=?, name=?, down_mbps=?, up_mbps=?, monthly_price=?,
                    install_24mo=?, install_mtm=?, contention=?, description=?,
                    is_active=?, sort_order=?
              WHERE id=?"
        );
        $stmt->execute([
            $args['tier_key'], $args['name'], $args['down_mbps'], $args['up_mbps'], $args['monthly_price'],
            $args['install_24mo'], $args['install_mtm'], $args['contention'], $args['description'] ?: null,
            $args['is_active'], $args['sort_order'], $id,
        ]);
        return $id;
    }
    $stmt = pdo()->prepare(
        "INSERT INTO products
            (tier_key, name, down_mbps, up_mbps, monthly_price,
             install_24mo, install_mtm, contention, description,
             is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $args['tier_key'], $args['name'], $args['down_mbps'], $args['up_mbps'], $args['monthly_price'],
        $args['install_24mo'], $args['install_mtm'], $args['contention'], $args['description'] ?: null,
        $args['is_active'], $args['sort_order'],
    ]);
    return (int)pdo()->lastInsertId();
}

function product_delete(int $id): bool {
    // Hard delete + null any users.product_id pointing here. We use ON DELETE
    // SET NULL semantics manually because the FK isn't enforced at the DB.
    pdo()->prepare("UPDATE users SET product_id = NULL WHERE product_id = ?")->execute([$id]);
    return pdo()->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
}

/** Compact one-line label for dropdowns: "Home 10/5 Mbps — R679/m". */
function product_dropdown_label(array $p): string {
    $price = number_format((float)$p['monthly_price'], 0, '.', ' ');
    return $p['name'] . ' — R' . $price . '/m';
}
