<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
$user = require_auth();

$data = input();
$id = int_field($data, 'product_id');
$existing = $id ? db_one("SELECT * FROM products WHERE product_id = ?", [$id]) : null;
if (!$existing) {
    fail('Product not found', 404);
}
if ($existing['store_id'] !== null) {
    assert_store_access($user, $existing['store_id']);
}
$p = product_payload($data, $user);
product_duplicate_check($p, $id);

// Stock is changed only through inventory adjustments so every change is audited.
$set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($p)));
db_query("UPDATE products SET $set WHERE product_id = ?", array_merge(array_values($p), [$id]));
$changes = [];
foreach (['price' => 'price', 'cost_price' => 'cost', 'gst_rate' => 'GST %', 'name' => 'name', 'status' => 'status'] as $k => $label) {
    if (array_key_exists($k, $p) && (string) $p[$k] !== (string) $existing[$k] && !(is_numeric($p[$k]) && (float) $p[$k] == (float) $existing[$k])) {
        $changes[] = "$label {$existing[$k]} → {$p[$k]}";
    }
}
if ($changes) {
    log_activity(isset($p['price']) && (float) $p['price'] != (float) $existing['price'] ? 'product.price' : 'product.update', 'product', $id,
        "Product {$existing['name']}: " . implode(', ', $changes), [], $existing['store_id']);
}
json_out(["status" => "success", "message" => "Product updated successfully"]);
