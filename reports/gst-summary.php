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

$totals = cast_numbers(db_one(
    "SELECT COUNT(*) AS invoices, COALESCE(SUM(i.taxable_amount),0) AS taxable_value, COALESCE(SUM(i.cgst_amount),0) AS cgst_amount,
            COALESCE(SUM(i.sgst_amount),0) AS sgst_amount, COALESCE(SUM(i.igst_amount),0) AS igst_amount,
            COALESCE(SUM(i.tax_amount),0) AS tax_amount, COALESCE(SUM(i.total_amount),0) AS total_amount
     FROM invoices i WHERE $base",
    $params
), $money, ['invoices']);

$byRate = f2(db_all(
    "SELECT ii.gst_rate, SUM(ii.taxable_value) AS taxable_value, SUM(ii.cgst_amount) AS cgst_amount, SUM(ii.sgst_amount) AS sgst_amount,
            SUM(ii.igst_amount) AS igst_amount, SUM(ii.tax_amount) AS tax_amount
     FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $base GROUP BY ii.gst_rate ORDER BY ii.gst_rate",
    $params
), $money);

$b2b = array_map('doc_cast', db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.customer_name, i.customer_gstin, i.place_of_supply, i.place_of_supply_code,
            i.reverse_charge, i.taxable_amount, i.cgst_amount, i.sgst_amount, i.igst_amount, i.tax_amount, i.total_amount
     FROM invoices i WHERE $base AND i.customer_gstin IS NOT NULL AND i.customer_gstin <> '' ORDER BY i.invoice_date, i.invoice_id",
    $params
));

$b2c = f2(db_all(
    "SELECT COALESCE(i.place_of_supply, '-') AS place_of_supply, ii.gst_rate, SUM(ii.taxable_value) AS taxable_value,
            SUM(ii.cgst_amount) AS cgst_amount, SUM(ii.sgst_amount) AS sgst_amount, SUM(ii.igst_amount) AS igst_amount, SUM(ii.tax_amount) AS tax_amount
     FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE $base AND (i.customer_gstin IS NULL OR i.customer_gstin = '')
     GROUP BY i.place_of_supply, ii.gst_rate ORDER BY i.place_of_supply, ii.gst_rate",
    $params
), $money);

$hsn = f2(db_all(
    "SELECT COALESCE(ii.hsn_code, '-') AS hsn_code, MAX(ii.product_name) AS description, COALESCE(ii.unit, 'OTH') AS unit, ii.gst_rate,
            SUM(ii.quantity) AS quantity, SUM(ii.line_total) AS total_amount, SUM(ii.taxable_value) AS taxable_value,
            SUM(ii.cgst_amount) AS cgst_amount, SUM(ii.sgst_amount) AS sgst_amount, SUM(ii.igst_amount) AS igst_amount, SUM(ii.tax_amount) AS tax_amount
     FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE $base
     GROUP BY ii.hsn_code, ii.unit, ii.gst_rate ORDER BY ii.hsn_code",
    $params
), $money);

$inputTax = (float) db_value("SELECT COALESCE(SUM(e.tax_amount),0) FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se", array_merge([$from, $to], $sep));
$cancelled = (int) db_value("SELECT COUNT(*) FROM invoices i WHERE i.status = 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si", $params);

json_out([
    "status" => "success",
    "range" => ["from" => $from, "to" => $to],
    "totals" => $totals + ["cancelled_invoices" => $cancelled],
    "input_tax_credit" => round($inputTax, 2),
    "net_gst_payable" => round(max(0, $totals['tax_amount'] - $inputTax), 2),
    "by_rate" => $byRate,
    "b2b" => $b2b,
    "b2c" => $b2c,
    "hsn" => $hsn,
]);
