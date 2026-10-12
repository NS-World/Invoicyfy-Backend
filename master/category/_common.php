<?php
/** Shared category field handling. A category gives new products a default unit (kg, ltr, pcs...), GST and sizes. */
require_once __DIR__ . '/../product/_common.php';

function category_payload(array $d): array
{
    $name = trim((string) str_field($d, 'name'));
    if ($name === '') {
        fail('Category name is required');
    }
    if (mb_strlen($name) > 100) {
        fail('Category name is too long (max 100 characters)');
    }
    $unit = trim((string) str_field($d, 'default_unit', 'pcs')) ?: 'pcs';
    if (mb_strlen($unit) > 50) {
        fail('Unit is too long');
    }
    $gst = num_field($d, 'gst_rate');
    if ($gst !== null && ($gst < 0 || $gst > 100)) {
        fail('GST rate must be between 0 and 100');
    }
    return [
        'name' => $name,
        'default_unit' => $unit,
        'gst_rate' => $gst,
        'hsn_code' => str_field($d, 'hsn_code'),
        'weight_presets' => clean_weight_presets($d['weight_presets'] ?? null),
        'description' => mb_substr((string) str_field($d, 'description', ''), 0, 255) ?: null,
        'sort_order' => int_field($d, 'sort_order', 0),
        'is_active' => bool_field($d, 'is_active', true) ? 1 : 0,
    ];
}

function category_row(array $r): array
{
    return cast_numbers($r, ['gst_rate'], ['category_id', 'sort_order', 'is_active', 'product_count']);
}

function category_name_taken(string $name, int $exceptId = 0): bool
{
    return (bool) db_value('SELECT 1 FROM categories WHERE name = ? AND category_id <> ?', [$name, $exceptId]);
}
