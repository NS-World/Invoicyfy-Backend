<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$p = offer_payload($d, $storeId);
$id = insert_row('offers', $p + ['store_id' => $storeId, 'created_by' => (int) $user['user_id']]);
log_activity('offer.create', 'offer', $id, "Created offer {$p['name']}", [], $storeId);
json_out(['status' => 'success', 'message' => "Offer {$p['name']} created", 'offer_id' => $id, 'data' => offer_out(db_one('SELECT * FROM offers WHERE offer_id = ?', [$id]))], 201);
