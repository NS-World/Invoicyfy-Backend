<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);

$p = store_payload(input());
if (db_value("SELECT 1 FROM stores WHERE name = ? LIMIT 1", [$p['name']])) {
    fail('Store with this name already exists', 409);
}
$cols = array_keys($p);
db_query(
    "INSERT INTO stores (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
    array_values($p)
);
json_out(["status" => "success", "message" => "Store added successfully", "store_id" => (int) $pdo->lastInsertId()], 201);
