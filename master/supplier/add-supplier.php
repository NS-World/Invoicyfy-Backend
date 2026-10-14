<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$p = supplier_payload($d);
if ($p['phone'] && db_value('SELECT 1 FROM suppliers WHERE store_id = ? AND phone = ?', [$storeId, $p['phone']])) {
    fail("A supplier with phone {$p['phone']} already exists", 409);
}
if (db_value('SELECT 1 FROM suppliers WHERE store_id = ? AND name = ?', [$storeId, $p['name']])) {
    fail("Supplier {$p['name']} already exists", 409);
}
$id = insert_row('suppliers', $p + ['store_id' => $storeId]);
log_activity('supplier.create', 'supplier', $id, "Added supplier {$p['name']}", [], $storeId);
json_out(['status' => 'success', 'message' => "Supplier {$p['name']} added", 'supplier_id' => $id, 'data' => supplier_row(db_one('SELECT * FROM suppliers WHERE supplier_id = ?', [$id]))], 201);
