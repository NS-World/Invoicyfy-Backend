<?php
/** Suppliers with purchase bills or payments are switched off instead of deleted, so their history stays. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'DELETE');
$user = require_auth();

$s = load_supplier($user, int_field(input(), 'supplier_id') ?? 0);
$used = db_value('SELECT 1 FROM purchases WHERE supplier_id = ? LIMIT 1', [$s['supplier_id']]) || db_value('SELECT 1 FROM supplier_payments WHERE supplier_id = ? LIMIT 1', [$s['supplier_id']]);
if ($used) {
    db_query('UPDATE suppliers SET is_active = 0 WHERE supplier_id = ?', [$s['supplier_id']]);
    log_activity('supplier.deactivate', 'supplier', $s['supplier_id'], "Switched off supplier {$s['name']}", [], $s['store_id']);
    json_out(['status' => 'success', 'message' => "{$s['name']} has purchase history, so it was switched off instead of deleted"]);
}
db_query('DELETE FROM suppliers WHERE supplier_id = ?', [$s['supplier_id']]);
log_activity('supplier.delete', 'supplier', $s['supplier_id'], "Deleted supplier {$s['name']}", [], $s['store_id']);
json_out(['status' => 'success', 'message' => "Supplier {$s['name']} deleted"]);
