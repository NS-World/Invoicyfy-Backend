<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$pdo->beginTransaction();
$id = int_field($d, 'invoice_id');
$invoice = $id ? db_one("SELECT * FROM invoices WHERE invoice_id = ? FOR UPDATE", [$id]) : null;
if (!$invoice) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $invoice['store_id']);
$paymentId = record_payment($invoice, $user, $d);
$pdo->commit();
log_activity('payment.add', 'invoice', $id, 'Payment ' . rupees($d['amount'] ?? 0) . ' (' . ($d['payment_method'] ?? 'Cash') . ") on bill {$invoice['invoice_number']}", [], $invoice['store_id']);

json_out([
    "status" => "success",
    "message" => "Payment recorded",
    "payment_id" => $paymentId,
    "invoice" => doc_cast(db_one("SELECT invoice_id, invoice_number, total_amount, amount_paid, balance_due, status FROM invoices WHERE invoice_id = ?", [$id])),
], 201);
