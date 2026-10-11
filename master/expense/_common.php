<?php
const DEFAULT_EXPENSE_CATEGORIES = [
    'Purchase / Raw Material', 'Rent', 'Salary & Wages', 'Electricity', 'Water', 'Internet & Phone',
    'Transport & Delivery', 'Repairs & Maintenance', 'Marketing', 'Packaging', 'Fuel', 'Bank Charges',
    'Taxes & Fees', 'Office Supplies', 'Miscellaneous',
];

function expense_payload(array $d, array $user): array
{
    $amount = num_field($d, 'amount');
    if ($amount === null || $amount <= 0) {
        fail('Enter a valid amount');
    }
    $category = str_field($d, 'category');
    if (!$category) {
        fail('Category is required');
    }
    $method = str_field($d, 'payment_method', 'Cash');
    if (!in_array($method, PAYMENT_METHODS, true)) {
        fail('Invalid payment method');
    }
    $tax = max(0, num_field($d, 'tax_amount', 0));
    if ($tax > $amount) {
        fail('GST amount cannot be more than the expense amount');
    }
    return [
        'store_id' => write_store($user, $d['store_id'] ?? null),
        'expense_date' => date_field($d, 'expense_date', date('Y-m-d')),
        'category' => mb_substr($category, 0, 100),
        'description' => str_field($d, 'description'),
        'vendor' => str_field($d, 'vendor'),
        'amount' => round($amount, 2),
        'tax_amount' => round($tax, 2),
        'payment_method' => $method,
        'reference_no' => str_field($d, 'reference_no'),
    ];
}
