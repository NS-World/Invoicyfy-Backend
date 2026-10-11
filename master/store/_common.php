<?php
function store_payload(array $d): array
{
    $name = str_field($d, 'name');
    if (!$name) {
        fail('Business / store name is required');
    }
    $gstin = normalize_gstin(str_field($d, 'gstin'));
    $stateCode = resolve_state_code(str_field($d, 'state_code'), $gstin, str_field($d, 'state'));
    $email = str_field($d, 'email');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address');
    }
    $pan = str_field($d, 'pan');
    if ($pan) {
        $pan = strtoupper($pan);
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            fail('Invalid PAN format');
        }
    }
    $ifsc = str_field($d, 'bank_ifsc');
    if ($ifsc) {
        $ifsc = strtoupper($ifsc);
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            fail('Invalid IFSC code');
        }
    }
    $prefix = str_field($d, 'invoice_prefix', 'INV');
    if (!preg_match('/^[A-Za-z0-9\-]{1,6}$/', $prefix)) {
        fail('Invoice prefix must be 1-6 letters/numbers');
    }
    $billPrefix = (string) str_field($d, 'bill_prefix', '');
    if ($billPrefix !== '' && !preg_match('/^[A-Za-z0-9\-]{1,6}$/', $billPrefix)) {
        fail('Bill prefix must be up to 6 letters/numbers');
    }
    return [
        'name' => $name,
        'legal_name' => str_field($d, 'legal_name'),
        'tagline' => str_field($d, 'tagline'),
        'address' => str_field($d, 'address'),
        'city' => str_field($d, 'city'),
        'state' => $stateCode ? state_name($stateCode) : str_field($d, 'state'),
        'state_code' => $stateCode,
        'pincode' => str_field($d, 'pincode'),
        'phone' => str_field($d, 'phone'),
        'email' => $email,
        'gstin' => $gstin,
        'pan' => $pan,
        'fssai_no' => str_field($d, 'fssai_no'),
        'logo_url' => str_field($d, 'logo_url'),
        'invoice_prefix' => strtoupper($prefix),
        'bill_prefix' => strtoupper($billPrefix),
        'bank_name' => str_field($d, 'bank_name'),
        'bank_account_name' => str_field($d, 'bank_account_name'),
        'bank_account_no' => str_field($d, 'bank_account_no'),
        'bank_ifsc' => $ifsc,
        'bank_branch' => str_field($d, 'bank_branch'),
        'upi_id' => str_field($d, 'upi_id'),
        'invoice_terms' => str_field($d, 'invoice_terms'),
        'receipt_footer' => str_field($d, 'receipt_footer'),
    ];
}

function can_edit_store(array $user, int $storeId): void
{
    if (is_admin($user)) {
        return;
    }
    if (strcasecmp((string) $user['role_name'], 'Manager') === 0 && (int) $user['store_id'] === $storeId) {
        return;
    }
    fail('You are not allowed to edit this store', 403);
}
