<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$o = load_offer($user, int_field($d, 'offer_id') ?? 0);
$p = offer_payload($d, (int) $o['store_id']);
update_row('offers', 'offer_id', (int) $o['offer_id'], $p);
log_activity('offer.update', 'offer', $o['offer_id'], "Updated offer {$p['name']}", [], $o['store_id']);
json_out(['status' => 'success', 'message' => 'Offer updated', 'data' => offer_out(db_one('SELECT * FROM offers WHERE offer_id = ?', [$o['offer_id']]))]);
