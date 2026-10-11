<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
$user = require_auth();

$data = input();
$orderId = int_field($data, 'order_id');
$existing = $orderId ? db_one("SELECT * FROM orders WHERE order_id = ?", [$orderId]) : null;
if (!$existing) {
    fail('Order not found', 404);
}
assert_store_access($user, $existing['store_id']);
if (in_array($existing['status'], ['Invoiced', 'Cancelled'], true)) {
    fail("An {$existing['status']} order cannot be edited", 409);
}
$data['store_id'] = $existing['store_id'];
[, $head, $lines] = build_order($data, $user);

$pdo->beginTransaction();
update_row('orders', 'order_id', $orderId, $head);
write_order_items($orderId, $lines);
$pdo->commit();

json_out(["status" => "success", "message" => "Order updated successfully", "data" => load_order($orderId)]);
