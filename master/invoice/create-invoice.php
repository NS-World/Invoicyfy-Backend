<?php
/**
 * Create an invoice.
 *  - From an order:  { "order_id": 12, "invoice_type": "tax" | "retail", ... }
 *  - Direct / quick bill: { "store_id", "customer_id" | "customer_name"/"customer_phone", "items": [...], ... }
 * Payment: "payment": { "amount", "payment_method", "reference_no" } or split "payments": [ {...}, {...} ].
 * invoice_type: "retail" = bill of supply / cash memo (thermal receipt), "tax" = GST tax invoice (A4, B2B).
 *
 * V3 options (all optional):
 *   table_no          dine-in table, printed on the bill and KOT
 *   coupon_code       coupon from Coupons & Loyalty
 *   redeem_points     loyalty points used as discount (customer required)
 *   approval_pin      manager PIN when the discount is above the cashier's limit
 *   client_uuid       idempotency key: the same bill sent twice (offline sync, double click) is saved once
 *   offline_created_at  when the bill was made without internet (YYYY-MM-DD HH:MM:SS)
 *
 * V4 options (all optional):
 *   sales_channel     online platform from Counter settings (e.g. "Swiggy", "Zomato"); its commission % is noted
 *   channel_order_id  the platform's order number (one bill per order number)
 *   no_offers         true = do not apply combo / happy-hour offers to this bill
 *   send_bill         true / false = send (or not) the bill link to the customer, overriding the counter setting
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$type = strtolower((string) str_field($d, 'invoice_type', 'tax'));
if (!in_array($type, ['tax', 'retail'], true)) {
    fail('invoice_type must be "tax" or "retail"');
}

/** The same request sent again returns the bill that was already saved. */
function existing_by_uuid(?string $uuid): ?array
{
    if (!$uuid) {
        return null;
    }
    try {
        $id = db_value('SELECT invoice_id FROM invoices WHERE client_uuid = ?', [$uuid]);
    } catch (PDOException $e) {
        return null;
    }
    return $id ? load_invoice((int) $id) : null;
}

function respond_existing(array $invoice): void
{
    json_out([
        "status" => "success",
        "message" => "Invoice {$invoice['invoice_number']} was already saved",
        "duplicate" => true,
        "invoice_id" => $invoice['invoice_id'],
        "invoice_number" => $invoice['invoice_number'],
        "data" => $invoice,
    ]);
}

$uuid = str_field($d, 'client_uuid');
if ($uuid !== null && !preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uuid)) {
    fail('client_uuid must be 8-40 letters, numbers, - or _');
}
if ($existing = existing_by_uuid($uuid)) {
    assert_store_access($user, $existing['store_id']);
    respond_existing($existing);
}

