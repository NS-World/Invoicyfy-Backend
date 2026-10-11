<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
$user = require_auth();

$data = input();
$id = int_field($data, 'store_id');
if (!$id || !db_value("SELECT 1 FROM stores WHERE store_id = ?", [$id])) {
    fail('Store not found', 404);
}
can_edit_store($user, $id);
$p = store_payload($data);
if (db_value("SELECT 1 FROM stores WHERE name = ? AND store_id <> ? LIMIT 1", [$p['name'], $id])) {
    fail('Another store with this name already exists', 409);
}
$set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($p)));
db_query("UPDATE stores SET $set WHERE store_id = ?", array_merge(array_values($p), [$id]));
json_out(["status" => "success", "message" => "Store updated successfully"]);
