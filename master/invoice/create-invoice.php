<?php
/**
 * Create an invoice.
 *  - From an order:  { "order_id": 12, "invoice_type": "tax" | "retail", ... }
 *  - Direct / quick bill: { "store_id", "customer_id" | "customer_name"/"customer_phone", "items": [...], ... }
 * Optional "payment": { "amount", "payment_method", "reference_no" } records an immediate payment (e.g. cash counter).
 * invoice_type: "retail" = bill of supply / cash memo (thermal receipt), "tax" = GST tax invoice (A4, B2B).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$type = strtolower((string) str_field($d, 'invoice_type', 'tax'));
if (!in_array($type, ['tax', 'retail'], true)) {
    fail('invoice_type must be "tax" or "retail"');
}

$order = null;
$orderId = int_field($d, 'order_id');
if ($orderId) {
    $order = load_order($orderId);
    if (!$order) {
        fail('Order not found', 404);
    }
    assert_store_access($user, $order['store_id']);
    if (!in_array($order['status'], ['Pending', 'Approved'], true)) {
        fail("Order is {$order['status']} and cannot be invoiced", 409);
    }
    $storeId = (int) $order['store_id'];
    // Party comes from the order unless overridden.
    $d += [
        'customer_id' => $order['customer_id'],
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'],
        'order_type' => $order['order_type'],
        'notes' => $order['notes'],
    ];
    if (!$order['items']) {
        fail('Order has no items', 422);
    }
    $rawItems = array_map(fn($it) => pick($it, [
        'product_id', 'product_name', 'hsn_code', 'unit', 'quantity', 'unit_price', 'discount_amount',
        'gst_rate', 'price_includes_tax', 'item_note',
    ]), $order['items']);
} else {
    $storeId = write_store($user, $d['store_id'] ?? null);
    $rawItems = $d['items'] ?? [];
}

$store = get_store($storeId);
$party = resolve_party($d, $store);
if ($type === 'tax' && !$party['customer_name']) {
    fail('Customer name is required on a tax invoice');
}
$items = prepare_items($rawItems);
[$lines, $totals] = gst_calculate($items, (bool) $party['is_interstate'], bool_field($d, 'round_off', true));

$invoiceDate = date_field($d, 'invoice_date', date('Y-m-d'));
$head = $party + [
    'invoice_type' => $type,
    'order_id' => $orderId ?: null,
    'invoice_date' => $invoiceDate,
    'due_date' => date_field($d, 'due_date'),
    'order_type' => str_field($d, 'order_type'),
    'reverse_charge' => bool_field($d, 'reverse_charge') ? 1 : 0,
    'notes' => str_field($d, 'notes'),
    'terms' => str_field($d, 'terms', $store['invoice_terms']),
    'eway_bill_no' => str_field($d, 'eway_bill_no'),
    'vehicle_no' => str_field($d, 'vehicle_no'),
];
if ($head['due_date'] && $head['due_date'] < $invoiceDate) {
    fail('Due date cannot be before the invoice date');
}

$pdo->beginTransaction();
$invoiceId = create_invoice_record($store, $user, $head, $lines, $totals);
if ($orderId) {
    db_query("UPDATE orders SET status = 'Invoiced' WHERE order_id = ?", [$orderId]);
}
if (!empty($d['payment']) && is_array($d['payment']) && (float) ($d['payment']['amount'] ?? 0) > 0) {
    $inv = db_one("SELECT * FROM invoices WHERE invoice_id = ?", [$invoiceId]);
    record_payment($inv, $user, $d['payment'] + ['payment_date' => $invoiceDate]);
}
$pdo->commit();

$invoice = load_invoice($invoiceId);
json_out([
    "status" => "success",
    "message" => "Invoice {$invoice['invoice_number']} created",
    "invoice_id" => $invoiceId,
    "invoice_number" => $invoice['invoice_number'],
    "data" => $invoice,
], 201);
