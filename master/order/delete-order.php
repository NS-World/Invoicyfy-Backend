<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('DELETE', 'POST', 'PUT');
$user = require_auth();

$id = int_field(input(), 'order_id');
$order = $id ? db_one("SELECT order_id, status, store_id FROM orders WHERE order_id = ?", [$id]) : null;
if (!$order) {
    fail('Order not found', 404);
}
assert_store_access($user, $order['store_id']);
if (db_value("SELECT 1 FROM invoices WHERE order_id = ? LIMIT 1", [$id])) {
    fail('This order has an invoice and cannot be deleted. Cancel the order instead.', 409);
}
db_query("DELETE FROM orders WHERE order_id = ?", [$id]);
json_out(["status" => "success", "message" => "Order deleted successfully"]);
