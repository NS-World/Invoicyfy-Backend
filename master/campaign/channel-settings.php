<?php
/** WhatsApp, Email (SMTP) and SMS settings for a store. Passwords / keys are never returned. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('GET');
$user = require_auth();
$store = get_store(write_store($user, $_GET['store_id'] ?? null));
json_out(['status' => 'success', 'data' => channel_settings_public($store) + ['store_name' => $store['name'], 'store_id' => (int) $store['store_id']]]);
