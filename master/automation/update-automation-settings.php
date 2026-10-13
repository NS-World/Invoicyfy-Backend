<?php
/** POST { store_id, owner_name, owner_phones, owner_emails, owner_channels, summary:{}, low_stock:{}, birthday:{}, reminders:{} } */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$s = clean_automation_settings($d, automation_settings($store));
db_query('UPDATE stores SET automation_settings = ? WHERE store_id = ?', [json_encode($s, JSON_UNESCAPED_UNICODE), $store['store_id']]);
$on = array_keys(array_filter(['summary' => $s['summary']['enabled'], 'low stock' => $s['low_stock']['enabled'], 'birthday' => $s['birthday']['enabled'], 'reminders' => $s['reminders']['enabled']]));
log_activity('settings.automation', 'store', $store['store_id'], 'Automatic messages: ' . ($on ? implode(', ', $on) . ' on' : 'all off'), [], $store['store_id']);
json_out(['status' => 'success', 'message' => 'Automatic messages saved', 'data' => $s]);
