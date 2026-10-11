<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$id = int_field($d, 'invoice_id');
$reason = str_field($d, 'reason');
if (!$reason) {
    fail('Please enter a reason for cancelling');
}

$pdo->beginTransaction();
$inv = $id ? db_one("SELECT * FROM invoices WHERE invoice_id = ? FOR UPDATE", [$id]) : null;
if (!$inv) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $inv['store_id']);
if ($inv['status'] === 'Cancelled') {
    fail('Invoice is already cancelled', 409);
}
if ($inv['irn']) {
    fail('This invoice has an e-invoice IRN. Cancel the IRN on the IRP portal first, then remove it here.', 409);
}

db_query("UPDATE invoices SET status = 'Cancelled', cancel_reason = ?, cancelled_at = NOW(), balance_due = 0 WHERE invoice_id = ?", [$reason, $id]);
foreach (db_all("SELECT product_id, quantity FROM invoice_items WHERE invoice_id = ? AND product_id IS NOT NULL", [$id]) as $it) {
    move_stock((int) $it['product_id'], (float) $it['quantity'], 'cancel', 'invoice', $id, "Cancelled {$inv['invoice_number']}", (int) $user['user_id']);
}
if ($inv['order_id']) {
    db_query("UPDATE orders SET status = 'Approved' WHERE order_id = ? AND status = 'Invoiced'", [$inv['order_id']]);
}
$pdo->commit();

json_out(["status" => "success", "message" => "Invoice {$inv['invoice_number']} cancelled. Stock has been restored."]);
