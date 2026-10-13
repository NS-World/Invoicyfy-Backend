<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$c = load_coupon($user, int_field($d, 'coupon_id') ?? 0);
$p = coupon_payload($d);
if ($p['code'] !== $c['code']) {
    if ((int) $c['used_count'] > 0) {
        fail('This coupon has been used, so its code cannot be changed. Create a new coupon instead.', 409);
    }
    if (db_value('SELECT 1 FROM coupons WHERE store_id = ? AND code = ? AND coupon_id <> ?', [$c['store_id'], $p['code'], $c['coupon_id']])) {
        fail("Coupon {$p['code']} already exists", 409);
    }
}
update_row('coupons', 'coupon_id', (int) $c['coupon_id'], $p);
log_activity('coupon.update', 'coupon', $c['coupon_id'], "Updated coupon {$p['code']}" . ($p['is_active'] ? '' : ' (switched off)'), [], $c['store_id']);
json_out(['status' => 'success', 'message' => 'Coupon updated', 'data' => coupon_row(db_one('SELECT * FROM coupons WHERE coupon_id = ?', [$c['coupon_id']]))]);
