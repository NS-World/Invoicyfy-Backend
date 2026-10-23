<?php
/**
 * GET ?order_id=&email= — has this sign-up been paid? (The payment page was closed before it finished, or the
 * webhook got there first.) Login details are given once, only with the email of the sign-up.
 */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
saas_rate_limit('order-status', 60, 10);
$o = saas_q('SELECT * FROM saas_orders WHERE gateway_order_id = ?', [(string) ($_GET['order_id'] ?? '')])->fetch();
if (!$o) {
    fail('Payment order not found', 404);
}
$signup = json_decode((string) $o['signup'], true) ?: [];
$emailOk = strtolower(trim((string) ($_GET['email'] ?? ''))) === ($signup['email'] ?? '#');
json_out(['status' => 'success', 'paid' => $o['status'] === 'paid', 'kind' => $o['kind'],
          'credentials' => $o['status'] === 'paid' && $emailOk ? saas_reveal_credentials($o) : null]);
