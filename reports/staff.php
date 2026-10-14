<?php
/**
 * Staff report: per cashier — bills, sales, discounts given, PIN approvals, cancelled bills and money collected
 * by mode; plus every cash register shift (day open → close) with the cash difference.
 * GET ?from=&to=&store_id=
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range();
$scope = scope_store($user, $_GET['store_id'] ?? null);
[$si, $sp] = store_filter($user, 'i');
$staff = [];
$get = function (int $uid, ?string $name) use (&$staff) {
    $staff[$uid] ??= ['user_id' => $uid, 'name' => $name ?: "User #$uid", 'bills' => 0, 'sales' => 0.0, 'avg_bill' => 0.0, 'discount' => 0.0,
        'coupon_discount' => 0.0, 'loyalty_discount' => 0.0, 'offer_discount' => 0.0, 'approvals' => 0, 'cancelled_bills' => 0,
        'cancelled_amount' => 0.0, 'collected' => 0.0, 'by_method' => []];
    return $uid;
};

$fast = stats_sync(); // day summaries per cashier (exact; kept up to date by every bill and payment)
if ($fast) {
    [$ss, $ssp] = stats_store($scope);
    $billRows = db_all(
        "SELECT s.user_id, u.name, SUM(s.bills) AS bills, SUM(s.sales) AS sales, SUM(s.discount) AS discount, SUM(s.coupon) AS coupon,
                SUM(s.loyalty) AS loyalty, SUM(s.offer) AS offer, SUM(s.approvals) AS approvals
         FROM stat_staff_daily s LEFT JOIN users u ON u.user_id = s.user_id
         WHERE s.day BETWEEN ? AND ?$ss GROUP BY s.user_id",
        array_merge([$from, $to], $ssp)
    );
} else {
    $v4 = v4_ready() ? ', COALESCE(SUM(i.offer_discount),0) AS offer' : ', 0 AS offer';
    $billRows = db_all(
        "SELECT i.user_id, u.name, COUNT(*) AS bills, SUM(i.total_amount) AS sales, SUM(i.discount_amount) AS discount,
                COALESCE(SUM(i.coupon_discount),0) AS coupon, COALESCE(SUM(i.loyalty_discount),0) AS loyalty$v4, SUM(i.approved_by IS NOT NULL) AS approvals
         FROM invoices i LEFT JOIN users u ON u.user_id = i.user_id
         WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si GROUP BY i.user_id",
        array_merge([$from, $to], $sp)
    );
}
foreach ($billRows as $r) {
    $id = $get((int) $r['user_id'], $r['name']);
    $staff[$id] = array_merge($staff[$id], [
        'bills' => (int) $r['bills'], 'sales' => money($r['sales']), 'discount' => money($r['discount']), 'coupon_discount' => money($r['coupon']),
        'loyalty_discount' => money($r['loyalty']), 'offer_discount' => money($r['offer']), 'approvals' => (int) $r['approvals'],
        'avg_bill' => (int) $r['bills'] ? money($r['sales'] / $r['bills']) : 0.0,
    ]);
}

// Cancelled: who pressed "Cancel" (from the activity log), in the period.
$aw = "a.action = 'invoice.cancel' AND a.created_at >= ? AND a.created_at < ? + INTERVAL 1 DAY" . ($scope !== null ? ' AND a.store_id = ?' : '');
foreach (db_all(
    "SELECT c.user_id, u.name AS user_name, c.n, c.amount
     FROM (SELECT a.user_id, COUNT(*) AS n, COALESCE(SUM(i.total_amount),0) AS amount
           FROM activity_log a LEFT JOIN invoices i ON i.invoice_id = a.entity_id WHERE $aw GROUP BY a.user_id) c
     LEFT JOIN users u ON u.user_id = c.user_id",
    array_merge([$from, $to], $scope !== null ? [$scope] : [])
) as $r) {
    $id = $get((int) $r['user_id'], $r['user_name']);
    $staff[$id]['cancelled_bills'] += (int) $r['n'];
    $staff[$id]['cancelled_amount'] = money($staff[$id]['cancelled_amount'] + (float) $r['amount']);
}

[$pf, $pp] = store_filter($user, 'p');
$payRows = $fast
    ? db_all(
        "SELECT s.user_id, u.name, s.method AS payment_method, SUM(s.amount) AS amount FROM stat_staff_pay_daily s LEFT JOIN users u ON u.user_id = s.user_id
         WHERE s.day BETWEEN ? AND ?$ss GROUP BY s.user_id, s.method",
        array_merge([$from, $to], $ssp)
    )
    : db_all(
        "SELECT p.user_id, u.name, p.payment_method, SUM(p.amount) AS amount, COUNT(*) AS n
         FROM payments p LEFT JOIN users u ON u.user_id = p.user_id
         WHERE p.payment_date BETWEEN ? AND ? $pf GROUP BY p.user_id, p.payment_method",
        array_merge([$from, $to], $pp)
    );
foreach ($payRows as $r) {
    $id = $get((int) $r['user_id'], $r['name']);
    $staff[$id]['by_method'][$r['payment_method']] = money($r['amount']);
    $staff[$id]['collected'] = money($staff[$id]['collected'] + (float) $r['amount']);
}
foreach ($staff as &$s) {
    arsort($s['by_method']);
}
unset($s);
usort($staff, fn($a, $b) => $b['sales'] <=> $a['sales']);

[$cf, $cp] = store_filter($user, 'c');
$shifts = array_map(fn($r) => cast_numbers($r, ['opening_cash', 'expected_cash', 'counted_cash', 'difference'], ['session_id', 'store_id']), db_all(
    "SELECT c.session_id, c.store_id, c.status, c.opened_at, c.closed_at, c.opening_cash, c.expected_cash, c.counted_cash, c.difference,
            o.name AS opened_by_name, cl.name AS closed_by_name, s.name AS store_name
     FROM cash_sessions c LEFT JOIN users o ON o.user_id = c.opened_by LEFT JOIN users cl ON cl.user_id = c.closed_by LEFT JOIN stores s ON s.store_id = c.store_id
     WHERE c.opened_at >= ? AND c.opened_at < ? + INTERVAL 1 DAY $cf ORDER BY c.opened_at DESC LIMIT 200",
    array_merge([$from, $to], $cp)
));
$short = array_sum(array_map(fn($s) => $s['difference'] !== null && $s['difference'] < 0 ? $s['difference'] : 0, $shifts));
json_out(['status' => 'success', 'range' => ['from' => $from, 'to' => $to], 'staff' => array_values($staff), 'shifts' => $shifts,
    'totals' => ['bills' => array_sum(array_column($staff, 'bills')), 'sales' => money(array_sum(array_column($staff, 'sales'))),
                 'discount' => money(array_sum(array_column($staff, 'discount'))), 'cancelled_bills' => array_sum(array_column($staff, 'cancelled_bills')),
                 'cash_short' => money($short)]]);
