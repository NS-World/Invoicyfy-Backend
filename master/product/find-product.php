<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
require_auth();

$id = int_field($_GET, 'product_id');
$product = $id ? db_one("SELECT p.*, s.name AS store_name FROM products p LEFT JOIN stores s ON p.store_id = s.store_id WHERE p.product_id = ?", [$id]) : null;
if (!$product) {
    fail('Product not found', 404);
}
$product = cast_numbers($product, PRODUCT_FLOATS, PRODUCT_INTS);
$product['sales'] = cast_numbers(db_one(
    "SELECT COALESCE(SUM(ii.quantity),0) AS qty_sold, COALESCE(SUM(ii.line_total),0) AS revenue
     FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE ii.product_id = ? AND i.status <> 'Cancelled'",
    [$id]
), ['qty_sold', 'revenue']);

json_out(["status" => "success", "data" => $product]);
