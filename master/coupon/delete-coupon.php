<?php
/** Coupons that were used are switched off instead of deleted, so bills keep their history. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'DELETE');
$user = require_auth();

$c = load_coupon($user, int_field(input(), 'coupon_id') ?? 0);
if (db_value('SELECT 1 FROM coupon_redemptions WHERE coupon_id = ? LIMIT 1', [$c['coupon_id']])) {
    db_query('UPDATE coupons SET is_active = 0 WHERE coupon_id = ?', [$c['coupon_id']]);
    log_activity('coupon.deactivate', 'coupon', $c['coupon_id'], "Switched off coupon {$c['code']}", [], $c['store_id']);
    json_out(['status' => 'success', 'message' => "Coupon {$c['code']} was used on bills, so it was switched off instead of deleted"]);
}
db_query('DELETE FROM coupons WHERE coupon_id = ?', [$c['coupon_id']]);
log_activity('coupon.delete', 'coupon', $c['coupon_id'], "Deleted coupon {$c['code']}", [], $c['store_id']);
json_out(['status' => 'success', 'message' => "Coupon {$c['code']} deleted"]);
