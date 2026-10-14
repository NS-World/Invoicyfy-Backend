<?php
/**
 * Switch "Track stock" on or off from the Inventory screen.
 * POST { product_ids: [..] | all_untracked: true, track: true|false, opening_stock?: number (one product only) }
 * Turning it on with an opening stock records an "opening" stock entry, like a new product.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$track = bool_field($d, 'track', true);
$scope = scope_store($user, $d['store_id'] ?? null);
if (bool_field($d, 'all_untracked')) {
    if (!$track) {
        fail('all_untracked only works with track = true');
    }
    $ids = array_map('intval', array_column(db_all(
        "SELECT product_id FROM products WHERE track_inventory = 0 AND status <> 'Inactive'" . ($scope !== null ? ' AND (store_id = ? OR store_id IS NULL)' : ''),
        $scope !== null ? [$scope] : []
    ), 'product_id'));
} else {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($d['product_ids'] ?? [])))));
}
if (!$ids) {
    json_out(['status' => 'success', 'message' => 'Nothing to change', 'changed' => 0]);
}
if (count($ids) > 5000) {
    fail('Too many products at once');
}
$opening = num_field($d, 'opening_stock');
if ($opening !== null && ($opening < 0 || count($ids) !== 1 || !$track)) {
    fail('Opening stock can be given for one product while switching tracking on');
}

$pdo->beginTransaction();
$marks = implode(',', array_fill(0, count($ids), '?'));
$rows = db_all("SELECT product_id, name, store_id, track_inventory, stock_qty FROM products WHERE product_id IN ($marks) FOR UPDATE", $ids);
if (count($rows) !== count($ids)) {
    fail('Product not found', 404);
}
foreach ($rows as $r) {
    if ($r['store_id'] !== null) {
        assert_store_access($user, $r['store_id']);
    }
}
db_query("UPDATE products SET track_inventory = ? WHERE product_id IN ($marks)", array_merge([$track ? 1 : 0], $ids));
if ($opening !== null && $opening > 0) {
    $diff = round($opening - (float) $rows[0]['stock_qty'], 3);
    move_stock((int) $rows[0]['product_id'], $diff, 'opening', 'manual', null, 'Opening stock', (int) $user['user_id']);
}
$pdo->commit();

$changed = count(array_filter($rows, fn($r) => (int) $r['track_inventory'] !== ($track ? 1 : 0)));
$what = count($rows) === 1 ? $rows[0]['name'] : count($rows) . ' products';
log_activity($track ? 'stock.track_on' : 'stock.track_off', 'product', count($rows) === 1 ? $rows[0]['product_id'] : null,
    ($track ? 'Stock tracking on: ' : 'Stock tracking off: ') . $what . ($opening ? ' (opening ' . qty_text($opening) . ')' : ''), [], $rows[0]['store_id']);
json_out(['status' => 'success', 'message' => ($track ? 'Stock tracking started for ' : 'Stock tracking stopped for ') . $what, 'changed' => $changed]);
