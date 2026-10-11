<?php
function product_payload(array $d, array $user): array
{
    $name = str_field($d, 'name');
    if (!$name) {
        fail('Product name is required');
    }
    $price = num_field($d, 'price');
    if ($price === null || $price < 0) {
        fail('Enter a valid selling price');
    }
    $rate = num_field($d, 'gst_rate', 0);
    if ($rate < 0 || $rate > 100) {
        fail('Invalid GST rate');
    }
    $hsn = str_field($d, 'hsn_code');
    if ($hsn && !preg_match('/^[0-9]{4,8}$/', $hsn)) {
        fail('HSN/SAC code must be 4 to 8 digits');
    }
    $status = ucfirst(strtolower((string) str_field($d, 'status', 'Active')));
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        fail('status must be Active or Inactive');
    }
    return [
        'store_id' => write_store($user, $d['store_id'] ?? null),
        'name' => $name,
        'sku' => str_field($d, 'sku'),
        'description' => str_field($d, 'description'),
        'category' => str_field($d, 'category'),
        'hsn_code' => $hsn,
        'price' => round($price, 2),
        'cost_price' => round(max(0, num_field($d, 'cost_price', 0)), 2),
        'gst_rate' => $rate,
        'price_includes_tax' => bool_field($d, 'price_includes_tax') ? 1 : 0,
        'unit' => str_field($d, 'unit', 'pcs'),
        'track_inventory' => bool_field($d, 'track_inventory', true) ? 1 : 0,
        'low_stock_threshold' => max(0, num_field($d, 'low_stock_threshold', 0)),
        'status' => $status,
    ];
}

function product_duplicate_check(array $p, int $excludeId = 0): void
{
    if (db_value("SELECT 1 FROM products WHERE store_id = ? AND name = ? AND product_id <> ? LIMIT 1", [$p['store_id'], $p['name'], $excludeId])) {
        fail('A product with this name already exists in this store', 409);
    }
    if ($p['sku'] && db_value("SELECT 1 FROM products WHERE sku = ? AND product_id <> ? LIMIT 1", [$p['sku'], $excludeId])) {
        fail('SKU already exists', 409);
    }
}

const PRODUCT_FLOATS = ['price', 'cost_price', 'gst_rate', 'stock_qty', 'low_stock_threshold'];
const PRODUCT_INTS = ['product_id', 'store_id', 'price_includes_tax', 'track_inventory'];
