<?php
/**
 * Orders, invoices and payments share the same "party + items + GST totals" logic. It lives here
 * so every endpoint computes amounts the same way (the server never trusts totals sent by the client).
 */

const ORDER_STATUSES = ['Pending', 'Approved', 'Invoiced', 'Cancelled'];
const ORDER_TYPES = ['Counter', 'Takeaway', 'Delivery', 'Dine-in', 'Online', 'B2B'];
const PAYMENT_METHODS = ['Cash', 'UPI', 'Card', 'Bank Transfer', 'Cheque', 'Wallet', 'Credit', 'Other'];

const DOC_FLOATS = ['subtotal', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount',
    'tax_amount', 'round_off', 'total_amount', 'amount_paid', 'balance_due', 'quantity', 'unit_price', 'cost_price',
    'taxable_value', 'gst_rate', 'cgst_rate', 'sgst_rate', 'igst_rate', 'line_total', 'amount', 'item_count', 'total_qty'];
const DOC_INTS = ['order_id', 'invoice_id', 'customer_id', 'user_id', 'store_id', 'product_id', 'order_item_id',
    'invoice_item_id', 'payment_id', 'is_interstate', 'reverse_charge', 'price_includes_tax'];

function doc_cast(array $row): array
{
    return cast_numbers($row, DOC_FLOATS, DOC_INTS);
}

/**
 * Work out who we are billing and whether the supply is inter-state.
 * Accepts customer_id (preferred) or a walk-in name/phone.
 */
function resolve_party(array $d, array $store): array
{
    $customer = null;
    $customerId = int_field($d, 'customer_id');
    if ($customerId) {
        $customer = db_one("SELECT * FROM customers WHERE customer_id = ?", [$customerId]);
        if (!$customer) {
            fail('Customer not found');
        }
    }
    $gstin = normalize_gstin(str_field($d, 'customer_gstin', $customer['gstin'] ?? null));
    $posCode = resolve_state_code(
        str_field($d, 'place_of_supply_code'),
        $gstin,
        str_field($d, 'place_of_supply', $customer['state'] ?? null)
    ) ?? ($customer['state_code'] ?? null) ?? $store['state_code'];

    $interstate = $posCode && $store['state_code'] && $posCode !== $store['state_code'];
    if (array_key_exists('is_interstate', $d) && $d['is_interstate'] !== null && $d['is_interstate'] !== '') {
        $interstate = bool_field($d, 'is_interstate');
    }

    $address = $customer ? trim(implode(', ', array_filter([
        $customer['address'], $customer['city'], $customer['state'], $customer['pincode'],
    ]))) : null;

    return [
        'customer_id' => $customer ? (int) $customer['customer_id'] : null,
        'customer_name' => str_field($d, 'customer_name', $customer ? ($customer['company_name'] ?: $customer['name']) : null),
        'customer_phone' => str_field($d, 'customer_phone', $customer['phone'] ?? null),
        'customer_email' => str_field($d, 'customer_email', $customer['email'] ?? null),
        'customer_gstin' => $gstin,
        'billing_address' => str_field($d, 'billing_address', $address ?: null),
        'shipping_address' => str_field($d, 'shipping_address', $customer['shipping_address'] ?? null),
        'place_of_supply_code' => $posCode,
        'place_of_supply' => state_name($posCode),
        'is_interstate' => $interstate ? 1 : 0,
    ];
}

function insert_row(string $table, array $row): int
{
    global $pdo;
    $cols = array_keys($row);
    db_query(
        "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($row)
    );
    return (int) $pdo->lastInsertId();
}

function update_row(string $table, string $pk, int $id, array $row): void
{
    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($row)));
    db_query("UPDATE $table SET $set WHERE $pk = ?", array_merge(array_values($row), [$id]));
}

function pick(array $src, array $keys): array
{
    return array_intersect_key($src, array_flip($keys));
}

/* ---------------------------------------------------------------- Orders */

function write_order_items(int $orderId, array $lines): void
{
    db_query("DELETE FROM order_items WHERE order_id = ?", [$orderId]);
    foreach ($lines as $l) {
        insert_row('order_items', ['order_id' => $orderId] + pick($l, [
            'product_id', 'product_name', 'hsn_code', 'unit', 'quantity', 'unit_price', 'discount_amount',
            'gst_rate', 'price_includes_tax', 'taxable_value', 'tax_amount', 'line_total', 'item_note',
        ]));
    }
}

