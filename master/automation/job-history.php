<?php
/** GET ?store_id= — automatic messages sent recently (birthday, reminders, summary, stock alerts). */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$storeId = write_store($user, $_GET['store_id'] ?? null);
$rows = db_all(
    "SELECT campaign_id, name, kind, channels, total, sent, failed, status, created_at FROM campaigns
     WHERE store_id = ? AND kind IN ('summary', 'stock', 'birthday', 'anniversary', 'reminder') ORDER BY campaign_id DESC LIMIT 50",
    [$storeId]
);
json_out(['status' => 'success', 'data' => array_map(fn($r) => cast_numbers($r, [], ['campaign_id', 'total', 'sent', 'failed']), $rows)]);
