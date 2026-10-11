<?php
/** Validate input and compute everything needed to save an order. */
function build_order(array $d, array $user): array
{
    $storeId = write_store($user, $d['store_id'] ?? null);
    $store = get_store($storeId);
    $party = resolve_party($d, $store);

    $orderType = str_field($d, 'order_type', 'Counter');
    if (!in_array($orderType, ORDER_TYPES, true)) {
        fail('Invalid order type');
    }
    $userId = int_field($d, 'user_id');
    if (!$userId || !is_admin($user) || !db_value("SELECT 1 FROM users WHERE user_id = ?", [$userId])) {
        $userId = (int) $user['user_id'];
    }
    $items = prepare_items($d['items'] ?? []);
    [$lines, $totals] = gst_calculate($items, (bool) $party['is_interstate']);

    $head = [
        'customer_id' => $party['customer_id'],
        'customer_name' => $party['customer_name'],
        'customer_phone' => $party['customer_phone'],
        'user_id' => $userId,
        'store_id' => $storeId,
        'order_date' => date_field($d, 'order_date', date('Y-m-d')),
        'order_type' => $orderType,
        'is_interstate' => $party['is_interstate'],
        'notes' => str_field($d, 'notes'),
    ] + pick($totals, ['subtotal', 'discount_amount', 'taxable_amount', 'tax_amount', 'round_off', 'total_amount']);

    return [$store, $head, $lines];
}
