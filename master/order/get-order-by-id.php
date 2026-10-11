<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$id = int_field($_GET, 'order_id');
$order = $id ? load_order($id) : null;
if (!$order) {
    fail('Order not found', 404);
}
assert_store_access($user, $order['store_id']);
$items = $order['items'];
unset($order['items']);
json_out(["status" => "success", "order" => $order, "items" => $items]);
