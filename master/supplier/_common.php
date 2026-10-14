<?php
/** Supplier fields from the Suppliers screen, validated. */
function supplier_payload(array $d): array
{
    $name = str_field($d, 'name');
    if (!$name) {
        fail('Supplier name is required');
    }
    $phone = str_field($d, 'phone');
    if ($phone !== null && !preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
        fail('Phone number looks wrong');
    }
    $email = str_field($d, 'email');
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Email is not valid');
    }
    $gstin = normalize_gstin(str_field($d, 'gstin'));
    if ($gstin !== null && !valid_gstin($gstin)) {
        fail('GSTIN is not valid');
    }
    $opening = num_field($d, 'opening_balance', 0);
    if (abs($opening) > 100000000) {
        fail('Opening balance is too large');
    }
    return [
        'name' => mb_substr($name, 0, 255),
        'contact_person' => mb_substr((string) str_field($d, 'contact_person', ''), 0, 150) ?: null,
        'phone' => $phone,
        'email' => $email,
        'gstin' => $gstin,
        'state_code' => resolve_state_code(str_field($d, 'state_code'), $gstin),
        'address' => str_field($d, 'address'),
        'opening_balance' => round($opening, 2),
        'notes' => mb_substr((string) str_field($d, 'notes', ''), 0, 500) ?: null,
        'is_active' => bool_field($d, 'is_active', true) ? 1 : 0,
    ];
}

function load_supplier(array $user, int $id, bool $lock = false): array
{
    $s = db_one('SELECT * FROM suppliers WHERE supplier_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    if (!$s) {
        fail('Supplier not found', 404);
    }
    assert_store_access($user, $s['store_id']);
    return $s;
}

/** What we owe a supplier: opening balance + purchase bills - payments (cancelled bills excluded). */
function supplier_balance(int $supplierId): array
{
    $b = db_one(
        "SELECT COALESCE(SUM(total_amount),0) AS purchased, COALESCE(SUM(balance_due),0) AS bills_due, COUNT(*) AS bills
         FROM purchases WHERE supplier_id = ? AND status <> 'Cancelled'",
        [$supplierId]
    );
    $paid = (float) db_value('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id = ?', [$supplierId]);
    $opening = (float) db_value('SELECT opening_balance FROM suppliers WHERE supplier_id = ?', [$supplierId]);
    return [
        'purchased' => round((float) $b['purchased'], 2),
        'bills' => (int) $b['bills'],
        'paid' => round($paid, 2),
        'opening_balance' => round($opening, 2),
        'balance' => round($opening + (float) $b['purchased'] - $paid, 2),
    ];
}

function supplier_row(array $s): array
{
    return cast_numbers($s, ['opening_balance', 'purchased', 'paid', 'balance'], ['supplier_id', 'store_id', 'is_active', 'bills']);
}
