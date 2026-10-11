<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
require_auth();

$data = input();
$id = int_field($data, 'customer_id');
if (!$id || !db_value("SELECT 1 FROM customers WHERE customer_id = ?", [$id])) {
    fail('Customer not found', 404);
}
$p = customer_payload($data);
customer_duplicate_check($p, $id);

$set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($p)));
db_query("UPDATE customers SET $set WHERE customer_id = ?", array_merge(array_values($p), [$id]));
json_out(["status" => "success", "message" => "Customer updated successfully"]);
