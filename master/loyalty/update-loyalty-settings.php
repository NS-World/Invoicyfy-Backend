<?php
/** POST { store_id, enabled, earn_per_100, point_value, min_redeem_points, max_redeem_percent, min_bill_to_earn } */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$s = clean_loyalty_settings($d, loyalty_settings($store));
db_query('UPDATE stores SET loyalty_settings = ? WHERE store_id = ?', [json_encode($s), $store['store_id']]);
log_activity('settings.loyalty', 'store', $store['store_id'], 'Loyalty rules: ' . ($s['enabled'] ? "on, {$s['earn_per_100']} pts per ₹100, 1 pt = ₹{$s['point_value']}" : 'off'), [], $store['store_id']);
json_out(['status' => 'success', 'message' => 'Loyalty settings saved', 'data' => $s]);
