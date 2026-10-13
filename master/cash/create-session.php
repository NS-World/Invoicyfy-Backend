<?php
/** Open the day: POST { store_id, opening_cash, notes }. Only one open day per store. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$opening = num_field($d, 'opening_cash', 0);
if ($opening < 0 || $opening > 10000000) {
    fail('Opening cash must be between 0 and 1,00,00,000');
}
$denoms = clean_denominations($d['denominations'] ?? null);
try {
    $id = insert_row('cash_sessions', [
        'store_id' => $storeId,
        'status' => 'Open',
        'opened_by' => (int) $user['user_id'],
        'opened_at' => date('Y-m-d H:i:s'),
        'opening_cash' => money($opening),
        'denominations' => $denoms ? json_encode(['opening' => $denoms]) : null,
        'notes' => mb_substr((string) str_field($d, 'notes', ''), 0, 500) ?: null,
        'open_lock' => $storeId,
    ]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) == 1062) {
        fail('The day is already open for this store. Close it before opening a new one.', 409);
    }
    throw $e;
}
log_activity('cash.open', 'cash_session', $id, 'Opened the day with ' . rupees($opening) . ' cash', [], $storeId);
json_out(['status' => 'success', 'message' => 'Day opened', 'data' => cash_session_full(load_cash_session($user, $id))], 201);
