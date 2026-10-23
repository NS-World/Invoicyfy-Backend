<?php
/**
 * POST {order_id, payment_id, signature} — after the checkout says paid. The signature is checked with the gateway
 * secret; then the shop is set up (sign-up) or the plan extended (renewal). A new shop's login details come back once.
 */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
saas_rate_limit('verify', 60, 10);
$d = input();
$orderId = (string) ($d['order_id'] ?? '');
$paymentId = (string) ($d['payment_id'] ?? '');
if (!saas_verify_payment_signature($orderId, $paymentId, (string) ($d['signature'] ?? ''))) {
    saas_event(null, 'payment_bad_signature', "order $orderId payment $paymentId");
    fail('Payment could not be verified. If money was debited, it is refunded automatically or contact support with this payment id: ' . $paymentId, 400);
}
[$order, $tenant] = saas_complete_order($orderId, $paymentId);
$fresh = saas_q('SELECT * FROM saas_orders WHERE order_id = ?', [$order['order_id']])->fetch();
json_out(['status' => 'success', 'message' => 'Payment received', 'kind' => $fresh['kind'],
          'credentials' => $fresh['kind'] === 'signup' ? saas_reveal_credentials($fresh) : null,
          'shop' => saas_tenant_public($tenant)]);
