<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET', 'PUT', 'POST');
$user = require_auth();

$id = int_field($_GET, 'store_id', $user['store_id'] !== null ? (int) $user['store_id'] : null);
$store = $id ? db_one("SELECT * FROM stores WHERE store_id = ?", [$id]) : null;
if (!$store) {
    fail('Store not found', 404);
}
assert_store_access($user, $id);
json_out(["status" => "success", "data" => $store]);