function load_order(int $orderId): ?array
{
    $order = db_one(
        "SELECT o.*, COALESCE(o.customer_name, c.name) AS customer_name, COALESCE(o.customer_phone, c.phone) AS customer_phone,
                c.email AS customer_email, c.gstin AS customer_gstin, c.customer_type,
                u.name AS user_name, s.name AS store_name,
                (SELECT i.invoice_id FROM invoices i WHERE i.order_id = o.order_id AND i.status <> 'Cancelled' ORDER BY i.invoice_id DESC LIMIT 1) AS invoice_id,
                (SELECT i.invoice_number FROM invoices i WHERE i.order_id = o.order_id AND i.status <> 'Cancelled' ORDER BY i.invoice_id DESC LIMIT 1) AS invoice_number
         FROM orders o
         LEFT JOIN customers c ON o.customer_id = c.customer_id
         LEFT JOIN users u ON o.user_id = u.user_id
         LEFT JOIN stores s ON o.store_id = s.store_id
         WHERE o.order_id = ?",
        [$orderId]
    );
    if (!$order) {
        return null;
    }
    $order = doc_cast($order);
    $order['items'] = array_map('doc_cast', db_all(
        "SELECT oi.*, COALESCE(oi.product_name, p.name) AS product_name, p.sku, (oi.quantity * oi.unit_price) AS total_price
         FROM order_items oi LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ? ORDER BY oi.order_item_id",
        [$orderId]
    ));
    return $order;
}

/* ---------------------------------------------------------------- Invoices */

function next_invoice_number(array $store, string $type, string $date): array
{
    $fy = financial_year($date);
    $seq = next_sequence((int) $store['store_id'], $type, $fy);
    if ($type === 'retail') {
        $number = ($store['bill_prefix'] ?? '') . $seq;
    } else {
        $number = sprintf('%s/%s/%04d', $store['invoice_prefix'] ?: 'INV', $fy, $seq);
    }
    return [$number, $fy];
}

/**
 * Create an invoice (inside the caller's transaction) and deduct stock.
 * $head: party fields + invoice meta; $lines/$totals from gst_calculate().
 */
function create_invoice_record(array $store, array $user, array $head, array $lines, array $totals): int
{
    [$number, $fy] = next_invoice_number($store, $head['invoice_type'], $head['invoice_date']);

    // V3 columns are only written when used, so plain bills keep working even before "Update database" is pressed.
    foreach (['table_no', 'coupon_code', 'coupon_discount', 'loyalty_points_redeemed', 'loyalty_discount', 'loyalty_points_earned', 'approved_by', 'client_uuid', 'offline_at'] as $k) {
        if (array_key_exists($k, $head) && ($head[$k] === null || $head[$k] === '' || $head[$k] === 0 || $head[$k] === 0.0)) {
            unset($head[$k]);
        }
    }
    $invoiceId = insert_row('invoices', [
        'invoice_number' => $number,
        'fy' => $fy,
        'store_id' => (int) $store['store_id'],
        'user_id' => (int) $user['user_id'],
    ] + pick($head, [
        'invoice_type', 'order_id', 'customer_id', 'customer_name', 'customer_phone', 'customer_email', 'customer_gstin',
        'billing_address', 'shipping_address', 'place_of_supply', 'place_of_supply_code', 'is_interstate', 'reverse_charge',
        'invoice_date', 'due_date', 'order_type', 'notes', 'terms', 'eway_bill_no', 'vehicle_no',
        'table_no', 'coupon_code', 'coupon_discount', 'loyalty_points_redeemed', 'loyalty_discount', 'loyalty_points_earned',
        'approved_by', 'client_uuid', 'offline_at',
    ]) + pick($totals, [
        'subtotal', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount',
        'round_off', 'total_amount',
    ]) + [
        'amount_paid' => 0,
        'balance_due' => $totals['total_amount'],
        'status' => 'Unpaid',
    ]);

    foreach ($lines as $l) {
        insert_row('invoice_items', ['invoice_id' => $invoiceId] + pick($l, [
            'product_id', 'product_name', 'hsn_code', 'unit', 'quantity', 'unit_price', 'cost_price', 'discount_amount',
            'price_includes_tax', 'taxable_value', 'gst_rate', 'cgst_rate', 'cgst_amount', 'sgst_rate', 'sgst_amount',
            'igst_rate', 'igst_amount', 'tax_amount', 'line_total', 'item_note',
        ]));
        if ($l['product_id']) {
            move_stock((int) $l['product_id'], -$l['quantity'], 'sale', 'invoice', $invoiceId, $number, (int) $user['user_id']);
        }
    }
    stats_touch($store['store_id'], $head['invoice_date'] ?? date('Y-m-d'));
    return $invoiceId;
}

