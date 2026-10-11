<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$id = int_field($_GET, 'invoice_id');
$inv = $id ? db_one("SELECT store_id FROM invoices WHERE invoice_id = ?", [$id]) : null;
if (!$inv) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $inv['store_id']);
$rows = db_all("SELECT p.*, u.name AS received_by FROM payments p LEFT JOIN users u ON u.user_id = p.user_id WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id", [$id]);
json_out(["status" => "success", "payments" => array_map('doc_cast', $rows)]);
