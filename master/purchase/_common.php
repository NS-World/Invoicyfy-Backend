<?php
const PURCHASE_FLOATS = ['subtotal', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'other_charges',
    'round_off', 'total_amount', 'amount_paid', 'balance_due', 'quantity', 'unit_cost', 'gst_rate', 'taxable_value', 'line_total', 'amount'];
const PURCHASE_INTS = ['purchase_id', 'store_id', 'supplier_id', 'is_interstate', 'user_id', 'purchase_item_id', 'product_id', 'batch_id', 'payment_id'];

function purchase_cast(array $r): array
{
    return cast_numbers($r, PURCHASE_FLOATS, PURCHASE_INTS);
}

/** Recalculate paid / balance / status of a purchase bill from its payments. */
function refresh_purchase_status(int $purchaseId): void
{
    $p = db_one('SELECT total_amount, status FROM purchases WHERE purchase_id = ?', [$purchaseId]);
    if (!$p) {
        return;
    }
    $paid = money(db_value('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE purchase_id = ?', [$purchaseId]));
    $balance = max(0, money((float) $p['total_amount'] - $paid));
    $status = $p['status'] === 'Cancelled' ? 'Cancelled' : ($balance <= 0 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid'));
    db_query('UPDATE purchases SET amount_paid = ?, balance_due = ?, status = ? WHERE purchase_id = ?', [$paid, $status === 'Cancelled' ? 0 : $balance, $status, $purchaseId]);
}

/**
 * Purchase lines from the screen: [{product_id | product_name, quantity, unit_cost, gst_rate, discount_amount, batch_no, expiry_date}].
 * Rates are before GST unless price_includes_tax is true. Returns [lines, totals] like gst_calculate().
 */
function purchase_lines(array $raw, bool $interstate, float $otherCharges): array
{
    if (!$raw || !is_array($raw)) {
        fail('Add at least one item');
    }
    if (count($raw) > 300) {
        fail('A purchase bill can have at most 300 items');
    }
    $items = [];
    foreach ($raw as $i => $r) {
        $row = $i + 1;
        if (!is_array($r)) {
            fail("Invalid item at row $row");
        }
        $pid = int_field($r, 'product_id');
        $product = $pid ? db_one('SELECT * FROM products WHERE product_id = ?', [$pid]) : null;
        if ($pid && !$product) {
            fail("Product not found at row $row");
        }
        $name = str_field($r, 'product_name', $product['name'] ?? null);
        if (!$name) {
            fail("Select a product at row $row");
        }
        $qty = num_field($r, 'quantity', 0);
        if ($qty <= 0) {
            fail("Quantity must be more than zero at row $row");
        }
        $cost = num_field($r, 'unit_cost', 0);
        if ($cost < 0) {
            fail("Rate cannot be negative at row $row");
        }
        $rate = num_field($r, 'gst_rate', isset($product['gst_rate']) ? (float) $product['gst_rate'] : 0);
        if ($rate < 0 || $rate > 100) {
            fail("Invalid GST rate at row $row");
        }
        $disc = max(0, num_field($r, 'discount_amount', 0));
        if ($disc > $qty * $cost) {
            fail("Discount is more than the amount at row $row");
        }
        $expiry = date_field($r, 'expiry_date');
        $batch = str_field($r, 'batch_no');
        if ($batch !== null && mb_strlen($batch) > 50) {
            fail("Batch number is too long at row $row");
        }
        $items[] = [
            'product_id' => $pid ?: null, 'product_name' => mb_substr($name, 0, 255),
            'hsn_code' => str_field($r, 'hsn_code', $product['hsn_code'] ?? null), 'unit' => str_field($r, 'unit', $product['unit'] ?? null),
            'quantity' => round($qty, 3), 'unit_price' => round($cost, 2), 'discount_amount' => round($disc, 2), 'gst_rate' => round($rate, 2),
            'price_includes_tax' => bool_field($r, 'price_includes_tax') ? 1 : 0, 'batch_no' => $batch, 'expiry_date' => $expiry,
        ];
    }
    [$lines, $t] = gst_calculate($items, $interstate, false);
    $exact = round($t['taxable_amount'] + $t['tax_amount'] + $otherCharges, 2);
    $total = round($exact);
    $t['other_charges'] = round($otherCharges, 2);
    $t['round_off'] = round($total - $exact, 2);
    $t['total_amount'] = $total;
    return [$lines, $t];
}

function load_purchase(array $user, int $id): array
{
    $p = db_one('SELECT p.*, s.name AS supplier, s.phone AS supplier_phone, u.name AS user_name FROM purchases p
                 LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id LEFT JOIN users u ON u.user_id = p.user_id WHERE p.purchase_id = ?', [$id]);
    if (!$p) {
        fail('Purchase bill not found', 404);
    }
    assert_store_access($user, $p['store_id']);
    $p = purchase_cast($p);
    $p['items'] = array_map('purchase_cast', db_all(
        'SELECT pi.*, b.qty_left AS batch_qty_left FROM purchase_items pi LEFT JOIN stock_batches b ON b.batch_id = pi.batch_id
         WHERE pi.purchase_id = ? ORDER BY pi.purchase_item_id', [$id]));
    $p['payments'] = array_map('purchase_cast', db_all(
        'SELECT sp.*, u.name AS paid_by FROM supplier_payments sp LEFT JOIN users u ON u.user_id = sp.user_id WHERE sp.purchase_id = ? ORDER BY sp.payment_date, sp.payment_id', [$id]));
    return $p;
}
