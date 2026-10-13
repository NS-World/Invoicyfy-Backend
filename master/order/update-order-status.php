<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'PATCH');
$user = require_auth();

$data = input();
$id = int_field($data, 'order_id');
$status = ucfirst(strtolower((string) str_field($data, 'status', '')));
if (!$id || !$status) {
    fail('Order ID and status are required');
}
if (!in_array($status, ['Pending', 'Approved', 'Cancelled'], true)) {
    fail('Status can be Pending, Approved or Cancelled. Orders become Invoiced when you generate an invoice.');
}
$order = db_one("SELECT order_id, order_number, status, store_id FROM orders WHERE order_id = ?", [$id]);
if (!$order) {
    fail('Order not found', 404);
}
assert_store_access($user, $order['store_id']);
if ($order['status'] === 'Invoiced') {
    fail('This order is already invoiced. Cancel the invoice first to change it.', 409);
}
db_query("UPDATE orders SET status = ? WHERE order_id = ?", [$status, $id]);
log_activity('order.status', 'order', $id, "Order {$order['order_number']}: {$order['status']} → $status", [], $order['store_id']);
json_out(["status" => "success", "message" => "Order status updated successfully", "order_id" => $id, "new_status" => $status]);
