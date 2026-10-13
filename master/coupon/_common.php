<?php
/** Coupon fields from the Coupons screen, validated. */
function coupon_payload(array $d): array
{
    $code = normalize_coupon_code(str_field($d, 'code'));
    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
        fail('Coupon code must be 3-40 letters / numbers (e.g. DIWALI20)');
    }
    $type = str_field($d, 'discount_type', 'percent');
    if (!in_array($type, ['percent', 'flat'], true)) {
        fail('Discount type must be percent or flat');
    }
    $value = num_field($d, 'value');
    if ($value === null || $value <= 0 || ($type === 'percent' && $value > 100) || $value > 1000000) {
        fail($type === 'percent' ? 'Discount % must be between 0 and 100' : 'Enter the discount amount');
    }
    $max = num_field($d, 'max_discount');
    if ($max !== null && $max <= 0) {
        $max = null;
    }
    $min = max(0, num_field($d, 'min_bill', 0));
    $from = date_field($d, 'valid_from');
    $to = date_field($d, 'valid_to');
    if ($from && $to && $to < $from) {
        fail('"Valid till" cannot be before "Valid from"');
    }
    $limit = int_field($d, 'usage_limit');
    $perCustomer = int_field($d, 'per_customer_limit');
    if (($limit !== null && $limit < 1) || ($perCustomer !== null && $perCustomer < 1)) {
        fail('Usage limits must be 1 or more (leave empty for unlimited)');
    }
    $customerId = int_field($d, 'customer_id');
    if ($customerId && !db_value('SELECT 1 FROM customers WHERE customer_id = ?', [$customerId])) {
        fail('Customer not found');
    }
    return [
        'code' => $code,
        'description' => mb_substr((string) str_field($d, 'description', ''), 0, 255) ?: null,
        'discount_type' => $type,
        'value' => round($value, 2),
        'max_discount' => $max !== null ? round($max, 2) : null,
        'min_bill' => round($min, 2),
        'valid_from' => $from,
        'valid_to' => $to,
        'usage_limit' => $limit,
        'per_customer_limit' => $perCustomer,
        'customer_id' => $customerId ?: null,
        'is_active' => bool_field($d, 'is_active', true) ? 1 : 0,
    ];
}

function coupon_row(array $c): array
{
    $c = cast_numbers($c, ['value', 'max_discount', 'min_bill', 'total_discount'],
        ['coupon_id', 'store_id', 'usage_limit', 'per_customer_limit', 'used_count', 'customer_id', 'is_active', 'created_by']);
    $today = date('Y-m-d');
    $c['state'] = !$c['is_active'] ? 'Off'
        : (($c['valid_to'] && $today > $c['valid_to']) ? 'Expired'
        : (($c['valid_from'] && $today < $c['valid_from']) ? 'Scheduled'
        : (($c['usage_limit'] !== null && $c['used_count'] >= $c['usage_limit']) ? 'Used up' : 'Active')));
    return $c;
}

function load_coupon(array $user, int $id): array
{
    $c = db_one('SELECT * FROM coupons WHERE coupon_id = ?', [$id]);
    if (!$c) {
        fail('Coupon not found', 404);
    }
    assert_store_access($user, $c['store_id']);
    return $c;
}
