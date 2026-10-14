<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range();
[$si, $sp] = store_filter($user, 'i');
[$se, $sep] = store_filter($user, 'e');
[$so, $sop] = store_filter($user, 'o');
[$spr, $sprp] = store_filter($user, 'pr');

$scope = scope_store($user, $_GET['store_id'] ?? null);
[$ss, $ssp] = stats_store($scope);
$S = sales_source();
$P = payments_source();
$live = "s.status <> 'Cancelled'";

// Day-wise summaries (lib/stats.php): a few hundred rows instead of every bill in the range.
$sales = cast_numbers(db_one(
    "SELECT COALESCE(SUM(s.bills),0) AS invoice_count, COALESCE(SUM(s.total),0) AS total_sales, COALESCE(SUM(s.taxable),0) AS net_sales,
            COALESCE(SUM(s.tax),0) AS tax_collected, COALESCE(SUM(s.balance),0) AS period_due, COALESCE(SUM(s.cogs),0) AS cogs
     FROM $S s WHERE $live AND s.day BETWEEN ? AND ?$ss",
    array_merge([$from, $to], $ssp)
), ['total_sales', 'net_sales', 'tax_collected', 'period_due', 'cogs'], ['invoice_count']);
$cogs = $sales['cogs'];

$collected = (float) db_value("SELECT COALESCE(SUM(s.live_amount),0) FROM $P s WHERE s.day BETWEEN ? AND ?$ss", array_merge([$from, $to], $ssp));
$expenses = cast_numbers(db_one(
    "SELECT COALESCE(SUM(e.amount),0) AS total, COALESCE(SUM(e.tax_amount),0) AS tax FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se",
    array_merge([$from, $to], $sep)
), ['total', 'tax']);
$outstanding = cast_numbers(db_one(
    "SELECT COALESCE(SUM(s.balance),0) AS total, COALESCE(SUM(s.bills),0) AS invoices FROM $S s WHERE s.status IN ('Unpaid','Partial')$ss",
    $ssp
), ['total'], ['invoices']);
// Overdue needs the due date: read from the (status, due_date) index, which only holds the few bills with one.
$outstanding['overdue'] = money(db_value(
    "SELECT COALESCE(SUM(i.balance_due),0) FROM invoices i WHERE i.status IN ('Unpaid','Partial') AND i.due_date < CURDATE() $si",
    $sp
));

$counts = [
    'customers' => (int) db_value("SELECT COUNT(*) FROM customers WHERE 1=1" . customer_active_sql()),
    'products' => (int) db_value("SELECT COUNT(*) FROM products pr WHERE pr.status = 'Active' $spr", $sprp),
    'open_orders' => (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.status IN ('Pending','Approved') $so", $sop),
    'low_stock' => (int) db_value("SELECT COUNT(*) FROM products pr WHERE pr.track_inventory = 1 AND pr.status = 'Active' AND pr.stock_qty <= pr.low_stock_threshold $spr", $sprp),
];

// Last 12 months ending with the "to" month.
$months = [];
$end = new DateTime(substr($to, 0, 7) . '-01');
for ($k = 11; $k >= 0; $k--) {
    $m = (clone $end)->modify("-$k month")->format('Y-m');
    $months[$m] = ['month' => $m, 'sales' => 0.0, 'collected' => 0.0, 'expenses' => 0.0];
}
$start12 = array_key_first($months) . '-01';
$end12 = (clone $end)->modify('last day of this month')->format('Y-m-d');
foreach (db_all("SELECT DATE_FORMAT(s.day,'%Y-%m') AS m, SUM(s.total) AS v FROM $S s WHERE $live AND s.day BETWEEN ? AND ?$ss GROUP BY m", array_merge([$start12, $end12], $ssp)) as $r) {
    $months[$r['m']]['sales'] = (float) $r['v'];
}
foreach (db_all("SELECT DATE_FORMAT(s.day,'%Y-%m') AS m, SUM(s.live_amount) AS v FROM $P s WHERE s.day BETWEEN ? AND ?$ss GROUP BY m", array_merge([$start12, $end12], $ssp)) as $r) {
    $months[$r['m']]['collected'] = (float) $r['v'];
}
foreach (db_all("SELECT DATE_FORMAT(e.expense_date,'%Y-%m') AS m, SUM(e.amount) AS v FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY m", array_merge([$start12, $end12], $sep)) as $r) {
    $months[$r['m']]['expenses'] = (float) $r['v'];
}

$daily = [];
if ((strtotime($to) - strtotime($from)) / 86400 <= 62) {
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        $daily[date('Y-m-d', $d)] = ['date' => date('Y-m-d', $d), 'sales' => 0.0, 'expenses' => 0.0];
    }
    foreach (db_all("SELECT s.day AS d, SUM(s.total) AS v FROM $S s WHERE $live AND s.day BETWEEN ? AND ?$ss GROUP BY d", array_merge([$from, $to], $ssp)) as $r) {
        $daily[$r['d']]['sales'] = (float) $r['v'];
    }
    foreach (db_all("SELECT e.expense_date AS d, SUM(e.amount) AS v FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY d", array_merge([$from, $to], $sep)) as $r) {
        $daily[$r['d']]['expenses'] = (float) $r['v'];
    }
}

