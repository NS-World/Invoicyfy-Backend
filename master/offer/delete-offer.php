<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'DELETE');
$user = require_auth();

$o = load_offer($user, int_field(input(), 'offer_id') ?? 0);
db_query('DELETE FROM offers WHERE offer_id = ?', [$o['offer_id']]);
log_activity('offer.delete', 'offer', $o['offer_id'], "Deleted offer {$o['name']}", [], $o['store_id']);
json_out(['status' => 'success', 'message' => "Offer {$o['name']} deleted (bills keep their discount)"]);
