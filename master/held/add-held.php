<?php
/**
 * Park a bill to finish later: POST { store_id, held_id?, label, table_no, customer_name, total, payload: {...cart...} }.
 * Sending held_id updates that held bill. The cart is stored as-is (max 200 KB) and re-checked when it is billed.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$payload = $d['payload'] ?? null;
if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
    fail('Add at least one item before holding the bill');
}
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if (strlen($json) > 200000) {
    fail('This bill is too large to hold');
}
$count = (int) db_value('SELECT COUNT(*) FROM held_bills WHERE store_id = ?', [$storeId]);
$row = [
    'store_id' => $storeId,
    'user_id' => (int) $user['user_id'],
    'label' => mb_substr((string) str_field($d, 'label', ''), 0, 100) ?: null,
    'table_no' => mb_substr((string) str_field($d, 'table_no', ''), 0, 20) ?: null,
    'customer_name' => mb_substr((string) str_field($d, 'customer_name', ''), 0, 255) ?: null,
    'item_count' => count($payload['items']),
    'total' => money(max(0, num_field($d, 'total', 0))),
    'payload' => $json,
];
if ($id = int_field($d, 'held_id')) {
    if (!db_value('SELECT 1 FROM held_bills WHERE held_id = ? AND store_id = ?', [$id, $storeId])) {
        fail('This held bill was already resumed or deleted', 404);
    }
    update_row('held_bills', 'held_id', $id, $row);
} else {
    if ($count >= 50) {
        fail('50 bills are already on hold. Finish or delete some first.', 409);
    }
    $id = insert_row('held_bills', $row);
}
log_activity('bill.hold', 'held_bill', $id, 'Held bill ' . ($row['label'] ?: ($row['table_no'] ? "table {$row['table_no']}" : "#$id")) . ' ' . rupees($row['total']), [], $storeId);
json_out(['status' => 'success', 'message' => 'Bill put on hold', 'held_id' => $id], 201);
