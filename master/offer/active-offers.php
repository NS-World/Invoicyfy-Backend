<?php
/** GET ?store_id= — offers running right now (shown on the billing screen). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

$storeId = write_store($user, $_GET['store_id'] ?? null);
json_out(['status' => 'success', 'data' => array_map(fn($o) => offer_out(db_one('SELECT * FROM offers WHERE offer_id = ?', [$o['offer_id']])), running_offers($storeId))]);
