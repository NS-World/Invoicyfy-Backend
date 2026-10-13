<?php
/** GET ?store_id= — loyalty rules + how many points customers hold (and what they are worth). */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$store = get_store(write_store($user, $_GET['store_id'] ?? null));
$ls = loyalty_settings($store);
$stats = db_one('SELECT COUNT(*) AS members, COALESCE(SUM(loyalty_points),0) AS points FROM customers WHERE loyalty_points > 0');
$month = db_one(
    "SELECT COALESCE(SUM(CASE WHEN type = 'earn' THEN points END),0) AS earned, COALESCE(-SUM(CASE WHEN type = 'redeem' THEN points END),0) AS redeemed
     FROM loyalty_ledger WHERE store_id = ? AND created_at >= ?",
    [$store['store_id'], date('Y-m-01')]
);
json_out(['status' => 'success', 'data' => [
    'settings' => $ls,
    'store_id' => (int) $store['store_id'],
    'stats' => [
        'members' => (int) $stats['members'],
        'points' => round((float) $stats['points'], 2),
        'value' => money((float) $stats['points'] * (float) $ls['point_value']),
        'earned_this_month' => round((float) $month['earned'], 2),
        'redeemed_this_month' => round((float) $month['redeemed'], 2),
    ],
]]);
