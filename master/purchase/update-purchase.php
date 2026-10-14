<?php
/**
 * Change the bill number, dates or note of a purchase. Items and amounts are not edited
 * (stock and supplier balance depend on them): cancel the bill and enter it again instead.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$p = load_purchase($user, int_field($d, 'purchase_id') ?? 0);
if ($p['status'] === 'Cancelled') {
    fail('This purchase bill is cancelled', 409);
}
$billNo = str_field($d, 'bill_no', $p['bill_no']);
if ($billNo !== null && mb_strlen($billNo) > 50) {
    fail('Bill number is too long');
}
if ($billNo && db_value("SELECT 1 FROM purchases WHERE supplier_id = ? AND bill_no = ? AND purchase_id <> ? AND status <> 'Cancelled'", [$p['supplier_id'], $billNo, $p['purchase_id']])) {
    fail("Bill $billNo of this supplier is already entered", 409);
}
$date = date_field($d, 'purchase_date', $p['purchase_date']);
if ($date > date('Y-m-d')) {
    fail('Purchase date cannot be in the future');
}
$due = array_key_exists('due_date', $d) ? date_field($d, 'due_date') : $p['due_date'];
if ($due && $due < $date) {
    fail('Due date cannot be before the purchase date');
}
update_row('purchases', 'purchase_id', $p['purchase_id'], [
    'bill_no' => $billNo, 'purchase_date' => $date, 'due_date' => $due,
    'notes' => array_key_exists('notes', $d) ? (mb_substr((string) str_field($d, 'notes', ''), 0, 500) ?: null) : $p['notes'],
]);
log_activity('purchase.update', 'purchase', $p['purchase_id'], 'Updated purchase ' . ($billNo ?: '#' . $p['purchase_id']) . " of {$p['supplier_name']}", [], $p['store_id']);
json_out(['status' => 'success', 'message' => 'Purchase bill updated', 'data' => load_purchase($user, $p['purchase_id'])]);
