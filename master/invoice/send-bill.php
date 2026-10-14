<?php
/**
 * Send the bill link to the customer now. POST { invoice_id, channels?: ["whatsapp","sms","email"], phone?, email? }
 * Always returns the link and a ready text, so the screen can also open WhatsApp on this phone (no API needed).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$inv = load_invoice(int_field($d, 'invoice_id') ?? 0);
if (!$inv) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $inv['store_id']);
if ($phone = str_field($d, 'phone')) {
    $inv['customer_phone'] = $phone;
}
if ($email = str_field($d, 'email')) {
    $inv['customer_email'] = $email;
}
$store = get_store((int) $inv['store_id']);
$pos = pos_settings($store);
$vars = bill_vars($inv);
$text = strtr(wa_personalise($pos['bill_message'], (string) ($inv['customer_name'] ?? ''), (string) $store['name']), array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_values($vars)));
$queued = null;
if (!empty($d['channels'])) {
    $channels = setting_channels($d['channels']);
    $queued = queue_bill_message($store, $inv, $channels, $pos['bill_message'], (int) $user['user_id']);
    if ($queued) {
        $cid = $queued;
        after_response(fn() => process_campaign($cid, 5, microtime(true) + 20));
    }
}
json_out(['status' => 'success', 'message' => $queued ? 'Bill is being sent' : 'Bill link ready', 'queued' => (bool) $queued,
    'link' => $vars['link'], 'text' => $text, 'phone' => wa_phone($inv['customer_phone'] ?? null)]);
