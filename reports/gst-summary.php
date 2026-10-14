<?php
/** GST summary for filing (GSTR-1 / GSTR-3B preparation). */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range();
[$si, $sp] = store_filter($user, 'i');
[$se, $sep] = store_filter($user, 'e');
$base = "i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si";
$params = array_merge([$from, $to], $sp);
$money = ['taxable_value', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'total_amount', 'quantity', 'gst_rate'];

$scope = scope_store($user, $_GET['store_id'] ?? null);
[$ss, $ssp] = stats_store($scope);
$S = sales_source();
$G = gst_source();
$range = array_merge([$from, $to], $ssp);

$totals = cast_numbers(db_one(
    "SELECT COALESCE(SUM(s.bills),0) AS invoices, COALESCE(SUM(s.taxable),0) AS taxable_value, COALESCE(SUM(s.cgst),0) AS cgst_amount,
            COALESCE(SUM(s.sgst),0) AS sgst_amount, COALESCE(SUM(s.igst),0) AS igst_amount,
            COALESCE(SUM(s.tax),0) AS tax_amount, COALESCE(SUM(s.total),0) AS total_amount
     FROM $S s WHERE s.status <> 'Cancelled' AND s.day BETWEEN ? AND ?$ss",
    $range
), $money, ['invoices']);

$byRate = f2(db_all(
    "SELECT s.gst_rate, SUM(s.taxable) AS taxable_value, SUM(s.cgst) AS cgst_amount, SUM(s.sgst) AS sgst_amount,
            SUM(s.igst) AS igst_amount, SUM(s.tax) AS tax_amount
     FROM $G s WHERE s.day BETWEEN ? AND ?$ss GROUP BY s.gst_rate ORDER BY s.gst_rate",
    $range
), $money);

// B2B bills are listed one by one (GSTR-1): read through the (date) index.
$b2b = array_map('doc_cast', db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.customer_name, i.customer_gstin, i.place_of_supply, i.place_of_supply_code,
            i.reverse_charge, i.taxable_amount, i.cgst_amount, i.sgst_amount, i.igst_amount, i.tax_amount, i.total_amount
     FROM invoices i WHERE $base AND i.customer_gstin IS NOT NULL AND i.customer_gstin <> '' ORDER BY i.invoice_date, i.invoice_id",
    $params
));

$b2c = f2(db_all(
    "SELECT s.place_of_supply, s.gst_rate, SUM(s.taxable) AS taxable_value,
            SUM(s.cgst) AS cgst_amount, SUM(s.sgst) AS sgst_amount, SUM(s.igst) AS igst_amount, SUM(s.tax) AS tax_amount
     FROM $G s WHERE s.b2b = 0 AND s.day BETWEEN ? AND ?$ss
     GROUP BY s.place_of_supply, s.gst_rate ORDER BY s.place_of_supply, s.gst_rate",
    $range
), $money);

$hsn = f2(db_all(
    "SELECT s.hsn_code, MAX(s.description) AS description, s.unit, s.gst_rate,
            SUM(s.qty) AS quantity, SUM(s.line_total) AS total_amount, SUM(s.taxable) AS taxable_value,
            SUM(s.cgst) AS cgst_amount, SUM(s.sgst) AS sgst_amount, SUM(s.igst) AS igst_amount, SUM(s.tax) AS tax_amount
     FROM $G s WHERE s.day BETWEEN ? AND ?$ss
     GROUP BY s.hsn_code, s.unit, s.gst_rate ORDER BY s.hsn_code",
    $range
), $money);

$inputTax = (float) db_value("SELECT COALESCE(SUM(e.tax_amount),0) FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se", array_merge([$from, $to], $sep));
// V4: GST paid on purchase bills is input tax credit too.
$purchaseTax = 0.0;
if (v4_ready()) {
    [$sq, $sqp] = store_filter($user, 'p');
    $purchaseTax = (float) db_value("SELECT COALESCE(SUM(p.tax_amount),0) FROM purchases p WHERE p.status <> 'Cancelled' AND p.purchase_date BETWEEN ? AND ? $sq", array_merge([$from, $to], $sqp));
    $inputTax += $purchaseTax;
}
$cancelled = (int) db_value("SELECT COALESCE(SUM(s.bills),0) FROM $S s WHERE s.status = 'Cancelled' AND s.day BETWEEN ? AND ?$ss", $range);

json_out([
    "status" => "success",
    "range" => ["from" => $from, "to" => $to],
    "totals" => $totals + ["cancelled_invoices" => $cancelled],
    "input_tax_credit" => round($inputTax, 2),
    "input_tax_purchases" => round($purchaseTax, 2),
    "net_gst_payable" => round(max(0, $totals['tax_amount'] - $inputTax), 2),
    "by_rate" => $byRate,
    "b2b" => $b2b,
    "b2c" => $b2c,
    "hsn" => $hsn,
]);
