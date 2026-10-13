<?php
/** Remove a held bill (after it is resumed, or discarded): POST { held_id, store_id }. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'DELETE');
$user = require_auth();

$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$id = int_field($d, 'held_id');
$row = $id ? db_one('SELECT * FROM held_bills WHERE held_id = ? AND store_id = ?', [$id, $storeId]) : null;
if (!$row) {
    fail('This held bill was already resumed or deleted', 404);
}
db_query('DELETE FROM held_bills WHERE held_id = ?', [$id]);
if (!bool_field($d, 'resumed')) {
    log_activity('bill.hold_delete', 'held_bill', $id, 'Discarded held bill ' . ($row['label'] ?: "#$id") . ' ' . rupees($row['total']), [], $storeId);
}
json_out(['status' => 'success', 'message' => 'Held bill removed']);
