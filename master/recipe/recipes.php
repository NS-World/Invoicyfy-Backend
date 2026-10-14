<?php
/** GET ?store_id=&search= — products that have a recipe, with recipe cost, selling price and margin. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$scope = scope_store($user, $_GET['store_id'] ?? null);
$where = 'EXISTS (SELECT 1 FROM recipe_items r WHERE r.product_id = p.product_id)';
$params = [];
if ($scope !== null) {
    $where .= ' AND (p.store_id = ? OR p.store_id IS NULL)';
    $params[] = $scope;
}
$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where .= ' AND p.name LIKE ?';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}
$rows = db_all("SELECT p.product_id, p.name, p.category, p.unit, p.price, p.gst_rate, p.price_includes_tax, p.status FROM products p WHERE $where ORDER BY p.name LIMIT 500", $params);
$recipes = recipes_for(array_column($rows, 'product_id'));
$out = [];
foreach ($rows as $r) {
    $lines = $recipes[(int) $r['product_id']] ?? [];
    $cost = recipe_cost_of($lines);
    $net = (int) $r['price_includes_tax'] ? (float) $r['price'] * 100 / (100 + (float) $r['gst_rate']) : (float) $r['price'];
    $out[] = cast_numbers($r, ['price', 'gst_rate'], ['product_id', 'price_includes_tax']) + [
        'ingredients' => count($lines),
        'recipe_cost' => $cost,
        'price_before_tax' => round($net, 2),
        'profit' => round($net - $cost, 2),
        'margin_percent' => $net > 0 ? round(($net - $cost) / $net * 100, 1) : null,
    ];
}
json_out(['status' => 'success', 'total' => count($out), 'data' => $out]);
