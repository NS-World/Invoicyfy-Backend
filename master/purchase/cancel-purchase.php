<?php
/** Cancel a wrong purchase bill: its stock is taken out again. Payments must be deleted first. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$reason = str_field($d, 'reason');
if (!$reason) {
    fail('Please enter a reason for cancelling');
}
$pdo->beginTransaction();
$p = db_one('SELECT * FROM purchases WHERE purchase_id = ? FOR UPDATE', [int_field($d, 'purchase_id') ?? 0]);
if (!$p) {
    fail('Purchase bill not found', 404);
}
assert_store_access($user, $p['store_id']);
if ($p['status'] === 'Cancelled') {
    fail('This purchase bill is already cancelled', 409);
}
if (db_value('SELECT 1 FROM supplier_payments WHERE purchase_id = ? LIMIT 1', [$p['purchase_id']])) {
    fail('Payments are recorded against this bill. Delete those payments first, then cancel.', 409);
}
$label = 'Purchase ' . ($p['bill_no'] ?: '#' . $p['purchase_id']) . ' cancelled';
foreach (db_all('SELECT product_id, quantity, batch_id FROM purchase_items WHERE purchase_id = ? AND product_id IS NOT NULL', [$p['purchase_id']]) as $it) {
    move_stock((int) $it['product_id'], -(float) $it['quantity'], 'purchase_cancel', 'purchase', (int) $p['purchase_id'], $label, (int) $user['user_id'], $it['batch_id'] ? (int) $it['batch_id'] : null);
}
db_query("UPDATE purchases SET status = 'Cancelled', balance_due = 0, cancel_reason = ?, cancelled_at = NOW() WHERE purchase_id = ?", [mb_substr($reason, 0, 255), $p['purchase_id']]);
$pdo->commit();
log_activity('purchase.cancel', 'purchase', $p['purchase_id'], "$label (" . rupees($p['total_amount']) . ") — $reason", [], $p['store_id']);
json_out(['status' => 'success', 'message' => "$label. Stock taken out again."]);
