<?php
/**
 * Annual (financial year) business report: GET ?fy=2026 (FY starting April 2026; default = current FY).
 * Month-wise sales, tax, collections, expenses and profit, plus top products/customers, payment modes,
 * GST rate summary and a comparison with the previous year.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

$currentStart = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
$start = isset($_GET['fy']) && $_GET['fy'] !== '' ? (int) $_GET['fy'] : $currentStart;
if ($start < 2000 || $start > 2100) {
    fail('Choose a valid financial year');
}
$from = "$start-04-01";
$to = ($start + 1) . '-03-31';
[$si, $sp] = store_filter($user, 'i');
[$se, $sep] = store_filter($user, 'e');
$scope = scope_store($user, $_GET['store_id'] ?? null);
[$ss, $ssp] = stats_store($scope);
$S = sales_source();
$P = payments_source();

function annual_totals(string $from, string $to, string $S, string $ss, array $ssp, string $se, array $sep): array
{
    $s = db_one("SELECT COALESCE(SUM(s.bills),0) AS bills, COALESCE(SUM(s.total),0) AS sales, COALESCE(SUM(s.taxable),0) AS net_sales,
                        COALESCE(SUM(s.tax),0) AS tax, COALESCE(SUM(s.cogs),0) AS cogs, COALESCE(SUM(s.balance),0) AS balance
                 FROM $S s WHERE s.status <> 'Cancelled' AND s.day BETWEEN ? AND ?$ss", array_merge([$from, $to], $ssp));
    $e = db_one("SELECT COALESCE(SUM(e.amount),0) AS amount, COALESCE(SUM(e.tax_amount),0) AS tax FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se", array_merge([$from, $to], $sep));
    $net = (float) $s['net_sales'];
    $cogs = (float) $s['cogs'];
    $expenses = (float) $e['amount'] - (float) $e['tax'];
    return [
        'bills' => (int) $s['bills'],
        'sales' => round((float) $s['sales'], 2),
        'net_sales' => round($net, 2),
        'tax' => round((float) $s['tax'], 2),
        'cost_of_goods' => round($cogs, 2),
        'gross_profit' => round($net - $cogs, 2),
        'expenses' => round((float) $e['amount'], 2),
        'profit' => round($net - $expenses, 2),
        'average_bill' => (int) $s['bills'] ? round((float) $s['sales'] / (int) $s['bills'], 2) : 0,
        '_balance' => round((float) $s['balance'], 2),
    ];
}

$months = [];
for ($i = 0; $i < 12; $i++) {
    $m = date('Y-m', strtotime("$from +$i month"));
    $months[$m] = [
        'month' => $m, 'bills' => 0, 'retail_bills' => 0, 'tax_invoices' => 0, 'net_sales' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0,
        'tax' => 0.0, 'sales' => 0.0, 'collected' => 0.0, 'cost_of_goods' => 0.0, 'expenses' => 0.0, 'expense_tax' => 0.0,
    ];
}
$range = array_merge([$from, $to], $ssp);
$live = "s.status <> 'Cancelled' AND s.day BETWEEN ? AND ?$ss";
foreach (db_all(
    "SELECT DATE_FORMAT(s.day,'%Y-%m') AS m, SUM(s.bills) AS bills, SUM(CASE WHEN s.invoice_type = 'retail' THEN s.bills ELSE 0 END) AS retail_bills,
            SUM(CASE WHEN s.invoice_type = 'tax' THEN s.bills ELSE 0 END) AS tax_invoices,
            SUM(s.taxable) AS net_sales, SUM(s.cgst) AS cgst, SUM(s.sgst) AS sgst, SUM(s.igst) AS igst, SUM(s.tax) AS tax, SUM(s.total) AS sales,
            SUM(s.cogs) AS cogs
     FROM $S s WHERE $live GROUP BY m",
    $range
) as $r) {
    $r['cost_of_goods'] = round((float) $r['cogs'], 2);
    unset($r['cogs']);
    $months[$r['m']] = array_merge($months[$r['m']], cast_numbers($r, ['net_sales', 'cgst', 'sgst', 'igst', 'tax', 'sales'], ['bills', 'retail_bills', 'tax_invoices']));
    unset($months[$r['m']]['m']);
}
foreach (db_all("SELECT DATE_FORMAT(s.day,'%Y-%m') AS m, SUM(s.done_amount) AS amount FROM $P s WHERE s.day BETWEEN ? AND ?$ss GROUP BY m", $range) as $r) {
    $months[$r['m']]['collected'] = round((float) $r['amount'], 2);
}
foreach (db_all(
    "SELECT DATE_FORMAT(e.expense_date,'%Y-%m') AS m, SUM(e.amount) AS amount, SUM(e.tax_amount) AS tax FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY m",
    array_merge([$from, $to], $sep)
) as $r) {
    $months[$r['m']]['expenses'] = round((float) $r['amount'], 2);
    $months[$r['m']]['expense_tax'] = round((float) $r['tax'], 2);
}
foreach ($months as &$m) {
    $m['gross_profit'] = round($m['net_sales'] - $m['cost_of_goods'], 2);
    $m['profit'] = round($m['net_sales'] - ($m['expenses'] - $m['expense_tax']), 2);
}
unset($m);

$totals = annual_totals($from, $to, $S, $ss, $ssp, $se, $sep);
$totals['collected'] = round(array_sum(array_column($months, 'collected')), 2);
$totals['outstanding'] = $totals['_balance'];
unset($totals['_balance']);
$previous = annual_totals(($start - 1) . '-04-01', "$start-03-31", $S, $ss, $ssp, $se, $sep);
unset($previous['_balance']);
$growth = fn($k) => $previous[$k] > 0 ? round(($totals[$k] - $previous[$k]) * 100 / $previous[$k], 1) : null;
[$items, $itemParams] = items_source($from, $to, $scope);
[$cust, $custParams] = customers_source($from, $to, $scope);

json_out([
    'status' => 'success',
    'fy' => sprintf('%d-%02d', $start, ($start + 1) % 100),
    'fy_start' => $start,
    'range' => ['from' => $from, 'to' => $to],
    'totals' => $totals,
    'previous' => $previous,
    'growth' => ['sales' => $growth('sales'), 'profit' => $growth('profit'), 'bills' => $growth('bills')],
    'monthly' => array_values($months),
    'top_products' => f2(db_all(
        "SELECT x.product_name AS name, MAX(x.unit) AS unit, SUM(x.qty) AS quantity, SUM(x.line_total) AS sales, SUM(x.taxable - x.cost) AS margin
         FROM $items GROUP BY x.product_id, x.product_name ORDER BY sales DESC LIMIT 25",
        $itemParams
    ), ['quantity', 'sales', 'margin']),
    'categories' => f2(db_all(
        "SELECT COALESCE(NULLIF(pr.category,''),'Other') AS category, SUM(t.qty) AS quantity, SUM(t.sales) AS sales
         FROM (SELECT x.product_id, SUM(x.qty) AS qty, SUM(x.line_total) AS sales FROM $items GROUP BY x.product_id) t
         LEFT JOIN products pr ON pr.product_id = t.product_id GROUP BY category ORDER BY sales DESC",
        $itemParams
    ), ['quantity', 'sales']),
    'top_customers' => f2(db_all(
        "SELECT COALESCE(MAX(x.customer_name),'Walk-in') AS name, MAX(x.customer_phone) AS phone, SUM(x.bills) AS bills, SUM(x.total) AS sales, SUM(x.balance) AS balance_due
         FROM $cust WHERE x.customer_id <> 0 GROUP BY x.customer_id ORDER BY sales DESC LIMIT 25",
        $custParams
    ), ['sales', 'balance_due'], ['bills']),
    'payment_modes' => f2(db_all(
        "SELECT s.method, SUM(s.done_count) AS count, SUM(s.done_amount) AS amount FROM $P s
         WHERE s.day BETWEEN ? AND ?$ss GROUP BY s.method HAVING count > 0 ORDER BY amount DESC",
        $range
    ), ['amount'], ['count']),
    'order_types' => f2(db_all(
        "SELECT COALESCE(NULLIF(s.order_type,''),'Counter') AS type, SUM(s.bills) AS bills, SUM(s.total) AS sales FROM $S s WHERE $live GROUP BY type ORDER BY sales DESC",
        $range
    ), ['sales'], ['bills']),
    'gst_rates' => f2(db_all(
        "SELECT x.gst_rate, SUM(x.taxable) AS taxable_value, SUM(x.cgst) AS cgst, SUM(x.sgst) AS sgst, SUM(x.igst) AS igst, SUM(x.tax) AS tax
         FROM $items GROUP BY x.gst_rate ORDER BY x.gst_rate",
        $itemParams
    ), ['gst_rate', 'taxable_value', 'cgst', 'sgst', 'igst', 'tax']),
    'expenses_by_category' => f2(db_all(
        "SELECT e.category, COUNT(*) AS count, SUM(e.amount) AS amount FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY e.category ORDER BY amount DESC",
        array_merge([$from, $to], $sep)
    ), ['amount'], ['count']),
    'weekdays' => f2(db_all(
        "SELECT DAYOFWEEK(s.day) AS day, SUM(s.bills) AS bills, SUM(s.total) AS sales FROM $S s WHERE $live GROUP BY DAYOFWEEK(s.day) ORDER BY day",
        $range
    ), ['sales'], ['day', 'bills']),
]);
