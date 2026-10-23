<?php
/** POST {order_id} — test payment for a renewal (SAAS_PAYMENT_MODE=mock only). */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$t = current_tenant();
if (saas_payment_mode() !== 'mock') {
    fail('Not found', 404);
}
$orderId = (string) (input()['order_id'] ?? '');
$o = saas_q("SELECT tenant_id FROM saas_orders WHERE gateway_order_id = ? AND gateway = 'mock'", [$orderId])->fetch();
if (!$o || (int) $o['tenant_id'] !== (int) $t['tenant_id']) {
    fail('Payment order not found', 404);
}
$paymentId = 'pay_mock_' . bin2hex(random_bytes(7));
json_out(['status' => 'success', 'order_id' => $orderId, 'payment_id' => $paymentId, 'signature' => saas_payment_signature($orderId, $paymentId)]);
