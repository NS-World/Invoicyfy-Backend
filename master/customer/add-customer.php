<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
require_auth();

$p = customer_payload(input());
customer_duplicate_check($p);

$cols = array_keys($p);
db_query(
    "INSERT INTO customers (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
    array_values($p)
);
$id = (int) $pdo->lastInsertId();
json_out([
    "status" => "success",
    "message" => "Customer added successfully",
    "customer_id" => $id,
    "data" => db_one("SELECT * FROM customers WHERE customer_id = ?", [$id]),
], 201);