$offlineAt = null;
if ($raw = str_field($d, 'offline_created_at')) {
    $ts = strtotime($raw);
    if ($ts === false || $ts > time() + 300 || $ts < time() - 30 * 86400) {
        fail('offline_created_at must be a date/time within the last 30 days');
    }
    $offlineAt = date('Y-m-d H:i:s', $ts);
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
        'table_no' => $order['table_no'] ?? null,
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
$pos = pos_settings($store);
if ($pos['require_day_open'] && !$offlineAt && !open_cash_session($storeId)) {
    fail('The day is not opened yet. Open the cash register (Day Close screen) before billing.', 409, ['code' => 'day_not_open']);
}
$party = resolve_party($d, $store);
if ($type === 'tax' && !$party['customer_name']) {
    fail('Customer name is required on a tax invoice');
}
$items = prepare_items($rawItems);
$tableNo = str_field($d, 'table_no');
if ($tableNo !== null && mb_strlen($tableNo) > 20) {
    fail('Table number is too long');
}
$quoteOpts = [
    'coupon_code' => str_field($d, 'coupon_code'),
    'redeem_points' => num_field($d, 'redeem_points', 0),
    'round_off' => bool_field($d, 'round_off', true),
    'no_offers' => bool_field($d, 'no_offers'),
    'at' => $offlineAt ? strtotime($offlineAt) : null,
];

// V4: online platform orders (Swiggy / Zomato ...), set up in Counter settings with their commission %.
$channel = null;
$channelOrderId = null;
if ($rawChannel = str_field($d, 'sales_channel')) {
    foreach ($pos['online_platforms'] as $pl) {
        if (strcasecmp($pl['name'], $rawChannel) === 0) {
            $channel = $pl;
        }
    }
    if (!$channel) {
        fail("Online platform \"$rawChannel\" is not set up. Add it in Counter & Printer settings.", 422);
    }
    $channelOrderId = str_field($d, 'channel_order_id');
    if ($channelOrderId !== null && !preg_match('/^[A-Za-z0-9#\/_.-]{1,60}$/', $channelOrderId)) {
        fail('Platform order number can have letters, numbers and # / - _ . only (max 60)');
    }
    if ($channelOrderId !== null && !$uuid) {
        $dup = db_value('SELECT invoice_number FROM invoices WHERE store_id = ? AND sales_channel = ? AND channel_order_id = ? AND status <> \'Cancelled\' LIMIT 1', [$storeId, $channel['name'], $channelOrderId]);
        if ($dup) {
            fail("{$channel['name']} order $channelOrderId is already billed (bill $dup)", 409);
        }
    }
    $d['order_type'] = $d['order_type'] ?? 'Online';
}
$quote = quote_bill($store, $party, $items, $quoteOpts);
$approver = check_discount_approval($user, $storeId, $quote['manual_discount_percent'], str_field($d, 'approval_pin'));

$invoiceDate = date_field($d, 'invoice_date', $offlineAt ? substr($offlineAt, 0, 10) : date('Y-m-d'));
$head = $party + [
    'invoice_type' => $type,
    'order_id' => $orderId ?: null,
    'invoice_date' => $invoiceDate,
    'due_date' => date_field($d, 'due_date'),
    'order_type' => str_field($d, 'order_type'),
    'table_no' => $tableNo,
    'reverse_charge' => bool_field($d, 'reverse_charge') ? 1 : 0,
    'notes' => str_field($d, 'notes'),
    'terms' => str_field($d, 'terms', $store['invoice_terms']),
    'eway_bill_no' => str_field($d, 'eway_bill_no'),
    'vehicle_no' => str_field($d, 'vehicle_no'),
    'approved_by' => $approver,
    'client_uuid' => $uuid,
    'offline_at' => $offlineAt,
    'sales_channel' => $channel['name'] ?? null,
    'channel_order_id' => $channelOrderId,
];
if ($head['due_date'] && $head['due_date'] < $invoiceDate) {
    fail('Due date cannot be before the invoice date');
}
$payments = payments_from_input($d);

try {
    $pdo->beginTransaction();
    // Recalculate with locks so a coupon / points cannot be used twice by two counters at the same moment.
    $quote = quote_bill($store, $party, $items, $quoteOpts + ['lock' => true]);
    $totals = $quote['totals'];
    if ($channel && !$payments && $totals['total_amount'] > 0) {
        // The platform collects the money and pays it later: the bill is settled from the Online orders screen.
        $payments = [['amount' => $totals['total_amount'], 'payment_method' => 'Online Platform', 'reference_no' => trim($channel['name'] . ' ' . $channelOrderId)]];
    }
    $paidTotal = round(array_sum(array_map(fn($p) => money($p['amount'] ?? 0), $payments)), 2);
    if ($paidTotal - $totals['total_amount'] > 0.009) {
        fail('Payments (' . rupees($paidTotal) . ') are more than the bill total (' . rupees($totals['total_amount']) . ')', 422);
    }
    $head += [
        'coupon_code' => $quote['coupon']['code'] ?? null,
        'coupon_discount' => $quote['coupon']['discount'] ?? 0,
        'loyalty_points_redeemed' => $quote['loyalty']['redeemed_points'],
        'loyalty_discount' => $quote['loyalty']['discount'],
        'loyalty_points_earned' => $quote['loyalty']['earn_points'],
        'offer_discount' => $quote['offer_discount'],
        'offer_names' => $quote['offers'] ? mb_substr(implode(', ', array_column($quote['offers'], 'name')), 0, 255) : null,
        'channel_commission' => $channel ? round($totals['total_amount'] * (float) $channel['commission'] / 100, 2) : 0,
    ];
    $invoiceId = create_invoice_record($store, $user, $head, $quote['lines'], $totals);

    if ($quote['coupon']) {
        db_query('INSERT INTO coupon_redemptions (coupon_id, invoice_id, customer_id, discount) VALUES (?, ?, ?, ?)',
            [$quote['coupon']['coupon_id'], $invoiceId, $party['customer_id'], $quote['coupon']['discount']]);
        db_query('UPDATE coupons SET used_count = used_count + 1 WHERE coupon_id = ?', [$quote['coupon']['coupon_id']]);
    }
    if ($party['customer_id']) {
        $number = db_value('SELECT invoice_number FROM invoices WHERE invoice_id = ?', [$invoiceId]);
        if ($quote['loyalty']['redeemed_points'] > 0) {
            loyalty_post((int) $party['customer_id'], $storeId, $invoiceId, 'redeem', -$quote['loyalty']['redeemed_points'], "Used on bill $number", (int) $user['user_id']);
        }
        if ($quote['loyalty']['earn_points'] > 0) {
            loyalty_post((int) $party['customer_id'], $storeId, $invoiceId, 'earn', $quote['loyalty']['earn_points'], "Earned on bill $number", (int) $user['user_id']);
        }
    }
    if ($orderId) {
        db_query("UPDATE orders SET status = 'Invoiced' WHERE order_id = ?", [$orderId]);
    }
    foreach ($quote['offers'] as $o) {
        db_query('UPDATE offers SET used_count = used_count + 1 WHERE offer_id = ?', [$o['offer_id']]);
    }
    foreach ($payments as $p) {
        $inv = db_one("SELECT * FROM invoices WHERE invoice_id = ? FOR UPDATE", [$invoiceId]);
        record_payment($inv, $user, $p + ['payment_date' => $invoiceDate]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Two copies of the same offline bill racing each other: the unique key keeps one.
    if (($e->errorInfo[1] ?? 0) == 1062 && ($existing = existing_by_uuid($uuid))) {
        respond_existing($existing);
    }
    throw $e;
}

$invoice = load_invoice($invoiceId);
$discounts = array_filter([
    $quote['coupon'] ? "coupon {$quote['coupon']['code']} " . rupees($quote['coupon']['discount']) : null,
    $quote['loyalty']['discount'] > 0 ? "{$quote['loyalty']['redeemed_points']} points " . rupees($quote['loyalty']['discount']) : null,
    $quote['offers'] ? 'offer ' . implode(', ', array_column($quote['offers'], 'name')) . ' ' . rupees($quote['offer_discount']) : null,
    $channel ? "{$channel['name']}" . ($channelOrderId ? " #$channelOrderId" : '') : null,
    $quote['manual_discount_percent'] > 0 ? "manual discount {$quote['manual_discount_percent']}%" : null,
]);
log_activity(
    $offlineAt ? 'invoice.create_offline' : 'invoice.create',
    'invoice',
    $invoiceId,
    "Bill {$invoice['invoice_number']} " . rupees($invoice['total_amount']) . ($discounts ? ' (' . implode(', ', $discounts) . ')' : ''),
    ['payments' => array_map(fn($p) => pick($p, ['amount', 'payment_method']), $payments), 'table_no' => $tableNo],
    $storeId
);
if ($approver) {
    $name = db_value('SELECT name FROM users WHERE user_id = ?', [$approver]);
    log_activity('discount.approved', 'invoice', $invoiceId, "Discount {$quote['manual_discount_percent']}% on bill {$invoice['invoice_number']} approved by $name", ['approved_by' => $approver], $storeId);
}

// V4: bill link to the customer on WhatsApp / SMS / Email, sent after the answer so billing stays instant.
$sendBill = array_key_exists('send_bill', $d) && $d['send_bill'] !== null ? bool_field($d, 'send_bill') : (bool) $pos['auto_send_bill'];
$billQueued = null;
if ($sendBill && !$offlineAt && ($invoice['customer_phone'] || $invoice['customer_email'])) {
    try {
        $billQueued = queue_bill_message($store, $invoice, $pos['bill_channels'], $pos['bill_message'], (int) $user['user_id']);
    } catch (Throwable $e) {
        error_log('[Invoicyfy bill message] ' . $e->getMessage());
    }
    if ($billQueued) {
        $cid = $billQueued;
        after_response(fn() => process_campaign($cid, 5, microtime(true) + 20));
    }
}

json_out([
    "status" => "success",
    "message" => "Invoice {$invoice['invoice_number']} created",
    "bill_message" => $billQueued ? 'queued' : null,
    "invoice_id" => $invoiceId,
    "invoice_number" => $invoice['invoice_number'],
    "data" => $invoice,
], 201);
