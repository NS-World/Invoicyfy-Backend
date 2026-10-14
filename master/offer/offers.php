<?php
/** GET ?store_id= — combo and happy-hour offers of a store. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

$storeId = write_store($user, $_GET['store_id'] ?? null);
$rows = db_all('SELECT * FROM offers WHERE store_id = ? ORDER BY is_active DESC, offer_id DESC LIMIT 200', [$storeId]);
json_out(['status' => 'success', 'total' => count($rows), 'data' => array_map('offer_out', $rows)]);
