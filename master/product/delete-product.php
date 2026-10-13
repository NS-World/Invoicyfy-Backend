<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'DELETE');
$user = require_auth();

$id = int_field(input(), 'product_id');
$product = $id ? db_one("SELECT * FROM products WHERE product_id = ?", [$id]) : null;
if (!$product) {
    fail('Product not found', 404);
}
if ($product['store_id'] !== null) {
    assert_store_access($user, $product['store_id']);
}
$used = db_value("SELECT 1 FROM invoice_items WHERE product_id = ? LIMIT 1", [$id])
    || db_value("SELECT 1 FROM order_items WHERE product_id = ? LIMIT 1", [$id]);
if ($used) {
    // Keep history intact: deactivate instead of deleting.
    db_query("UPDATE products SET status = 'Inactive' WHERE product_id = ?", [$id]);
    log_activity('product.deactivate', 'product', $id, "Product {$product['name']} marked Inactive", [], $product['store_id']);
    json_out(["status" => "success", "message" => "Product is used in bills, so it was marked Inactive instead of deleted"]);
}
db_query("DELETE FROM products WHERE product_id = ?", [$id]);
log_activity('product.delete', 'product', $id, "Deleted product {$product['name']}", [], $product['store_id']);
json_out(["status" => "success", "message" => "Product deleted successfully"]);
