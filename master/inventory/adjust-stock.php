<?php
/**
 * Stock in / out.
 *   type: purchase (+), return (+, customer returned goods), adjustment (+/- correction), damage (-, wastage/expiry)
 *   quantity: always positive; for "adjustment" use direction = "in" | "out".
 *   For purchases, unit_cost updates the product cost price and record_expense=true also books an expense.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$productId = int_field($d, 'product_id');
$type = strtolower((string) str_field($d, 'type', 'purchase'));
$qty = num_field($d, 'quantity');
if (!in_array($type, ['purchase', 'return', 'adjustment', 'damage'], true)) {
    fail('Invalid stock movement type');
}
if ($qty === null || $qty <= 0) {
    fail('Quantity must be greater than zero');
}
$direction = $type === 'damage' ? -1 : (($type === 'adjustment' && str_field($d, 'direction', 'in') === 'out') ? -1 : 1);
$unitCost = num_field($d, 'unit_cost');
if ($unitCost !== null && $unitCost < 0) {
    fail('Unit cost cannot be negative');
}

$pdo->beginTransaction();
$product = $productId ? db_one("SELECT * FROM products WHERE product_id = ? FOR UPDATE", [$productId]) : null;
if (!$product) {
    fail('Product not found', 404);
}
if ($product['store_id'] !== null) {
    assert_store_access($user, $product['store_id']);
}
if (!(int) $product['track_inventory']) {
    db_query("UPDATE products SET track_inventory = 1 WHERE product_id = ?", [$productId]);
}
$notes = str_field($d, 'notes');
move_stock($productId, $direction * $qty, $type, 'manual', null, $notes, (int) $user['user_id']);
if ($unitCost !== null) {
    db_query("UPDATE stock_movements SET unit_cost = ? WHERE movement_id = LAST_INSERT_ID()", [$unitCost]);
    if ($type === 'purchase' && $unitCost > 0) {
        db_query("UPDATE products SET cost_price = ? WHERE product_id = ?", [$unitCost, $productId]);
    }
}
$expenseId = null;
if ($type === 'purchase' && bool_field($d, 'record_expense') && $unitCost) {
    $expenseId = insert_row('expenses', [
        'store_id' => (int) ($product['store_id'] ?? $user['store_id']),
        'user_id' => (int) $user['user_id'],
        'expense_date' => date_field($d, 'date', date('Y-m-d')),
        'category' => 'Purchase / Raw Material',
        'description' => "Stock purchase: {$product['name']} x " . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
        'vendor' => str_field($d, 'vendor'),
        'amount' => round($qty * $unitCost, 2),
        'payment_method' => str_field($d, 'payment_method', 'Cash'),
        'reference_no' => str_field($d, 'reference_no'),
    ]);
}
$pdo->commit();

$stock = (float) db_value("SELECT stock_qty FROM products WHERE product_id = ?", [$productId]);
json_out(["status" => "success", "message" => "Stock updated. Current stock: " . rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.'), "stock_qty" => $stock, "expense_id" => $expenseId]);
