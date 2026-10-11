<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

[$store, $head, $lines] = build_order(input(), $user);

$pdo->beginTransaction();
$seq = next_sequence((int) $store['store_id'], 'order', financial_year($head['order_date']));
$head['order_number'] = sprintf('ORD/%s/%04d', financial_year($head['order_date']), $seq);
$head['status'] = 'Pending';
$orderId = insert_row('orders', $head);
write_order_items($orderId, $lines);
$pdo->commit();

json_out([
    "status" => "success",
    "message" => "Order created successfully",
    "order_id" => $orderId,
    "order_number" => $head['order_number'],
    "items_created" => count($lines),
    "data" => load_order($orderId),
], 201);
