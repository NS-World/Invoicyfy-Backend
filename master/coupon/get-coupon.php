<?php
/** GET ?coupon_id= — coupon with the bills it was used on. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

$c = load_coupon($user, int_field($_GET, 'coupon_id') ?? 0);
$c['customer_name'] = $c['customer_id'] ? db_value('SELECT name FROM customers WHERE customer_id = ?', [$c['customer_id']]) : null;
$uses = array_map(fn($r) => cast_numbers($r, ['discount', 'total_amount'], ['redemption_id', 'invoice_id', 'customer_id']), db_all(
    "SELECT r.redemption_id, r.invoice_id, r.customer_id, r.discount, r.status, r.created_at, i.invoice_number, i.total_amount,
            COALESCE(i.customer_name, cu.name) AS customer_name
     FROM coupon_redemptions r JOIN invoices i ON i.invoice_id = r.invoice_id LEFT JOIN customers cu ON cu.customer_id = r.customer_id
     WHERE r.coupon_id = ? ORDER BY r.redemption_id DESC LIMIT 200",
    [$c['coupon_id']]
));
$c['total_discount'] = money(array_sum(array_map(fn($u) => $u['status'] === 'Used' ? $u['discount'] : 0, $uses)));
json_out(['status' => 'success', 'data' => coupon_row($c) + ['redemptions' => $uses]]);
