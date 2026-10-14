<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$id = int_field($d, 'invoice_id');
$reason = str_field($d, 'reason');
if (!$reason) {
    fail('Please enter a reason for cancelling');
}

$pdo->beginTransaction();
$inv = $id ? db_one("SELECT * FROM invoices WHERE invoice_id = ? FOR UPDATE", [$id]) : null;
if (!$inv) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $inv['store_id']);
if ($inv['status'] === 'Cancelled') {
    fail('Invoice is already cancelled', 409);
}
if ($inv['irn']) {
    fail('This invoice has an e-invoice IRN. Cancel the IRN on the IRP portal first, then remove it here.', 409);
}

db_query("UPDATE invoices SET status = 'Cancelled', cancel_reason = ?, cancelled_at = NOW(), balance_due = 0 WHERE invoice_id = ?", [$reason, $id]);
stats_touch_invoice($id); // the bill's day and the days of its payments
foreach (db_all("SELECT product_id, quantity FROM invoice_items WHERE invoice_id = ? AND product_id IS NOT NULL", [$id]) as $it) {
    move_stock((int) $it['product_id'], (float) $it['quantity'], 'cancel', 'invoice', $id, "Cancelled {$inv['invoice_number']}", (int) $user['user_id']);
}
return_recipe_stock($id, $inv['invoice_number'], (int) $user['user_id']); // V4: ingredients used by recipes
if ($inv['order_id']) {
    db_query("UPDATE orders SET status = 'Approved' WHERE order_id = ? AND status = 'Invoiced'", [$inv['order_id']]);
}
// V3: give back the coupon use and loyalty points of this bill.
if (!empty($inv['coupon_code'])) {
    foreach (db_all("SELECT redemption_id, coupon_id FROM coupon_redemptions WHERE invoice_id = ? AND status = 'Used'", [$id]) as $r) {
        db_query("UPDATE coupon_redemptions SET status = 'Reversed' WHERE redemption_id = ?", [$r['redemption_id']]);
        db_query('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE coupon_id = ?', [$r['coupon_id']]);
    }
}
if ($inv['customer_id'] && ((float) ($inv['loyalty_points_earned'] ?? 0) > 0 || (float) ($inv['loyalty_points_redeemed'] ?? 0) > 0)) {
    if ((float) $inv['loyalty_points_earned'] > 0) {
        loyalty_post((int) $inv['customer_id'], (int) $inv['store_id'], $id, 'reverse', -(float) $inv['loyalty_points_earned'], "Bill {$inv['invoice_number']} cancelled", (int) $user['user_id']);
    }
    if ((float) $inv['loyalty_points_redeemed'] > 0) {
        loyalty_post((int) $inv['customer_id'], (int) $inv['store_id'], $id, 'reverse', (float) $inv['loyalty_points_redeemed'], "Points returned: bill {$inv['invoice_number']} cancelled", (int) $user['user_id']);
    }
}
$pdo->commit();
log_activity('invoice.cancel', 'invoice', $id, "Cancelled bill {$inv['invoice_number']} " . rupees($inv['total_amount']) . " — $reason", ['reason' => $reason], $inv['store_id']);

json_out(["status" => "success", "message" => "Invoice {$inv['invoice_number']} cancelled. Stock has been restored."]);