$statusBreakdown = f2(db_all(
    "SELECT s.status, SUM(s.bills) AS count, SUM(s.total) AS amount FROM $S s WHERE s.day BETWEEN ? AND ?$ss GROUP BY s.status",
    array_merge([$from, $to], $ssp)
), ['amount'], ['count']);
[$items, $itemParams] = items_source($from, $to, $scope);
$topProducts = f2(db_all(
    "SELECT MAX(x.product_name) AS name, SUM(x.qty) AS quantity, SUM(x.line_total) AS revenue
     FROM $items GROUP BY x.product_id, x.product_name ORDER BY revenue DESC LIMIT 5",
    $itemParams
), ['quantity', 'revenue']);
$byMethod = f2(db_all(
    "SELECT s.method AS payment_method, SUM(s.live_amount) AS amount FROM $P s WHERE s.day BETWEEN ? AND ?$ss GROUP BY s.method HAVING amount <> 0 ORDER BY amount DESC",
    array_merge([$from, $to], $ssp)
), ['amount']);
$recent = array_map('doc_cast', db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_type, i.customer_name, i.invoice_date, i.total_amount, i.balance_due, i.status
     FROM invoices i WHERE 1=1 $si ORDER BY i.invoice_id DESC LIMIT 6",
    $sp
));
// Top customers of the period: read through the (date) index; only bills of the chosen range are touched.
$topCustomers = f2(db_all(
    "SELECT COALESCE(MAX(i.customer_name),'Walk-in') AS name, COUNT(*) AS invoices, SUM(i.total_amount) AS amount
     FROM invoices i WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si
     GROUP BY i.customer_id, i.customer_name ORDER BY amount DESC LIMIT 5",
    array_merge([$from, $to], $sp)
), ['amount'], ['invoices']);

$netExpenses = round($expenses['total'] - $expenses['tax'], 2);
json_out([
    "status" => "success",
    "range" => ["from" => $from, "to" => $to],
    "kpis" => [
        "total_sales" => $sales['total_sales'],
        "net_sales" => $sales['net_sales'],
        "tax_collected" => $sales['tax_collected'],
        "invoice_count" => $sales['invoice_count'],
        "average_bill" => $sales['invoice_count'] ? round($sales['total_sales'] / $sales['invoice_count'], 2) : 0,
        "collected" => round($collected, 2),
        "expenses" => $expenses['total'],
        "cost_of_goods" => round($cogs, 2),
        "gross_profit" => round($sales['net_sales'] - $cogs, 2),
        "net_profit" => round($sales['net_sales'] - $netExpenses, 2),
        "outstanding" => $outstanding['total'],
        "overdue" => $outstanding['overdue'],
        "due_invoices" => $outstanding['invoices'] ?? 0,
    ],
    "counts" => $counts,
    "monthly" => array_values($months),
    "daily" => array_values($daily),
    "status_breakdown" => $statusBreakdown,
    "top_products" => $topProducts,
    "top_customers" => $topCustomers,
    "payment_methods" => $byMethod,
    "recent_invoices" => $recent,
]);
