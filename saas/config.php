<?php
/** GET — what the sign-up and pricing screens need to know (is SaaS on, payment mode, trial days, GST). */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
json_out(['status' => 'success', 'data' => [
    'saas' => true,
    'payment_mode' => saas_payment_mode(),
    'razorpay_key_id' => saas_payment_mode() === 'razorpay' ? (string) env('SAAS_RAZORPAY_KEY_ID', '') : null,
    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),
    'grace_days' => (int) env('SAAS_GRACE_DAYS', 7),
    'gst_rate' => saas_gst_rate(),
    'seller' => (string) env('SAAS_SELLER_NAME', 'Khanak'),
    'support' => ['phone' => (string) env('SAAS_SUPPORT_PHONE', ''), 'email' => (string) env('SAAS_SUPPORT_EMAIL', '')],
]]);
