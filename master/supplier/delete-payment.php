<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../purchase/_common.php';
allow_methods('POST', 'DELETE');
$user = require_auth();

$id = int_field(input(), 'payment_id') ?? 0;
$pdo->beginTransaction();
$pay = db_one('SELECT * FROM supplier_payments WHERE payment_id = ? FOR UPDATE', [$id]);
if (!$pay) {
    fail('Payment not found', 404);
}
assert_store_access($user, $pay['store_id']);
db_query('DELETE FROM supplier_payments WHERE payment_id = ?', [$id]);
if ($pay['purchase_id']) {
    refresh_purchase_status((int) $pay['purchase_id']);
}
$pdo->commit();
$name = db_value('SELECT name FROM suppliers WHERE supplier_id = ?', [$pay['supplier_id']]);
log_activity('supplier.payment_delete', 'supplier', $pay['supplier_id'], 'Deleted payment ' . rupees($pay['amount']) . " to $name", [], $pay['store_id']);
json_out(['status' => 'success', 'message' => 'Payment deleted']);
