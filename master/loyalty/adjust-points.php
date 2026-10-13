<?php
/** Manual correction: POST { customer_id, points (+ add / - remove), note }. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$id = int_field($d, 'customer_id') ?? 0;
$points = num_field($d, 'points');
$note = str_field($d, 'note');
if ($points === null || $points == 0.0 || abs($points) > 1000000) {
    fail('Enter points to add (e.g. 50) or remove (e.g. -50)');
}
if (!$note) {
    fail('Write a reason for this correction');
}
$name = db_value('SELECT name FROM customers WHERE customer_id = ?', [$id]);
if ($name === null) {
    fail('Customer not found', 404);
}
$pdo->beginTransaction();
$balance = loyalty_post($id, $user['store_id'] !== null ? (int) $user['store_id'] : null, null, 'adjust', round($points, 2), $note, (int) $user['user_id']);
$pdo->commit();
log_activity('loyalty.adjust', 'customer', $id, "Points " . ($points > 0 ? '+' : '') . round($points, 2) . " for $name — $note (balance $balance)");
json_out(['status' => 'success', 'message' => "Points updated. Balance: $balance", 'points' => $balance]);
