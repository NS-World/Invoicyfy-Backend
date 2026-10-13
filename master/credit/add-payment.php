<?php
/**
 * Receive money against a customer's dues: POST { customer_id, amount, payment_method, reference_no, payment_date, notes, invoice_ids? }.
 * The amount is applied to the oldest unpaid bills first (or only to invoice_ids when given).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$customerId = int_field($d, 'customer_id') ?? 0;
$amount = money(num_field($d, 'amount', 0));
if ($amount <= 0) {
    fail('Enter the amount received');
}
$name = db_value('SELECT name FROM customers WHERE customer_id = ?', [$customerId]);
if ($name === null) {
    fail('Customer not found', 404);
}
$storeId = scope_store($user, $d['store_id'] ?? null);
$only = array_values(array_filter(array_map('intval', (array) ($d['invoice_ids'] ?? []))));

$pdo->beginTransaction();
$sql = "SELECT * FROM invoices WHERE customer_id = ? AND status <> 'Cancelled' AND balance_due > 0";
$params = [$customerId];
if ($storeId !== null) {
    $sql .= ' AND store_id = ?';
    $params[] = $storeId;
}
if ($only) {
    $sql .= ' AND invoice_id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
    $params = array_merge($params, $only);
}
$bills = db_all($sql . ' ORDER BY invoice_date, invoice_id FOR UPDATE', $params);
$due = money(array_sum(array_column($bills, 'balance_due')));
if (!$bills) {
    fail('This customer has nothing pending', 409);
}
if ($amount - $due > 0.009) {
    fail('Amount is more than the pending ' . rupees($due), 422);
}
$left = $amount;
$applied = [];
foreach ($bills as $inv) {
    if ($left <= 0) {
        break;
    }
    $pay = min($left, money($inv['balance_due']));
    record_payment($inv, $user, [
        'amount' => $pay,
        'payment_method' => str_field($d, 'payment_method', 'Cash'),
        'payment_date' => date_field($d, 'payment_date', date('Y-m-d')),
        'reference_no' => str_field($d, 'reference_no'),
        'notes' => str_field($d, 'notes', 'Received against dues'),
    ]);
    $applied[] = ['invoice_id' => (int) $inv['invoice_id'], 'invoice_number' => $inv['invoice_number'], 'amount' => $pay];
    $left = money($left - $pay);
}
$pdo->commit();
log_activity('credit.payment', 'customer', $customerId, 'Received ' . rupees($amount) . " from $name against " . count($applied) . ' bill(s)', ['applied' => $applied], $bills[0]['store_id']);
json_out(['status' => 'success', 'message' => 'Payment of ' . rupees($amount) . ' recorded', 'applied' => $applied, 'remaining_due' => money($due - $amount)], 201);
