<?php
/**
 * POST {plan, cycle: year|month, stores, trial: bool, business_name, owner_name, email, phone, city, gstin, shop_code}
 * Free trial: the shop is set up now and the login details come back (and by email).
 * Paid: a payment order is created; the checkout screen pays it and calls verify-payment.php.
 */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
saas_rate_limit('signup', (int) env('SAAS_SIGNUP_LIMIT', 20));
$d = input();
$plan = saas_plan((string) ($d['plan'] ?? ''));
if (!$plan || !$plan['is_active']) {
    fail('Choose a plan');
}
$signup = saas_signup_details($d);
if (!empty($d['trial'])) {
    if ((int) env('SAAS_TRIAL_DAYS', 14) <= 0) {
        fail('Free trial is not available right now');
    }
    if (saas_q('SELECT 1 FROM saas_tenants WHERE email = ? AND created_at > NOW() - INTERVAL 30 DAY', [$signup['email']])->fetch()) {
        fail('A shop was already started with this email. Log in with it, or choose a paid plan.', 409, ['code' => 'trial_used']);
    }
    [$tenant, $creds] = saas_provision($signup, $plan, 'trial', 'year', 1);
    saas_send_welcome($tenant, $creds);
    json_out(['status' => 'success', 'message' => 'Your shop is ready', 'credentials' => $creds]);
}
$order = saas_create_order('signup', $plan, (string) ($d['cycle'] ?? 'year'), (int) ($d['stores'] ?? 1), null, $signup);
json_out(['status' => 'success', 'message' => 'Payment started', 'order' => $order, 'shop_code' => $signup['slug']]);
