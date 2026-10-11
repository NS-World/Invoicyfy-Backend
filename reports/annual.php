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
[$spay, $spayp] = store_filter($user, 'p');
$live = "i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si";

function annual_totals(string $from, string $to, string $si, array $sp, string $se, array $sep): array
{
    $live = "i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si";
    $s = db_one("SELECT COUNT(*) AS bills, COALESCE(SUM(i.total_amount),0) AS sales, COALESCE(SUM(i.taxable_amount),0) AS net_sales,
                        COALESCE(SUM(i.tax_amount),0) AS tax FROM invoices i WHERE $live", array_merge([$from, $to], $sp));
    $cogs = (float) db_value("SELECT COALESCE(SUM(ii.quantity * ii.cost_price),0) FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $live", array_merge([$from, $to], $sp));
    $e = db_one("SELECT COALESCE(SUM(e.amount),0) AS amount, COALESCE(SUM(e.tax_amount),0) AS tax FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se", array_merge([$from, $to], $sep));
    $net = (float) $s['net_sales'];
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
$p = array_merge([$from, $to], $sp);
foreach (db_all(
    "SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS m, COUNT(*) AS bills, SUM(i.invoice_type = 'retail') AS retail_bills, SUM(i.invoice_type = 'tax') AS tax_invoices,
            SUM(i.taxable_amount) AS net_sales, SUM(i.cgst_amount) AS cgst, SUM(i.sgst_amount) AS sgst, SUM(i.igst_amount) AS igst,
            SUM(i.tax_amount) AS tax, SUM(i.total_amount) AS sales
     FROM invoices i WHERE $live GROUP BY m",
    $p
) as $r) {
    $months[$r['m']] = array_merge($months[$r['m']], cast_numbers($r, ['net_sales', 'cgst', 'sgst', 'igst', 'tax', 'sales'], ['bills', 'retail_bills', 'tax_invoices']));
    unset($months[$r['m']]['m']);
}
foreach (db_all(
    "SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS m, SUM(ii.quantity * ii.cost_price) AS cogs FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $live GROUP BY m",
    $p
) as $r) {
    $months[$r['m']]['cost_of_goods'] = round((float) $r['cogs'], 2);
}
foreach (db_all(
    "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') AS m, SUM(p.amount) AS amount FROM payments p WHERE p.status = 'Completed' AND p.payment_date BETWEEN ? AND ? $spay GROUP BY m",
    array_merge([$from, $to], $spayp)
) as $r) {
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

$totals = annual_totals($from, $to, $si, $sp, $se, $sep);
$totals['collected'] = round(array_sum(array_column($months, 'collected')), 2);
$totals['outstanding'] = round((float) db_value("SELECT COALESCE(SUM(i.balance_due),0) FROM invoices i WHERE $live", $p), 2);
$previous = annual_totals(($start - 1) . '-04-01', "$start-03-31", $si, $sp, $se, $sep);
$growth = fn($k) => $previous[$k] > 0 ? round(($totals[$k] - $previous[$k]) * 100 / $previous[$k], 1) : null;

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
        "SELECT COALESCE(ii.product_name,'Item') AS name, MAX(ii.unit) AS unit, SUM(ii.quantity) AS quantity, SUM(ii.line_total) AS sales,
                SUM(ii.taxable_value - ii.quantity * ii.cost_price) AS margin
         FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $live
         GROUP BY ii.product_id, ii.product_name ORDER BY sales DESC LIMIT 25",
        $p
    ), ['quantity', 'sales', 'margin']),
    'categories' => f2(db_all(
        "SELECT COALESCE(NULLIF(pr.category,''),'Other') AS category, SUM(ii.quantity) AS quantity, SUM(ii.line_total) AS sales
         FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id LEFT JOIN products pr ON pr.product_id = ii.product_id
         WHERE $live GROUP BY category ORDER BY sales DESC",
        $p
    ), ['quantity', 'sales']),
    'top_customers' => f2(db_all(
        "SELECT COALESCE(i.customer_name,'Walk-in') AS name, MAX(i.customer_phone) AS phone, COUNT(*) AS bills, SUM(i.total_amount) AS sales, SUM(i.balance_due) AS balance_due
         FROM invoices i WHERE $live AND i.customer_id IS NOT NULL GROUP BY i.customer_id, i.customer_name ORDER BY sales DESC LIMIT 25",
        $p
    ), ['sales', 'balance_due'], ['bills']),
    'payment_modes' => f2(db_all(
        "SELECT p.payment_method AS method, COUNT(*) AS count, SUM(p.amount) AS amount FROM payments p
         WHERE p.status = 'Completed' AND p.payment_date BETWEEN ? AND ? $spay GROUP BY p.payment_method ORDER BY amount DESC",
        array_merge([$from, $to], $spayp)
    ), ['amount'], ['count']),
    'order_types' => f2(db_all(
        "SELECT COALESCE(i.order_type,'Counter') AS type, COUNT(*) AS bills, SUM(i.total_amount) AS sales FROM invoices i WHERE $live GROUP BY type ORDER BY sales DESC",
        $p
    ), ['sales'], ['bills']),
    'gst_rates' => f2(db_all(
        "SELECT ii.gst_rate, SUM(ii.taxable_value) AS taxable_value, SUM(ii.cgst_amount) AS cgst, SUM(ii.sgst_amount) AS sgst, SUM(ii.igst_amount) AS igst, SUM(ii.tax_amount) AS tax
         FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $live GROUP BY ii.gst_rate ORDER BY ii.gst_rate",
        $p
    ), ['gst_rate', 'taxable_value', 'cgst', 'sgst', 'igst', 'tax']),
    'expenses_by_category' => f2(db_all(
        "SELECT e.category, COUNT(*) AS count, SUM(e.amount) AS amount FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY e.category ORDER BY amount DESC",
        array_merge([$from, $to], $sep)
    ), ['amount'], ['count']),
    'weekdays' => f2(db_all(
        "SELECT DAYOFWEEK(i.invoice_date) AS day, COUNT(*) AS bills, SUM(i.total_amount) AS sales FROM invoices i WHERE $live GROUP BY day ORDER BY day",
        $p
    ), ['sales'], ['day', 'bills']),
]);
