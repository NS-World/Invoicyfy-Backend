<?php
/** GET ?product_id= — the recipe of one product (what one unit uses) with cost and margin. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$pid = (int) ($_GET['product_id'] ?? 0);
$p = db_one('SELECT product_id, name, unit, price, gst_rate, price_includes_tax, store_id FROM products WHERE product_id = ?', [$pid]);
if (!$p) {
    fail('Product not found', 404);
}
if ($p['store_id'] !== null) {
    assert_store_access($user, $p['store_id']);
}
$lines = recipes_for([$pid])[$pid] ?? [];
$cost = recipe_cost_of($lines);
$net = (int) $p['price_includes_tax'] ? (float) $p['price'] * 100 / (100 + (float) $p['gst_rate']) : (float) $p['price'];
json_out(['status' => 'success', 'data' => cast_numbers($p, ['price', 'gst_rate'], ['product_id', 'price_includes_tax', 'store_id']) + [
    'items' => array_map(fn($l) => [
        'ingredient_id' => (int) $l['ingredient_id'], 'name' => $l['name'], 'unit' => $l['unit'], 'quantity' => (float) $l['quantity'],
        'cost_price' => (float) $l['cost_price'], 'cost' => round((float) $l['quantity'] * (float) $l['cost_price'], 2),
    ], $lines),
    'recipe_cost' => $cost,
    'price_before_tax' => round($net, 2),
    'profit' => round($net - $cost, 2),
    'margin_percent' => $net > 0 ? round(($net - $cost) / $net * 100, 1) : null,
]]);
