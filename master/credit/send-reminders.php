<?php
/**
 * Payment reminders now: POST { store_id, customer_ids: [..] | all: true, channels: [whatsapp, sms, email], message? }.
 * {name}, {due}, {bills}, {oldest} and {shop} are filled per customer. WhatsApp needs the Business API here;
 * "from my WhatsApp" reminders are sent one by one from the screen.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/automation.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$auto = automation_settings($store);
$wanted = setting_channels($d['channels'] ?? $auto['reminders']['channels']);
$channels = automatic_channels($store, $wanted);
if (!$channels) {
    fail('None of the chosen channels is set up for automatic sending. Set up WhatsApp API, SMS or Email in Offers > Channels setup.', 422);
}
$message = setting_text($d['message'] ?? $auto['reminders']['message'], 1000, 'Message', true);
$ids = bool_field($d, 'all') ? null : array_values(array_filter(array_map('intval', (array) ($d['customer_ids'] ?? []))));
if ($ids !== null && !$ids) {
    fail('Choose at least one customer');
}
$dues = customers_with_dues((int) $store['store_id'], 0.01, 0, $ids);
if (!$dues) {
    fail('No pending dues for the chosen customers', 409);
}
[$cid, $people, $n] = send_due_reminders($store, $dues, $channels, $message, (int) $user['user_id']);
if (!$n) {
    fail('The chosen customers have no mobile number / email for these channels', 422);
}
log_activity('credit.reminder', 'campaign', $cid, "Payment reminders to $people customer(s), $n message(s)", [], $store['store_id']);
json_out(['status' => 'success', 'message' => "Reminders sending to $people customer(s) ($n messages)", 'campaign_id' => $cid]);
