<?php
/**
 * Purchase bill from a supplier: stock goes up, cost price is updated, GST paid is kept as input tax credit and
 * the unpaid part becomes the supplier's balance.
 * POST { store_id, supplier_id, bill_no, purchase_date, due_date, items: [...], other_charges, notes,
 *        update_cost (default true), payment: { amount, payment_method, reference_no } }
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$store = get_store($storeId);
$supplierId = int_field($d, 'supplier_id');
$supplier = $supplierId ? db_one('SELECT * FROM suppliers WHERE supplier_id = ?', [$supplierId]) : null;
if (!$supplier || (int) $supplier['store_id'] !== $storeId) {
    fail('Select the supplier', 422);
}
$date = date_field($d, 'purchase_date', date('Y-m-d'));
if ($date > date('Y-m-d')) {
    fail('Purchase date cannot be in the future');
}
$due = date_field($d, 'due_date');
if ($due && $due < $date) {
    fail('Due date cannot be before the purchase date');
}
$billNo = str_field($d, 'bill_no');
if ($billNo !== null && mb_strlen($billNo) > 50) {
    fail('Bill number is too long');
}
if ($billNo && db_value("SELECT 1 FROM purchases WHERE supplier_id = ? AND bill_no = ? AND status <> 'Cancelled'", [$supplierId, $billNo])) {
    fail("Bill $billNo of {$supplier['name']} is already entered", 409);
}
$other = num_field($d, 'other_charges', 0);
if ($other < 0 || $other > 10000000) {
    fail('Other charges must be 0 or more');
}
$interstate = $supplier['state_code'] && $store['state_code'] && $supplier['state_code'] !== $store['state_code'];
[$lines, $t] = purchase_lines($d['items'] ?? [], (bool) $interstate, $other);
$pay = is_array($d['payment'] ?? null) ? $d['payment'] : [];
$paid = money($pay['amount'] ?? 0);
if ($paid < 0 || $paid - $t['total_amount'] > 0.009) {
    fail('Amount paid cannot be more than the bill total (' . rupees($t['total_amount']) . ')', 422);
}
$method = str_field($pay, 'payment_method', 'Cash');
if ($paid > 0 && !in_array($method, PAYMENT_METHODS, true)) {
    fail('Invalid payment method');
}
$updateCost = bool_field($d, 'update_cost', true);

$pdo->beginTransaction();
$id = insert_row('purchases', [
    'store_id' => $storeId, 'supplier_id' => $supplierId, 'supplier_name' => $supplier['name'], 'supplier_gstin' => $supplier['gstin'],
    'bill_no' => $billNo, 'purchase_date' => $date, 'due_date' => $due, 'is_interstate' => $interstate ? 1 : 0,
    'notes' => mb_substr((string) str_field($d, 'notes', ''), 0, 500) ?: null, 'user_id' => (int) $user['user_id'],
    'balance_due' => $t['total_amount'], 'status' => 'Unpaid',
] + pick($t, ['subtotal', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'other_charges', 'round_off', 'total_amount']));
$label = 'Purchase ' . ($billNo ?: "#$id") . " from {$supplier['name']}";
foreach ($lines as $l) {
    $batchId = null;
    if ($l['product_id']) {
        $pid = (int) $l['product_id'];
        db_query('UPDATE products SET track_inventory = 1 WHERE product_id = ? AND track_inventory = 0', [$pid]);
        move_stock($pid, (float) $l['quantity'], 'purchase', 'purchase', $id, $label, (int) $user['user_id']);
        $unitCost = $l['quantity'] > 0 ? round($l['taxable_value'] / $l['quantity'], 2) : (float) $l['unit_price'];
        db_query("UPDATE stock_movements SET unit_cost = ? WHERE movement_id = LAST_INSERT_ID()", [$unitCost]);
        if ($updateCost && $unitCost > 0) {
            db_query('UPDATE products SET cost_price = ? WHERE product_id = ?', [$unitCost, $pid]);
        }
        $batchId = add_batch($pid, $storeId, (float) $l['quantity'], $l['batch_no'], $l['expiry_date'], $unitCost, $id);
    }
    insert_row('purchase_items', ['purchase_id' => $id, 'batch_id' => $batchId] + pick($l, [
        'product_id', 'product_name', 'hsn_code', 'unit', 'quantity', 'discount_amount', 'gst_rate', 'taxable_value',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'line_total', 'batch_no', 'expiry_date',
    ]) + ['unit_cost' => $l['unit_price']]);
}
if ($paid > 0) {
    insert_row('supplier_payments', [
        'store_id' => $storeId, 'supplier_id' => $supplierId, 'purchase_id' => $id, 'amount' => $paid, 'payment_method' => $method,
        'payment_date' => $date, 'reference_no' => str_field($pay, 'reference_no'), 'user_id' => (int) $user['user_id'],
    ]);
}
refresh_purchase_status($id);
$pdo->commit();

log_activity('purchase.create', 'purchase', $id, "$label " . rupees($t['total_amount']) . ' (' . count($lines) . ' items)' . ($paid > 0 ? ', paid ' . rupees($paid) : ''), [], $storeId);
json_out(['status' => 'success', 'message' => "$label saved. Stock updated.", 'purchase_id' => $id, 'data' => load_purchase($user, $id)], 201);
