<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
require_auth();

$id = int_field($_GET, 'customer_id');
$customer = $id ? db_one("SELECT * FROM customers WHERE customer_id = ?", [$id]) : null;
if (!$customer) {
    fail('Customer not found', 404);
}
$customer['summary'] = cast_numbers(db_one(
    "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(total_amount),0) AS total_billed,
            COALESCE(SUM(amount_paid),0) AS total_paid, COALESCE(SUM(balance_due),0) AS outstanding
     FROM invoices WHERE customer_id = ? AND status <> 'Cancelled'",
    [$id]
), ['total_billed', 'total_paid', 'outstanding'], ['invoice_count']);

json_out(["status" => "success", "data" => $customer]);
