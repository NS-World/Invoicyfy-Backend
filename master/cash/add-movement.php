<?php
/** Pay in / pay out of the drawer: POST { session_id, type: in|out, amount, reason } on the open day. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$type = str_field($d, 'type');
if (!in_array($type, ['in', 'out'], true)) {
    fail('Type must be "in" (cash added) or "out" (cash taken)');
}
$amount = num_field($d, 'amount');
if ($amount === null || $amount <= 0 || $amount > 10000000) {
    fail('Enter an amount greater than zero');
}
$reason = str_field($d, 'reason');
if (!$reason) {
    fail('Write a reason, e.g. "Milk payment" or "Change added"');
}
$pdo->beginTransaction();
$s = load_cash_session($user, int_field($d, 'session_id') ?? 0, true);
if ($s['status'] !== 'Open') {
    fail('This day is already closed', 409);
}
if ($type === 'out') {
    $report = cash_session_report($s);
    if ($amount - $report['expected_cash'] > 0.009) {
        fail('Only ' . rupees($report['expected_cash']) . ' cash should be in the drawer', 422);
    }
}
$id = insert_row('cash_movements', [
    'session_id' => (int) $s['session_id'],
    'store_id' => (int) $s['store_id'],
    'user_id' => (int) $user['user_id'],
    'type' => $type,
    'amount' => money($amount),
    'reason' => mb_substr($reason, 0, 255),
]);
$pdo->commit();
log_activity('cash.' . ($type === 'in' ? 'pay_in' : 'pay_out'), 'cash_session', $s['session_id'], ($type === 'in' ? 'Pay in ' : 'Pay out ') . rupees($amount) . " — $reason", [], $s['store_id']);
json_out(['status' => 'success', 'message' => $type === 'in' ? 'Cash added' : 'Cash taken out', 'movement_id' => $id,
    'data' => cash_session_full(load_cash_session($user, (int) $s['session_id']))], 201);
