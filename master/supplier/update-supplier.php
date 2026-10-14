<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$s = load_supplier($user, int_field($d, 'supplier_id') ?? 0);
$p = supplier_payload($d);
if ($p['phone'] && db_value('SELECT 1 FROM suppliers WHERE store_id = ? AND phone = ? AND supplier_id <> ?', [$s['store_id'], $p['phone'], $s['supplier_id']])) {
    fail("Another supplier has phone {$p['phone']}", 409);
}
if (db_value('SELECT 1 FROM suppliers WHERE store_id = ? AND name = ? AND supplier_id <> ?', [$s['store_id'], $p['name'], $s['supplier_id']])) {
    fail("Another supplier is called {$p['name']}", 409);
}
update_row('suppliers', 'supplier_id', (int) $s['supplier_id'], $p);
log_activity('supplier.update', 'supplier', $s['supplier_id'], "Updated supplier {$p['name']}", [], $s['store_id']);
json_out(['status' => 'success', 'message' => 'Supplier updated', 'data' => supplier_row(db_one('SELECT * FROM suppliers WHERE supplier_id = ?', [$s['supplier_id']]))]);
