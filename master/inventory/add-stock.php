<?php
/**
 * "Add inventory": stock in for many products at once.
 * POST {items: [{product_id, quantity, unit_cost?, batch_no?, expiry_date?}], notes?}
 * Products not tracked yet start being tracked. Each line is a normal stock-in entry (stock history, batch).
 * Returns every product's new stock and status.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$items = is_array($d['items'] ?? null) ? $d['items'] : [];
$notes = str_field($d, 'notes');
if ($notes !== null && mb_strlen($notes) > 500) {
    fail('Note is too long');
}
$lines = [];
foreach ($items as $i => $it) {
    $row = $i + 1;
    $pid = is_array($it) ? (int) ($it['product_id'] ?? 0) : 0;
    $qty = is_array($it) && is_numeric($it['quantity'] ?? null) ? (float) $it['quantity'] : 0.0;
    if (!$pid || $qty == 0.0) {
        continue; // rows left empty in the list
    }
    if ($qty < 0 || $qty > 1000000) {
        fail("Row $row: quantity must be between 0 and 10,00,000");
    }
    $cost = isset($it['unit_cost']) && $it['unit_cost'] !== '' && $it['unit_cost'] !== null ? (float) $it['unit_cost'] : null;
    if ($cost !== null && $cost < 0) {
        fail("Row $row: cost cannot be negative");
    }
    $batchNo = isset($it['batch_no']) ? trim((string) $it['batch_no']) : '';
    if (mb_strlen($batchNo) > 50) {
        fail("Row $row: batch number is too long");
    }
    $lines[$pid] = ['product_id' => $pid, 'quantity' => round($qty + ($lines[$pid]['quantity'] ?? 0), 3), 'unit_cost' => $cost,
        'batch_no' => $batchNo !== '' ? $batchNo : null, 'expiry_date' => date_field($it, 'expiry_date')];
}
if (!$lines) {
    fail('Enter the quantity for at least one product');
}
if (count($lines) > 500) {
    fail('At most 500 products at a time');
}

$pdo->beginTransaction();
$products = [];
$ids = array_keys($lines);
foreach (db_all('SELECT * FROM products WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', $ids) as $p) {
    $products[(int) $p['product_id']] = $p;
}
foreach ($lines as $pid => $l) {
    $p = $products[$pid] ?? null;
    if (!$p) {
        fail("Product #$pid not found", 404);
    }
    if ($p['store_id'] !== null) {
        assert_store_access($user, $p['store_id']);
    }
    if (!(int) $p['track_inventory']) {
        db_query('UPDATE products SET track_inventory = 1 WHERE product_id = ?', [$pid]);
    }
    move_stock($pid, $l['quantity'], 'purchase', 'manual', null, $notes ?? 'Add inventory', (int) $user['user_id']);
    if ($l['unit_cost'] !== null) {
        db_query('UPDATE stock_movements SET unit_cost = ? WHERE movement_id = LAST_INSERT_ID()', [$l['unit_cost']]);
        if ($l['unit_cost'] > 0) {
            db_query('UPDATE products SET cost_price = ? WHERE product_id = ?', [$l['unit_cost'], $pid]);
        }
    }
    add_batch($pid, $p['store_id'] !== null ? (int) $p['store_id'] : null, $l['quantity'], $l['batch_no'], $l['expiry_date'], $l['unit_cost'], null);
}
$pdo->commit();

$result = [];
foreach (db_all('SELECT product_id, name, unit, stock_qty, low_stock_threshold FROM products WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p) {
    $stock = (float) $p['stock_qty'];
    $result[] = ['product_id' => (int) $p['product_id'], 'name' => $p['name'], 'unit' => $p['unit'], 'added' => $lines[(int) $p['product_id']]['quantity'],
        'stock_qty' => $stock, 'status' => $stock <= 0 ? 'Out of stock' : ($stock <= (float) $p['low_stock_threshold'] ? 'Low stock' : 'In stock')];
}
$total = array_sum(array_column($lines, 'quantity'));
log_activity('stock.purchase', 'product', null, 'Add inventory: ' . count($lines) . ' product(s), ' . rtrim(rtrim(number_format($total, 3, '.', ''), '0'), '.') . ' units' . ($notes ? " — $notes" : ''));
json_out(['status' => 'success', 'message' => 'Stock added for ' . count($lines) . ' product(s)', 'data' => $result]);
