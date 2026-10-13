<?php
/** GET ?customer_id=&limit= — points balance and history of a customer. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$id = int_field($_GET, 'customer_id') ?? 0;
$c = db_one('SELECT customer_id, name, phone, loyalty_points FROM customers WHERE customer_id = ?', [$id]);
if (!$c) {
    fail('Customer not found', 404);
}
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$rows = db_all(
    'SELECT l.entry_id, l.type, l.points, l.balance_after, l.note, l.created_at, l.invoice_id, i.invoice_number, u.name AS user_name
     FROM loyalty_ledger l LEFT JOIN invoices i ON i.invoice_id = l.invoice_id LEFT JOIN users u ON u.user_id = l.user_id
     WHERE l.customer_id = ? ORDER BY l.entry_id DESC LIMIT ?',
    [$id, $limit]
);
$store = get_store(write_store($user, $_GET['store_id'] ?? null));
$ls = loyalty_settings($store);
json_out(['status' => 'success', 'data' => [
    'customer_id' => (int) $c['customer_id'],
    'name' => $c['name'],
    'points' => (float) $c['loyalty_points'],
    'value' => money((float) $c['loyalty_points'] * (float) $ls['point_value']),
    'enabled' => (bool) $ls['enabled'],
    'history' => array_map(fn($r) => cast_numbers($r, ['points', 'balance_after'], ['entry_id', 'invoice_id']), $rows),
]]);
