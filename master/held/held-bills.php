<?php
/** GET ?store_id= — bills parked with "Hold" (newest first). ?held_id= returns one with its saved cart. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$storeId = write_store($user, $_GET['store_id'] ?? null);
$cast = fn($r) => cast_numbers($r, ['total'], ['held_id', 'store_id', 'user_id', 'item_count']);
if ($id = int_field($_GET, 'held_id')) {
    $row = db_one('SELECT h.*, u.name AS user_name FROM held_bills h LEFT JOIN users u ON u.user_id = h.user_id WHERE h.held_id = ? AND h.store_id = ?', [$id, $storeId]);
    if (!$row) {
        fail('This held bill was already resumed or deleted', 404);
    }
    $row = $cast($row);
    $row['payload'] = json_decode($row['payload'], true);
    json_out(['status' => 'success', 'data' => $row]);
}
$rows = db_all(
    'SELECT h.held_id, h.store_id, h.user_id, h.label, h.table_no, h.customer_name, h.item_count, h.total, h.created_at, h.updated_at, u.name AS user_name
     FROM held_bills h LEFT JOIN users u ON u.user_id = h.user_id WHERE h.store_id = ? ORDER BY COALESCE(h.updated_at, h.created_at) DESC LIMIT 100',
    [$storeId]
);
json_out(['status' => 'success', 'total' => count($rows), 'data' => array_map($cast, $rows)]);
