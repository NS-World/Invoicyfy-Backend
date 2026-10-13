<?php
/** Quick check while billing: GET ?store_id=&code=&amount=&customer_id= -> discount or a clear reason. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$store = get_store(write_store($user, $_GET['store_id'] ?? null));
$amount = num_field($_GET, 'amount', 0);
[$c, $discount] = validate_coupon($store, (string) str_field($_GET, 'code', ''), int_field($_GET, 'customer_id'), $amount);
json_out(['status' => 'success', 'data' => [
    'code' => $c['code'], 'description' => $c['description'], 'discount_type' => $c['discount_type'],
    'value' => (float) $c['value'], 'discount' => $discount, 'min_bill' => (float) $c['min_bill'],
]]);
