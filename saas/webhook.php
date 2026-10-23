<?php
/**
 * POST — Razorpay webhook (payment.captured / order.paid). Checked with SAAS_RAZORPAY_WEBHOOK_SECRET.
 * Completes the order even when the customer closed the payment page; safe to receive more than once.
 */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$raw = (string) file_get_contents('php://input');
$secret = (string) env('SAAS_RAZORPAY_WEBHOOK_SECRET', '');
$sig = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    saas_event(null, 'webhook_bad_signature', substr($raw, 0, 200));
    json_out(['status' => 'error', 'message' => 'Invalid signature'], 400);
}
$e = json_decode($raw, true) ?: [];
$event = (string) ($e['event'] ?? '');
$payment = $e['payload']['payment']['entity'] ?? [];
$orderId = (string) ($payment['order_id'] ?? ($e['payload']['order']['entity']['id'] ?? ''));
if (in_array($event, ['payment.captured', 'order.paid'], true) && $orderId !== '' && !empty($payment['id'])) {
    try {
        saas_complete_order($orderId, (string) $payment['id']);
    } catch (ApiException $ex) {
        saas_event(null, 'webhook_error', "$event $orderId: " . $ex->getMessage());
    }
} elseif ($event === 'payment.failed' && $orderId !== '') {
    saas_q("UPDATE saas_orders SET error = ? WHERE gateway_order_id = ? AND status = 'created'",
        [mb_substr((string) ($payment['error_description'] ?? 'Payment failed'), 0, 250), $orderId]);
}
json_out(['status' => 'success']);
