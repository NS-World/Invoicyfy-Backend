<?php
/** GET ?store_id= — counter settings every biller needs (tables, day-open rule, UPI on screen). */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$store = get_store(write_store($user, $_GET['store_id'] ?? null));
$ls = loyalty_settings($store);
json_out(['status' => 'success', 'data' => pos_settings($store) + [
    'store_id' => (int) $store['store_id'],
    'upi_id' => $store['upi_id'],
    'payee_name' => $store['legal_name'] ?: $store['name'],
    'loyalty' => ['enabled' => (bool) $ls['enabled'], 'point_value' => $ls['point_value'], 'min_redeem_points' => $ls['min_redeem_points']],
    'discount_limit' => role_discount_limit($user),
]]);
