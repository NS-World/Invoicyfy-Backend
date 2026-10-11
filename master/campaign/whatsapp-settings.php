<?php
/** WhatsApp sending setup for a store (the access token itself is never returned). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('GET');
$user = require_auth();
$storeId = write_store($user, $_GET['store_id'] ?? null);
json_out(['status' => 'success', 'data' => wa_settings(get_store($storeId))]);
