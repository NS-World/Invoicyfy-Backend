<?php
/** Sales register grouped by day / month / product / customer. */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range();
[$si, $sp] = store_filter($user, 'i');
$group = $_GET['group'] ?? 'day';
$params = array_merge([$from, $to], $sp);
$base = "i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si";

switch ($group) {
    case 'month':
        $sql = "SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS label, COUNT(*) AS invoices, SUM(i.taxable_amount) AS taxable_value,
                       SUM(i.tax_amount) AS tax_amount, SUM(i.total_amount) AS total_amount, SUM(i.amount_paid) AS amount_paid, SUM(i.balance_due) AS balance_due
                FROM invoices i WHERE $base GROUP BY label ORDER BY label";
        break;
    case 'product':
        $sql = "SELECT COALESCE(ii.product_name,'Item') AS label, COUNT(DISTINCT i.invoice_id) AS invoices, SUM(ii.quantity) AS quantity,
                       SUM(ii.taxable_value) AS taxable_value, SUM(ii.tax_amount) AS tax_amount, SUM(ii.line_total) AS total_amount,
                       SUM(ii.quantity * ii.cost_price) AS cost
                FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $base
                GROUP BY ii.product_id, ii.product_name ORDER BY total_amount DESC";
        break;
    case 'customer':
        $sql = "SELECT COALESCE(i.customer_name,'Walk-in') AS label, MAX(i.customer_gstin) AS gstin, COUNT(*) AS invoices,
                       SUM(i.taxable_amount) AS taxable_value, SUM(i.tax_amount) AS tax_amount, SUM(i.total_amount) AS total_amount,
                       SUM(i.amount_paid) AS amount_paid, SUM(i.balance_due) AS balance_due
                FROM invoices i WHERE $base GROUP BY i.customer_id, i.customer_name ORDER BY total_amount DESC";
        break;
    default:
        $group = 'day';
        $sql = "SELECT i.invoice_date AS label, COUNT(*) AS invoices, SUM(i.taxable_amount) AS taxable_value, SUM(i.tax_amount) AS tax_amount,
                       SUM(i.total_amount) AS total_amount, SUM(i.amount_paid) AS amount_paid, SUM(i.balance_due) AS balance_due
                FROM invoices i WHERE $base GROUP BY label ORDER BY label";
}
$rows = f2(db_all($sql, $params), ['taxable_value', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due', 'quantity', 'cost'], ['invoices']);
$totals = [];
foreach (['invoices', 'quantity', 'taxable_value', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due', 'cost'] as $k) {
    if ($rows && array_key_exists($k, $rows[0])) {
        $totals[$k] = round(array_sum(array_column($rows, $k)), 2);
    }
}
json_out(["status" => "success", "range" => ["from" => $from, "to" => $to], "group" => $group, "totals" => $totals, "data" => $rows]);
