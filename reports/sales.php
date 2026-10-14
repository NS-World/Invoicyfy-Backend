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
        $all = item_rollup(item_groups($from, $to, $scope), ['product_id', 'product_name']);
        usort($all, fn($a, $b) => $b['line_total'] <=> $a['line_total']);
        $rows = array_map(fn($g) => ['label' => $g['product_name'], 'invoices' => (int) $g['bills'], 'quantity' => round($g['qty'], 3),
            'taxable_value' => round($g['taxable'], 2), 'tax_amount' => round($g['tax'], 2), 'total_amount' => round($g['line_total'], 2),
            'cost' => round($g['cost'], 2)], array_slice($all, 0, $top));
        $totals = [];
        if ($rows) {
            foreach (['invoices' => 'bills', 'quantity' => 'qty', 'taxable_value' => 'taxable', 'tax_amount' => 'tax', 'total_amount' => 'line_total', 'cost' => 'cost'] as $k => $c) {
                $totals[$k] = round(array_sum(array_column($all, $c)), 2);
            }
        }
        json_out(["status" => "success", "range" => ["from" => $from, "to" => $to], "group" => $group, "totals" => $totals, "data" => $rows,
            "truncated" => count($rows) >= $top]);
    case 'customer':
        // Saved customers by id, walk-in bills by the name typed on them; totals of every customer.
        [$rows, $sum] = customer_totals($from, $to, $scope, $top);
        $rows = f2(array_map(fn($r) => array_diff_key($r, ['phone' => 1]), $rows), ['taxable_value', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due'], ['invoices']);
        $totals = $rows ? array_map(fn($v) => round((float) $v, 2), $sum) : [];
        json_out(["status" => "success", "range" => ["from" => $from, "to" => $to], "group" => $group, "totals" => $totals, "data" => $rows,
            "truncated" => count($rows) >= $top]);
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
