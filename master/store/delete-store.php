<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'DELETE');
$user = require_auth();
require_admin($user);

$id = int_field(input(), 'store_id');
if (!$id) {
    fail('store_id is required');
}
foreach (['invoices', 'orders', 'expenses'] as $t) {
    if (db_value("SELECT 1 FROM $t WHERE store_id = ? LIMIT 1", [$id])) {
        fail("This store has $t and cannot be deleted", 409);
    }
}
if ((int) db_value("SELECT COUNT(*) FROM stores") <= 1) {
    fail('You need at least one store', 409);
}
$deleted = db_query("DELETE FROM stores WHERE store_id = ?", [$id])->rowCount();
if (!$deleted) {
    fail('Store not found', 404);
}
json_out(["status" => "success", "message" => "Store deleted successfully"]);
