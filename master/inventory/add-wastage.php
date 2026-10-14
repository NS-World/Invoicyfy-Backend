<?php
/**
 * Wastage: melted, expired or damaged stock. POST { product_id, quantity, reason, batch_id?, notes? }
 * Stock goes down (from the given batch first) and the cost is kept for the wastage report.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$qty = num_field($d, 'quantity', 0);
if ($qty <= 0) {
    fail('Quantity must be greater than zero');
}
$reason = str_field($d, 'reason', 'Other');
if (!in_array($reason, WASTAGE_REASONS, true)) {
    fail('Choose a reason: ' . implode(', ', WASTAGE_REASONS));
}
$notes = str_field($d, 'notes');
if ($notes !== null && mb_strlen($notes) > 300) {
    fail('Note is too long');
}
$pdo->beginTransaction();
$product = db_one('SELECT * FROM products WHERE product_id = ? FOR UPDATE', [int_field($d, 'product_id') ?? 0]);
if (!$product) {
    fail('Product not found', 404);
}
if ($product['store_id'] !== null) {
    assert_store_access($user, $product['store_id']);
}
$batchId = int_field($d, 'batch_id');
$batch = null;
if ($batchId) {
    $batch = db_one('SELECT * FROM stock_batches WHERE batch_id = ? AND product_id = ?', [$batchId, $product['product_id']]);
    if (!$batch) {
        fail('Batch not found for this product', 404);
    }
}
if (!(int) $product['track_inventory']) {
    db_query('UPDATE products SET track_inventory = 1 WHERE product_id = ?', [$product['product_id']]);
}
$cost = $batch && $batch['unit_cost'] !== null ? (float) $batch['unit_cost'] : (float) $product['cost_price'];
$text = $reason . ($batch ? ' (batch ' . ($batch['batch_no'] ?: '#' . $batch['batch_id']) . ')' : '') . ($notes ? " — $notes" : '');
move_stock((int) $product['product_id'], -$qty, 'wastage', 'wastage', null, $text, (int) $user['user_id'], $batchId);
db_query('UPDATE stock_movements SET unit_cost = ? WHERE movement_id = LAST_INSERT_ID()', [$cost]);
$pdo->commit();
$stock = (float) db_value('SELECT stock_qty FROM products WHERE product_id = ?', [$product['product_id']]);
log_activity('stock.wastage', 'product', $product['product_id'], "Wastage {$product['name']}: -" . qty_text($qty) . " {$product['unit']} ($text), loss " . rupees($qty * $cost), [], $product['store_id']);
json_out(['status' => 'success', 'message' => 'Wastage saved. Stock now ' . qty_text($stock), 'stock_qty' => $stock, 'loss' => round($qty * $cost, 2)], 201);
