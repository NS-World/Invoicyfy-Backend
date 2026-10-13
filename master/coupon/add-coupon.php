<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$p = coupon_payload($d);
if (db_value('SELECT 1 FROM coupons WHERE store_id = ? AND code = ?', [$storeId, $p['code']])) {
    fail("Coupon {$p['code']} already exists", 409);
}
$id = insert_row('coupons', $p + ['store_id' => $storeId, 'created_by' => (int) $user['user_id'], 'source' => 'manual']);
log_activity('coupon.create', 'coupon', $id, "Created coupon {$p['code']} (" . ($p['discount_type'] === 'percent' ? "{$p['value']}%" : rupees($p['value'])) . ')', [], $storeId);
json_out(['status' => 'success', 'message' => "Coupon {$p['code']} created", 'coupon_id' => $id, 'data' => coupon_row(db_one('SELECT * FROM coupons WHERE coupon_id = ?', [$id]))], 201);
