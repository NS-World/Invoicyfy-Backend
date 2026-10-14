<?php
/**
 * Pay a supplier. POST { supplier_id, amount, payment_method, payment_date, reference_no, notes, purchase_id? }
 * Without purchase_id the amount is used for the oldest unpaid bills first (any extra stays as advance).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../purchase/_common.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$amount = money(num_field($d, 'amount', 0));
if ($amount <= 0) {
    fail('Enter the amount paid');
}
$method = str_field($d, 'payment_method', 'Cash');
if (!in_array($method, PAYMENT_METHODS, true)) {
    fail('Invalid payment method');
}
$date = date_field($d, 'payment_date', date('Y-m-d'));
if ($date > date('Y-m-d')) {
    fail('Payment date cannot be in the future');
}
$pdo->beginTransaction();
$s = load_supplier($user, int_field($d, 'supplier_id') ?? 0, true);
$purchaseId = int_field($d, 'purchase_id');
$targets = [];
if ($purchaseId) {
    $p = db_one('SELECT * FROM purchases WHERE purchase_id = ? AND supplier_id = ? FOR UPDATE', [$purchaseId, $s['supplier_id']]);
    if (!$p) {
        fail('Purchase bill not found for this supplier', 404);
    }
    if ($p['status'] === 'Cancelled') {
        fail('This purchase bill is cancelled', 409);
    }
    if ($amount - (float) $p['balance_due'] > 0.009) {
        fail('Amount is more than the balance of this bill (' . rupees($p['balance_due']) . ')', 422);
    }
    $targets = [$p];
} else {
    $targets = db_all("SELECT * FROM purchases WHERE supplier_id = ? AND status IN ('Unpaid','Partial') ORDER BY purchase_date, purchase_id FOR UPDATE", [$s['supplier_id']]);
}
$left = $amount;
$ids = [];
$common = ['store_id' => (int) $s['store_id'], 'supplier_id' => (int) $s['supplier_id'], 'payment_method' => $method, 'payment_date' => $date,
    'reference_no' => str_field($d, 'reference_no'), 'notes' => mb_substr((string) str_field($d, 'notes', ''), 0, 500) ?: null, 'user_id' => (int) $user['user_id']];
foreach ($targets as $p) {
    if ($left <= 0) {
        break;
    }
    $part = min($left, (float) $p['balance_due']);
    if ($part <= 0) {
        continue;
    }
    $ids[] = insert_row('supplier_payments', $common + ['purchase_id' => (int) $p['purchase_id'], 'amount' => round($part, 2)]);
    refresh_purchase_status((int) $p['purchase_id']);
    $left = round($left - $part, 2);
}
if ($left > 0) {
    // Advance / opening balance payment, not tied to a bill.
    $ids[] = insert_row('supplier_payments', $common + ['purchase_id' => null, 'amount' => $left]);
}
$pdo->commit();
log_activity('supplier.payment', 'supplier', $s['supplier_id'], 'Paid ' . rupees($amount) . " to {$s['name']} ($method)", ['payment_ids' => $ids], $s['store_id']);
json_out(['status' => 'success', 'message' => 'Payment of ' . rupees($amount) . " to {$s['name']} saved", 'payment_ids' => $ids, 'data' => supplier_balance((int) $s['supplier_id'])], 201);
