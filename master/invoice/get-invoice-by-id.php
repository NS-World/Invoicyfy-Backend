<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$id = int_field($_GET, 'invoice_id');
$invoice = $id ? load_invoice($id) : null;
if (!$invoice) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $invoice['store_id']);
json_out(["status" => "success", "data" => $invoice]);
