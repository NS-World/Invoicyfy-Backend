<?php
/** GET ?store_id=&days= — batches expiring soon (default: the counter setting "expiry alert days"). */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$storeId = write_store($user, $_GET['store_id'] ?? null);
$days = isset($_GET['days']) ? max(0, min(365, (int) $_GET['days'])) : (int) pos_settings(get_store($storeId))['expiry_alert_days'];
$rows = expiring_batches($storeId, $days);
json_out(['status' => 'success', 'days' => $days, 'total' => count($rows), 'value' => round(array_sum(array_column($rows, 'value')), 2), 'data' => $rows]);
