<?php
/** POST { store_id, require_day_open, table_count, upi_qr_on_screen } */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$s = clean_pos_settings($d, pos_settings($store));
db_query('UPDATE stores SET pos_settings = ? WHERE store_id = ?', [json_encode($s), $store['store_id']]);
log_activity('settings.pos', 'store', $store['store_id'], 'Counter settings changed: ' . json_encode($s), [], $store['store_id']);
json_out(['status' => 'success', 'message' => 'Counter settings saved', 'data' => $s]);
