<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$data = input();
$p = product_payload($data, $user);
product_duplicate_check($p);
$opening = max(0, num_field($data, 'stock_qty', 0) ?? 0);

$pdo->beginTransaction();
$cols = array_keys($p);
db_query(
    "INSERT INTO products (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
    array_values($p)
);
$id = (int) $pdo->lastInsertId();
if ($opening > 0) {
    move_stock($id, $opening, 'opening', null, null, 'Opening stock', (int) $user['user_id']);
}
$pdo->commit();

json_out(["status" => "success", "message" => "Product created successfully", "product_id" => $id], 201);
