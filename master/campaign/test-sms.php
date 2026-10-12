<?php
/** POST { store_id, phone } — send one test SMS with the saved gateway settings. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST');
$user = require_auth();
$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$to = wa_phone(str_field($d, 'phone')) ?: fail('Enter a valid mobile number to test');
[$ok, , $err] = sms_send($store, $to, 'Test SMS from ' . $store['name'] . '. Your SMS setup works.');
$ok || fail($err);
json_out(['status' => 'success', 'message' => "Test SMS sent to +$to"]);
