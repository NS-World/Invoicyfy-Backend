<?php
/**
 * GST calculation helpers shared by orders and invoices.
 *
 * Every line carries its own GST rate. Prices can be tax-exclusive (B2B style, tax added on top)
 * or tax-inclusive (retail MRP style, tax extracted from the price).
 * Intra-state supply splits tax into CGST + SGST, inter-state supply uses IGST.
 */

const GST_RATES = [0, 0.1, 0.25, 1, 1.5, 3, 5, 6, 7.5, 12, 18, 28, 40];

const GST_STATES = [
    '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
    '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
    '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
    '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
    '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
    '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '28' => 'Andhra Pradesh (Old)',
    '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu',
    '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh',
    '38' => 'Ladakh', '97' => 'Other Territory',
];

function valid_gstin(?string $gstin): bool
{
    if ($gstin === null || $gstin === '') {
        return true;
    }
    return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper($gstin));
}

function normalize_gstin(?string $gstin): ?string
{
    if ($gstin === null || trim($gstin) === '') {
        return null;
    }
    $g = strtoupper(preg_replace('/\s+/', '', $gstin));
    if (!valid_gstin($g)) {
        fail('Invalid GSTIN format (example: 24ABCDE1234F1Z5)');
    }
    return $g;
}

function state_name(?string $code): ?string
{
    if ($code === null || $code === '') {
        return null;
    }
    return GST_STATES[str_pad($code, 2, '0', STR_PAD_LEFT)] ?? null;
}

/** Resolve state code from an explicit code, a GSTIN, or a state name. */
function resolve_state_code(?string $code, ?string $gstin = null, ?string $stateName = null): ?string
{
    if ($code !== null && $code !== '') {
        $code = str_pad($code, 2, '0', STR_PAD_LEFT);
        return isset(GST_STATES[$code]) ? $code : null;
    }
    if ($gstin) {
        $c = substr($gstin, 0, 2);
        if (isset(GST_STATES[$c])) {
            return $c;
        }
    }
    if ($stateName) {
        $found = array_search(strtolower(trim($stateName)), array_map('strtolower', GST_STATES), true);
        if ($found !== false) {
            return (string) $found;
        }
    }
    return null;
}

/**
 * Normalise raw item input and look up product defaults (name, HSN, rate, unit, cost).
 * Returns items ready for gst_calculate().
 */
function prepare_items(array $rawItems): array
{
    if (!$rawItems) {
        fail('Add at least one item');
    }
    $items = [];
    foreach ($rawItems as $i => $raw) {
        if (!is_array($raw)) {
            fail('Invalid item at row ' . ($i + 1));
        }
        $row = $i + 1;
        $productId = int_field($raw, 'product_id');
        $product = null;
        if ($productId) {
            $product = db_one("SELECT * FROM products WHERE product_id = ?", [$productId]);
            if (!$product) {
                fail("Product not found at row $row");
            }
        }
        $name = str_field($raw, 'product_name', $product['name'] ?? null);
        if (!$name) {
            fail("Select a product or enter an item name at row $row");
        }
        $qty = num_field($raw, 'quantity', 1);
        if ($qty <= 0) {
            fail("Quantity must be greater than zero at row $row");
        }
        $price = num_field($raw, 'unit_price', isset($product['price']) ? (float) $product['price'] : null);
        if ($price === null || $price < 0) {
            fail("Enter a valid price at row $row");
        }
        $rate = num_field($raw, 'gst_rate', isset($product['gst_rate']) ? (float) $product['gst_rate'] : 0);
        if ($rate < 0 || $rate > 100) {
            fail("Invalid GST rate at row $row");
        }
        $discount = max(0, num_field($raw, 'discount_amount', 0));
        if ($discount > $qty * $price) {
            fail("Discount cannot exceed the line amount at row $row");
        }
        $inclusive = array_key_exists('price_includes_tax', $raw)
            ? bool_field($raw, 'price_includes_tax')
            : (bool) ($product['price_includes_tax'] ?? false);

        $items[] = [
            'product_id' => $productId,
            'product_name' => mb_substr($name, 0, 255),
            'hsn_code' => str_field($raw, 'hsn_code', $product['hsn_code'] ?? null),
            'unit' => str_field($raw, 'unit', $product['unit'] ?? null),
            'quantity' => round($qty, 3),
            'unit_price' => round($price, 2),
            'discount_amount' => round($discount, 2),
            'gst_rate' => round($rate, 2),
            'price_includes_tax' => $inclusive ? 1 : 0,
            'cost_price' => isset($product['cost_price']) ? (float) $product['cost_price'] : 0.0,
            'item_note' => str_field($raw, 'item_note'),
        ];
    }
    return apply_recipe_costs($items); // V4: items made from a recipe cost what their ingredients cost
}

/** Compute line-wise tax and document totals. */
function gst_calculate(array $items, bool $interstate, bool $roundOff = true): array
{
    $lines = [];
    $t = ['subtotal' => 0.0, 'discount_amount' => 0.0, 'taxable_amount' => 0.0, 'cgst_amount' => 0.0,
          'sgst_amount' => 0.0, 'igst_amount' => 0.0, 'tax_amount' => 0.0, 'total_qty' => 0.0];

    foreach ($items as $it) {
        $gross = round($it['quantity'] * $it['unit_price'], 2);
        $net = round($gross - $it['discount_amount'], 2);
        $rate = (float) $it['gst_rate'];
        if ($it['price_includes_tax']) {
            $taxable = round($net * 100 / (100 + $rate), 2);
            $tax = round($net - $taxable, 2);
        } else {
            $taxable = $net;
            $tax = round($taxable * $rate / 100, 2);
        }
        if ($interstate) {
            $cgst = $sgst = 0.0;
            $igst = $tax;
        } else {
            $cgst = round($tax / 2, 2);
            $sgst = round($tax - $cgst, 2);
            $igst = 0.0;
        }
        $line = $it + [
            'taxable_value' => $taxable,
            'cgst_rate' => $interstate ? 0 : $rate / 2,
            'sgst_rate' => $interstate ? 0 : $rate / 2,
            'igst_rate' => $interstate ? $rate : 0,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => $igst,
            'tax_amount' => $tax,
            'line_total' => round($taxable + $tax, 2),
        ];
        $lines[] = $line;

        $t['subtotal'] += $gross;
        $t['discount_amount'] += $it['discount_amount'];
        $t['taxable_amount'] += $taxable;
        $t['cgst_amount'] += $cgst;
        $t['sgst_amount'] += $sgst;
        $t['igst_amount'] += $igst;
        $t['tax_amount'] += $tax;
        $t['total_qty'] += $it['quantity'];
    }
    foreach ($t as $k => $v) {
        $t[$k] = round($v, $k === 'total_qty' ? 3 : 2);
    }
    $exact = round($t['taxable_amount'] + $t['tax_amount'], 2);
    $total = $roundOff ? round($exact) : $exact;
    $t['round_off'] = round($total - $exact, 2);
    $t['total_amount'] = round($total, 2);

    return [$lines, $t];
}
