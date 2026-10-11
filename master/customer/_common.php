<?php
/** Shared customer field handling. */
function customer_payload(array $d): array
{
    $type = strtoupper((string) str_field($d, 'customer_type', 'B2C'));
    if (!in_array($type, ['B2C', 'B2B'], true)) {
        fail('customer_type must be B2C or B2B');
    }
    $name = str_field($d, 'name');
    if (!$name) {
        fail('Customer name is required');
    }
    $gstin = normalize_gstin(str_field($d, 'gstin'));
    if ($type === 'B2B' && !$gstin) {
        fail('GSTIN is required for a B2B (registered) customer');
    }
    $email = str_field($d, 'email');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address');
    }
    $phone = str_field($d, 'phone');
    if ($phone && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
        fail('Enter a valid phone number');
    }
    $stateCode = resolve_state_code(str_field($d, 'state_code'), $gstin, str_field($d, 'state'));
    $optIn = array_key_exists('marketing_opt_in', $d) ? ['marketing_opt_in' => bool_field($d, 'marketing_opt_in', true) ? 1 : 0] : [];
    return $optIn + [
        'customer_type' => $type,
        'name' => $name,
        'company_name' => str_field($d, 'company_name'),
        'phone' => $phone,
        'email' => $email,
        'address' => str_field($d, 'address'),
        'city' => str_field($d, 'city'),
        'state' => $stateCode ? state_name($stateCode) : str_field($d, 'state'),
        'state_code' => $stateCode,
        'pincode' => str_field($d, 'pincode'),
        'gstin' => $gstin,
        'shipping_address' => str_field($d, 'shipping_address'),
        'notes' => str_field($d, 'notes'),
    ];
}

function customer_duplicate_check(array $p, int $excludeId = 0): void
{
    if ($p['phone']) {
        $dup = db_value("SELECT customer_id FROM customers WHERE phone = ? AND customer_id <> ? LIMIT 1", [$p['phone'], $excludeId]);
        if ($dup) {
            fail('A customer with this phone number already exists', 409);
        }
    }
    if ($p['gstin']) {
        $dup = db_value("SELECT customer_id FROM customers WHERE gstin = ? AND customer_id <> ? LIMIT 1", [$p['gstin'], $excludeId]);
        if ($dup) {
            fail('A customer with this GSTIN already exists', 409);
        }
    }
}
