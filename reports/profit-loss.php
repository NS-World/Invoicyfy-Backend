<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range(date('Y') . '-' . (date('n') >= 4 ? '04-01' : '01-01'));
[$se, $sep] = store_filter($user, 'e');
[$ss, $ssp] = stats_store(scope_store($user, $_GET['store_id'] ?? null));
$S = sales_source();

$rows = [];
$cursor = new DateTime(substr($from, 0, 7) . '-01');
$last = new DateTime(substr($to, 0, 7) . '-01');
while ($cursor <= $last) {
    $m = $cursor->format('Y-m');
    $rows[$m] = ['month' => $m, 'sales' => 0.0, 'net_sales' => 0.0, 'tax' => 0.0, 'cost_of_goods' => 0.0, 'expenses' => 0.0, 'expense_tax' => 0.0];
    $cursor->modify('+1 month');
}
foreach (db_all(
    "SELECT DATE_FORMAT(s.day,'%Y-%m') AS m, SUM(s.total) AS sales, SUM(s.taxable) AS net_sales, SUM(s.tax) AS tax, SUM(s.cogs) AS cogs
     FROM $S s WHERE s.status <> 'Cancelled' AND s.day BETWEEN ? AND ?$ss GROUP BY m",
    array_merge([$from, $to], $ssp)
) as $r) {
    $rows[$r['m']]['sales'] = (float) $r['sales'];
    $rows[$r['m']]['net_sales'] = (float) $r['net_sales'];
    $rows[$r['m']]['tax'] = (float) $r['tax'];
    $rows[$r['m']]['cost_of_goods'] = round((float) $r['cogs'], 2);
}
foreach (db_all(
    "SELECT DATE_FORMAT(e.expense_date,'%Y-%m') AS m, SUM(e.amount) AS amount, SUM(e.tax_amount) AS tax
     FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY m",
    array_merge([$from, $to], $sep)
) as $r) {
    $rows[$r['m']]['expenses'] = (float) $r['amount'];
    $rows[$r['m']]['expense_tax'] = (float) $r['tax'];
}
foreach ($rows as &$r) {
    $r['profit'] = round($r['net_sales'] - ($r['expenses'] - $r['expense_tax']), 2);
}
unset($r);

$byCategory = f2(db_all(
    "SELECT e.category, SUM(e.amount) AS amount FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY e.category ORDER BY amount DESC",
    array_merge([$from, $to], $sep)
), ['amount']);

$sum = fn($k) => round(array_sum(array_column($rows, $k)), 2);
$totals = [
    'sales' => $sum('sales'), 'net_sales' => $sum('net_sales'), 'tax' => $sum('tax'), 'cost_of_goods' => $sum('cost_of_goods'),
    'expenses' => $sum('expenses'), 'expense_tax' => $sum('expense_tax'), 'profit' => $sum('profit'),
];
$totals['gross_profit'] = round($totals['net_sales'] - $totals['cost_of_goods'], 2);
$totals['margin_percent'] = $totals['net_sales'] > 0 ? round($totals['profit'] * 100 / $totals['net_sales'], 1) : 0;

json_out([
    "status" => "success",
    "range" => ["from" => $from, "to" => $to],
    "totals" => $totals,
    "monthly" => array_values($rows),
    "expenses_by_category" => $byCategory,
]);
