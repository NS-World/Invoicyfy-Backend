<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'DELETE');
require_auth();

$id = int_field(input(), 'customer_id');
if (!$id) {
    fail('customer_id is required');
}
$customer = db_one('SELECT customer_id, name FROM customers WHERE customer_id = ?' . customer_active_sql(), [$id]);
if (!$customer) {
    fail('Customer not found', 404);
}
$hasHistory = db_value('SELECT 1 FROM invoices WHERE customer_id = ? LIMIT 1', [$id]) || db_value('SELECT 1 FROM orders WHERE customer_id = ? LIMIT 1', [$id]);
if (!$hasHistory) {
    // No bills or orders: removed completely, as before.
    db_query('DELETE FROM customers WHERE customer_id = ?', [$id]);
    log_activity('customer.delete', 'customer', $id, "Deleted customer {$customer['name']}");
    json_out(['status' => 'success', 'message' => 'Customer deleted successfully']);
}
if (customer_active_sql() === '') {
    // Database not updated yet (no deleted_at column): keep the old rule.
    fail('This customer has orders or invoices and cannot be deleted. Admin: press "Update database" once to allow it.', 409);
}
// Has bills / orders: hidden from the customer list, search and messages; the bills, udhaar and reports stay as they are.
db_query('UPDATE customers SET deleted_at = NOW(), marketing_opt_in = 0 WHERE customer_id = ?', [$id]);
log_activity('customer.delete', 'customer', $id, "Deleted customer {$customer['name']} (old bills kept)");
json_out(['status' => 'success', 'message' => 'Customer deleted. Their old bills and payments are kept.']);
