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
db_query("DELETE FROM payments WHERE payment_id = ?", [$id]);
stats_touch($payment['store_id'] ?? 0, $payment['payment_date']);
if ($payment['invoice_id']) {
    refresh_invoice_payment_status((int) $payment['invoice_id']);
}
$pdo->commit();
log_activity('payment.delete', 'invoice', $payment['invoice_id'], 'Deleted payment ' . rupees($payment['amount']) . " ({$payment['payment_method']}, {$payment['payment_date']})", [], $payment['store_id']);
json_out(["status" => "success", "message" => "Payment deleted"]);
