<?php
/** POST {order_id} — test payment (SAAS_PAYMENT_MODE=mock only): answers like the gateway's checkout would. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
if (saas_payment_mode() !== 'mock') {
    fail('Not found', 404);
}
$orderId = (string) (input()['order_id'] ?? '');
if (!saas_q("SELECT 1 FROM saas_orders WHERE gateway_order_id = ? AND gateway = 'mock'", [$orderId])->fetch()) {
    fail('Payment order not found', 404);
}
$paymentId = 'pay_mock_' . bin2hex(random_bytes(7));
json_out(['status' => 'success', 'order_id' => $orderId, 'payment_id' => $paymentId, 'signature' => saas_payment_signature($orderId, $paymentId)]);
