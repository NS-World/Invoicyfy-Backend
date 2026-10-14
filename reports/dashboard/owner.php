<?php
/**
 * Owner's phone dashboard: today at a glance. GET ?store_id=
 * Today vs yesterday vs same day last week, month so far vs last month, money by mode, cash in the drawer,
 * sales by hour, top items, and what needs attention (dues, low stock, expiring stock, supplier dues, platform payouts).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('GET');
$user = require_auth();

$scope = scope_store($user, $_GET['store_id'] ?? null);
[$si, $sp] = store_filter($user, 'i');
$today = date('Y-m-d');
$day = function (string $date) use ($si, $sp) {
    $r = db_one("SELECT COUNT(*) AS bills, COALESCE(SUM(i.total_amount),0) AS sales FROM invoices i WHERE i.invoice_date = ? AND i.status <> 'Cancelled' $si", array_merge([$date], $sp));
    return ['date' => $date, 'bills' => (int) $r['bills'], 'sales' => money($r['sales']), 'avg_bill' => (int) $r['bills'] ? money($r['sales'] / $r['bills']) : 0.0];
};
$todayRow = $day($today);
$profit = db_one(
    "SELECT COALESCE(SUM(ii.taxable_value - ii.quantity * ii.cost_price),0) AS profit FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE i.invoice_date = ? AND i.status <> 'Cancelled' $si",
    array_merge([$today], $sp)
);
$todayRow['gross_profit'] = money($profit['profit']);

// Month so far vs the same days of last month (summary tables when ready).
$S = sales_source();
[$ss, $ssp] = stats_store($scope);
$mFrom = date('Y-m-01');
$lmFrom = date('Y-m-01', strtotime('first day of last month'));
$lmTo = date('Y-m-d', min(strtotime(date('Y-m-t', strtotime($lmFrom))), strtotime($lmFrom . ' +' . ((int) date('j') - 1) . ' days')));
$range = fn($f, $t) => money(db_value("SELECT COALESCE(SUM(s.total),0) FROM $S s WHERE s.status <> 'Cancelled' AND s.day BETWEEN ? AND ?$ss", array_merge([$f, $t], $ssp)));

[$pf, $pp] = store_filter($user, 'p');
$byMethod = array_map(fn($r) => ['method' => $r['payment_method'], 'amount' => money($r['amount']), 'count' => (int) $r['n']], db_all(
    "SELECT p.payment_method, SUM(p.amount) AS amount, COUNT(*) AS n FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
     WHERE p.payment_date = ? AND (i.status IS NULL OR i.status <> 'Cancelled') $pf GROUP BY p.payment_method ORDER BY amount DESC",
    array_merge([$today], $pp)
));

$hourly = array_fill(0, 24, 0.0);
foreach (db_all("SELECT HOUR(i.created_at) AS h, SUM(i.total_amount) AS t FROM invoices i WHERE i.invoice_date = ? AND i.status <> 'Cancelled' $si GROUP BY HOUR(i.created_at)", array_merge([$today], $sp)) as $r) {
    $hourly[(int) $r['h']] = money($r['t']);
}

$top = array_map(fn($r) => cast_numbers($r, ['qty', 'amount']), db_all(
    "SELECT ii.product_name AS name, ii.unit, SUM(ii.quantity) AS qty, SUM(ii.line_total) AS amount
     FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE i.invoice_date = ? AND i.status <> 'Cancelled' $si GROUP BY ii.product_name, ii.unit ORDER BY amount DESC LIMIT 5",
    array_merge([$today], $sp)
));

$drawer = [];
$sessions = $scope === null ? db_all("SELECT * FROM cash_sessions WHERE status = 'Open'") : db_all("SELECT * FROM cash_sessions WHERE status = 'Open' AND store_id = ?", [$scope]);
foreach ($sessions as $s) {
    $rep = cash_session_report($s);
    $drawer[] = ['session_id' => (int) $s['session_id'], 'store_id' => (int) $s['store_id'], 'opened_at' => $s['opened_at'], 'expected_cash' => $rep['expected_cash']];
}

$dues = db_one(
    "SELECT COUNT(DISTINCT i.customer_id) AS customers, COALESCE(SUM(i.balance_due),0) AS amount FROM invoices i
     WHERE i.status IN ('Unpaid','Partial') AND i.customer_id IS NOT NULL $si",
    $sp
);
$lowStock = (int) db_value(
    "SELECT COUNT(*) FROM products WHERE status = 'Active' AND track_inventory = 1 AND stock_qty <= low_stock_threshold" . ($scope !== null ? ' AND (store_id = ? OR store_id IS NULL)' : ''),
    $scope !== null ? [$scope] : []
);
$attention = ['dues' => ['customers' => (int) $dues['customers'], 'amount' => money($dues['amount'])], 'low_stock' => $lowStock];
if (v4_ready()) {
    $days = $scope !== null ? (int) pos_settings(get_store($scope))['expiry_alert_days'] : 3;
    $exp = expiring_batches($scope, $days, 500);
    $attention['expiring'] = ['batches' => count($exp), 'value' => money(array_sum(array_column($exp, 'value'))), 'days' => $days];
    $owed = db_one(
        'SELECT (SELECT COALESCE(SUM(opening_balance),0) FROM suppliers' . ($scope !== null ? ' WHERE store_id = ?' : '') . ')
              + (SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE status <> \'Cancelled\'' . ($scope !== null ? ' AND store_id = ?' : '') . ')
              - (SELECT COALESCE(SUM(amount),0) FROM supplier_payments' . ($scope !== null ? ' WHERE store_id = ?' : '') . ') AS owed',
        $scope !== null ? [$scope, $scope, $scope] : []
    );
    $attention['supplier_dues'] = money($owed['owed']);
    $pending = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(i.total_amount - i.channel_commission),0) AS amount FROM invoices i
         WHERE i.sales_channel IS NOT NULL AND i.settled_at IS NULL AND i.status <> 'Cancelled' AND i.invoice_date >= ? $si",
        array_merge([date('Y-m-d', strtotime('-60 days'))], $sp)
    );
    $attention['online_pending'] = ['orders' => (int) $pending['n'], 'amount' => money($pending['amount'])];
}

json_out(['status' => 'success', 'generated_at' => date('Y-m-d H:i:s'), 'data' => [
    'today' => $todayRow,
    'yesterday' => $day(date('Y-m-d', strtotime('-1 day'))),
    'last_week' => $day(date('Y-m-d', strtotime('-7 days'))),
    'month' => ['sales' => $range($mFrom, $today), 'last_month_same_days' => $range($lmFrom, $lmTo), 'from' => $mFrom, 'last_from' => $lmFrom, 'last_to' => $lmTo],
    'by_method' => $byMethod,
    'cash_in_drawer' => $drawer,
    'hourly' => $hourly,
    'top_items' => $top,
    'attention' => $attention,
]]);
