<?php
/**
 * Preview of a bill with coupon / loyalty points, calculated exactly as create-invoice will save it.
 * POST { store_id, customer_id, items: [...], coupon_code, redeem_points, round_off }
 * Returns totals, the applied coupon / points, points the bill will earn and whether a manager PIN is needed.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$store = get_store($storeId);
$party = resolve_party($d, $store);
$items = prepare_items($d['items'] ?? []);
$quote = quote_bill($store, $party, $items, [
    'coupon_code' => str_field($d, 'coupon_code'),
    'redeem_points' => num_field($d, 'redeem_points', 0),
    'round_off' => bool_field($d, 'round_off', true),
]);
$limit = role_discount_limit($user);

json_out(['status' => 'success', 'data' => [
    'totals' => $quote['totals'],
    'coupon' => $quote['coupon'],
    'loyalty' => $quote['loyalty'],
    'manual_discount_percent' => $quote['manual_discount_percent'],
    'discount_limit' => $limit,
    'approval_required' => $limit !== null && $quote['manual_discount_percent'] > $limit + 0.001,
    'lines' => array_map(fn($l) => pick($l, ['product_id', 'product_name', 'quantity', 'unit_price', 'discount_amount', 'taxable_value', 'tax_amount', 'line_total']), $quote['lines']),
]]);
