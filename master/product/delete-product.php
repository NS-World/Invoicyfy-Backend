<?php
/**
 * Deletes a product and everything linked to it (stock history, batches, recipes; offers with it are switched off).
 * Bills, orders and supplier bills keep the item name and amounts. POST {product_id, preview: true} only lists
 * what would be removed (the app shows it in red before asking to confirm).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cascade.php';
allow_methods('PUT', 'POST', 'DELETE');
$user = require_auth();

$d = input();
$id = int_field($d, 'product_id');
$product = $id ? db_one("SELECT * FROM products WHERE product_id = ?", [$id]) : null;
if (!$product) {
    fail('Product not found', 404);
}
if ($product['store_id'] !== null) {
    assert_store_access($user, $product['store_id']);
}
if (bool_field($d, 'preview')) {
    json_out(['status' => 'success', 'name' => $product['name'], 'related' => product_delete_plan($id)]);
}
$pdo->beginTransaction();
$plan = product_delete_run($id);
$pdo->commit();
log_activity('product.delete', 'product', $id, "Deleted product {$product['name']}" . cascade_summary($plan), [], $product['store_id']);
json_out(["status" => "success", "message" => "Product \"{$product['name']}\" and its related records were deleted", 'related' => $plan]);
