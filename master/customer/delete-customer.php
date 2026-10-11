<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'DELETE');
require_auth();

$id = int_field(input(), 'customer_id');
if (!$id) {
    fail('customer_id is required');
}
if (db_value("SELECT 1 FROM invoices WHERE customer_id = ? LIMIT 1", [$id]) || db_value("SELECT 1 FROM orders WHERE customer_id = ? LIMIT 1", [$id])) {
    fail('This customer has orders or invoices and cannot be deleted', 409);
}
$deleted = db_query("DELETE FROM customers WHERE customer_id = ?", [$id])->rowCount();
if (!$deleted) {
    fail('Customer not found', 404);
}
json_out(["status" => "success", "message" => "Customer deleted successfully"]);
