<?php
/** POST { store_id, email: {smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, from_email, from_name, reply_to}, sms: {provider, api_key, sender_id, custom_url, custom_method, custom_body, custom_headers, success_text} } */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST', 'PUT');
$user = require_auth();
$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
json_out(['status' => 'success', 'message' => 'Settings saved', 'data' => save_channel_settings($store, $d) + ['store_name' => $store['name']]]);
