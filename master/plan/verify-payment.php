<?php
/** POST {order_id, payment_id, signature} — checks the renewal payment and extends the plan. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$t = current_tenant();
$d = input();
$orderId = (string) ($d['order_id'] ?? '');
$paymentId = (string) ($d['payment_id'] ?? '');
$o = saas_q('SELECT tenant_id FROM saas_orders WHERE gateway_order_id = ?', [$orderId])->fetch();
if (!$o || (int) $o['tenant_id'] !== (int) $t['tenant_id']) {
    fail('Payment order not found', 404);
}
if (!saas_verify_payment_signature($orderId, $paymentId, (string) ($d['signature'] ?? ''))) {
    fail('Payment could not be verified. If money was debited, contact support with payment id ' . $paymentId);
}
[, $tenant] = saas_complete_order($orderId, $paymentId);
log_activity('plan.renew', null, null, 'Khanak plan paid: ' . $tenant['plan_code'] . ' till ' . $tenant['current_period_end'], [], null);
json_out(['status' => 'success', 'message' => 'Payment received. Your plan is renewed.', 'data' => saas_tenant_public($tenant)]);