/** Recalculate amount_paid / balance_due / status from the payments table. */
function refresh_invoice_payment_status(int $invoiceId): void
{
    $inv = db_one("SELECT total_amount, status, store_id, invoice_date FROM invoices WHERE invoice_id = ?", [$invoiceId]);
    if (!$inv) {
        return;
    }
    $paid = money(db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?", [$invoiceId]));
    $total = money($inv['total_amount']);
    $balance = max(0, money($total - $paid));
    if ($inv['status'] === 'Cancelled') {
        $status = 'Cancelled';
    } elseif ($balance <= 0) {
        $status = 'Paid';
    } elseif ($paid > 0) {
        $status = 'Partial';
    } else {
        $status = 'Unpaid';
    }
    db_query("UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ? WHERE invoice_id = ?", [$paid, $balance, $status, $invoiceId]);
    stats_touch($inv['store_id'], $inv['invoice_date']);
}

function record_payment(array $invoice, array $user, array $p): int
{
    $amount = money($p['amount'] ?? 0);
    if ($amount <= 0) {
        fail('Payment amount must be greater than zero');
    }
    if ($invoice['status'] === 'Cancelled') {
        fail('Cannot record a payment for a cancelled invoice', 409);
    }
    $due = money($invoice['balance_due']);
    if ($amount - $due > 0.009) {
        fail('Payment is more than the balance due (₹' . number_format($due, 2) . ')', 422);
    }
    $method = str_field($p, 'payment_method', str_field($p, 'method', 'Cash'));
    if (!in_array($method, PAYMENT_METHODS, true)) {
        fail('Invalid payment method');
    }
    $id = insert_row('payments', [
        'invoice_id' => (int) $invoice['invoice_id'],
        'order_id' => $invoice['order_id'] ?? null,
        'customer_id' => $invoice['customer_id'] ?? null,
        'store_id' => (int) $invoice['store_id'],
        'user_id' => (int) $user['user_id'],
        'amount' => $amount,
        'payment_method' => $method,
        'payment_date' => date_field($p, 'payment_date', date('Y-m-d')),
        'reference_no' => str_field($p, 'reference_no'),
        'notes' => str_field($p, 'notes'),
        'status' => 'Completed',
    ] + (($session = open_cash_session((int) $invoice['store_id'])) ? ['cash_session_id' => (int) $session['session_id']] : []));
    // ^ Day close: the payment counts in the cash register session that is open right now.
    stats_touch($invoice['store_id'], date_field($p, 'payment_date', date('Y-m-d')));
    refresh_invoice_payment_status((int) $invoice['invoice_id']);
    return $id;
}

function load_invoice(int $invoiceId): ?array
{
    $inv = db_one(
        "SELECT i.*, u.name AS user_name, c.customer_type, o.order_number
         FROM invoices i
         LEFT JOIN users u ON u.user_id = i.user_id
         LEFT JOIN customers c ON c.customer_id = i.customer_id
         LEFT JOIN orders o ON o.order_id = i.order_id
         WHERE i.invoice_id = ?",
        [$invoiceId]
    );
    if (!$inv) {
        return null;
    }
    $inv = doc_cast($inv);
    $inv['items'] = array_map('doc_cast', db_all("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY invoice_item_id", [$invoiceId]));
    $inv['payments'] = array_map('doc_cast', db_all(
        "SELECT p.*, u.name AS received_by FROM payments p LEFT JOIN users u ON u.user_id = p.user_id WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id",
        [$invoiceId]
    ));
    $inv['store'] = db_one("SELECT * FROM stores WHERE store_id = ?", [$inv['store_id']]);
    $inv['total_qty'] = round(array_sum(array_column($inv['items'], 'quantity')), 3);

    // HSN-wise tax summary (required on B2B tax invoices).
    $hsn = [];
    foreach ($inv['items'] as $it) {
        $k = ($it['hsn_code'] ?: '-') . '|' . $it['gst_rate'];
        $hsn[$k] ??= ['hsn_code' => $it['hsn_code'] ?: '-', 'gst_rate' => $it['gst_rate'], 'taxable_value' => 0.0,
                      'cgst_amount' => 0.0, 'sgst_amount' => 0.0, 'igst_amount' => 0.0, 'tax_amount' => 0.0];
        foreach (['taxable_value', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount'] as $f) {
            $hsn[$k][$f] = round($hsn[$k][$f] + $it[$f], 2);
        }
    }
    $inv['hsn_summary'] = array_values($hsn);
    return $inv;
}

/**
 * Customers with bills or orders are never removed from the database (their bills must stay intact):
 * "delete" sets customers.deleted_at, which hides them from the customer list, search and messages.
 * Returns the SQL condition for "not deleted" (empty before "Update database" adds the column).
 */
function customer_active_sql(string $alias = ''): string
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db_one("SHOW COLUMNS FROM customers LIKE 'deleted_at'");
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready ? ' AND ' . ($alias !== '' ? "$alias." : '') . 'deleted_at IS NULL' : '';
}
