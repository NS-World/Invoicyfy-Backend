<?php
/** POST { store_id, phone, message } — send one test offer through the API to check the setup. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('POST');
$user = require_auth();
$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$to = wa_phone(str_field($d, 'phone')) ?: fail('Enter a valid mobile number to test');
$message = str_field($d, 'message') ?: 'This is a test offer message from ' . $store['name'];
[$ok, $id, $err] = wa_send_template($store, $to, [$user['name'], wa_personalise($message, $user['name'], $store['name'])]);
if (!$ok) {
    fail($err);
}
json_out(['status' => 'success', 'message' => "Test message sent to +$to", 'message_id' => $id]);
