<?php
/**
 * Save e-invoice (IRN) / e-way bill details received from the GST IRP portal for a tax invoice,
 * so they print on the invoice with the signed QR code.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$id = int_field($d, 'invoice_id');
$inv = $id ? db_one("SELECT invoice_id, store_id, invoice_type, status FROM invoices WHERE invoice_id = ?", [$id]) : null;
if (!$inv) {
    fail('Invoice not found', 404);
}
assert_store_access($user, $inv['store_id']);
if ($inv['invoice_type'] !== 'tax') {
    fail('E-invoice details apply to tax invoices only', 409);
}
$irn = str_field($d, 'irn');
if ($irn && !preg_match('/^[a-fA-F0-9]{64}$/', $irn)) {
    fail('IRN must be the 64 character hash from the IRP');
}
$ackDate = str_field($d, 'ack_date');
if ($ackDate && strtotime($ackDate) === false) {
    fail('Invalid acknowledgement date');
}
db_query(
    "UPDATE invoices SET irn = ?, ack_no = ?, ack_date = ?, signed_qr = ?, eway_bill_no = ?, vehicle_no = ? WHERE invoice_id = ?",
    [$irn ? strtolower($irn) : null, str_field($d, 'ack_no'), $ackDate ? date('Y-m-d H:i:s', strtotime($ackDate)) : null,
     str_field($d, 'signed_qr'), str_field($d, 'eway_bill_no'), str_field($d, 'vehicle_no'), $id]
);
json_out(["status" => "success", "message" => "E-invoice details saved", "data" => load_invoice($id)]);
