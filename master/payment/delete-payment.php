<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('DELETE', 'POST', 'PUT');
$user = require_auth();

$id = int_field(input(), 'payment_id');
$pdo->beginTransaction();
$payment = $id ? db_one("SELECT * FROM payments WHERE payment_id = ? FOR UPDATE", [$id]) : null;
if (!$payment) {
    fail('Payment not found', 404);
}
assert_store_access($user, $payment['store_id']);
if (!is_admin($user) && strcasecmp((string) $user['role_name'], 'Manager') !== 0) {
    fail('Only an Admin or Manager can delete payments', 403);
}
db_query("DELETE FROM payments WHERE payment_id = ?", [$id]);
if ($payment['invoice_id']) {
    refresh_invoice_payment_status((int) $payment['invoice_id']);
}
$pdo->commit();
json_out(["status" => "success", "message" => "Payment deleted"]);
