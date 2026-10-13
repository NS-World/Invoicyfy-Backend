<?php
/** Sales register grouped by day / month / product / customer. */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range();
$scope = scope_store($user, $_GET['store_id'] ?? null);
[$ss, $ssp] = stats_store($scope);
$group = $_GET['group'] ?? 'day';
$params = array_merge([$from, $to], $ssp);
$S = sales_source();
$base = "s.status <> 'Cancelled' AND s.day BETWEEN ? AND ?$ss";
// Product / customer lists can be very long: the screen gets the biggest ones (?limit=, default 1000, max 5000).
$top = max(1, min(5000, (int) ($_GET['limit'] ?? 1000)));
$totalsSql = null;

switch ($group) {
    case 'month':
        $sql = "SELECT DATE_FORMAT(s.day,'%Y-%m') AS label, SUM(s.bills) AS invoices, SUM(s.taxable) AS taxable_value,
                       SUM(s.tax) AS tax_amount, SUM(s.total) AS total_amount, SUM(s.paid) AS amount_paid, SUM(s.balance) AS balance_due
                FROM $S s WHERE $base GROUP BY label ORDER BY label";
        break;
    case 'product':
        [$items, $params] = items_source($from, $to, $scope);
        $sql = "SELECT MAX(x.product_name) AS label, SUM(x.bills) AS invoices, SUM(x.qty) AS quantity,
                       SUM(x.taxable) AS taxable_value, SUM(x.tax) AS tax_amount, SUM(x.line_total) AS total_amount, SUM(x.cost) AS cost
                FROM $items GROUP BY x.product_id, x.product_name ORDER BY total_amount DESC LIMIT $top";
        $totalsSql = "SELECT SUM(x.bills) AS invoices, SUM(x.qty) AS quantity, SUM(x.taxable) AS taxable_value, SUM(x.tax) AS tax_amount,
                             SUM(x.line_total) AS total_amount, SUM(x.cost) AS cost FROM $items";
        break;
    case 'customer':
        [$cust, $params] = customers_source($from, $to, $scope);
        // Saved customers are grouped by id (a small number), walk-in bills by the name typed on them.
        $cols = "SUM(x.bills) AS invoices, SUM(x.taxable) AS taxable_value, SUM(x.tax) AS tax_amount, SUM(x.total) AS total_amount,
                 SUM(x.paid) AS amount_paid, SUM(x.balance) AS balance_due";
        $sql = "(SELECT MAX(x.customer_name) AS label, MAX(x.customer_gstin) AS gstin, $cols FROM $cust WHERE x.customer_id <> 0 GROUP BY x.customer_id)
                UNION ALL
                (SELECT COALESCE(x.customer_name,'Walk-in') AS label, MAX(x.customer_gstin) AS gstin, $cols FROM $cust WHERE x.customer_id = 0 GROUP BY x.customer_name)
                ORDER BY total_amount DESC LIMIT $top";
        $params = array_merge($params, $params);
        // Totals of every customer = all live bills of the range (day summaries).
        $totalsSql = "SELECT SUM(s.bills) AS invoices, SUM(s.taxable) AS taxable_value, SUM(s.tax) AS tax_amount,
                             SUM(s.total) AS total_amount, SUM(s.paid) AS amount_paid, SUM(s.balance) AS balance_due FROM $S s WHERE $base";
        $totalsParams = array_merge([$from, $to], $ssp);
        break;
    default:
        $group = 'day';
        $sql = "SELECT s.day AS label, SUM(s.bills) AS invoices, SUM(s.taxable) AS taxable_value, SUM(s.tax) AS tax_amount,
                       SUM(s.total) AS total_amount, SUM(s.paid) AS amount_paid, SUM(s.balance) AS balance_due
                FROM $S s WHERE $base GROUP BY label ORDER BY label";
}
$rows = f2(db_all($sql, $params), ['taxable_value', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due', 'quantity', 'cost'], ['invoices']);
$totals = [];
if ($totalsSql) {
    // Totals of every row, not only the ones listed.
    foreach (db_one($totalsSql, $totalsParams ?? $params) ?? [] as $k => $v) {
        if ($rows) {
            $totals[$k] = round((float) $v, 2);
        }
    }
} else {
    foreach (['invoices', 'quantity', 'taxable_value', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due', 'cost'] as $k) {
        if ($rows && array_key_exists($k, $rows[0])) {
            $totals[$k] = round(array_sum(array_column($rows, $k)), 2);
        }
    }
}
json_out(["status" => "success", "range" => ["from" => $from, "to" => $to], "group" => $group, "totals" => $totals, "data" => $rows,
    "truncated" => $totalsSql !== null && count($rows) >= $top]);
