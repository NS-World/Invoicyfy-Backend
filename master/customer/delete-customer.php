<?php
/**
 * Deletes a customer and everything linked to them: bills (with items), payments, orders, loyalty points,
 * personal coupons and messages. POST {customer_id, preview: true} only lists what would be removed
 * (the app shows it in red before asking to confirm).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cascade.php';
allow_methods('PUT', 'POST', 'DELETE');
require_auth();

$d = input();
$id = int_field($d, 'customer_id');
if (!$id) {
    fail('customer_id is required');
}
$customer = db_one('SELECT customer_id, name FROM customers WHERE customer_id = ?', [$id]);
if (!$customer) {
    fail('Customer not found', 404);
}
if (bool_field($d, 'preview')) {
    json_out(['status' => 'success', 'name' => $customer['name'], 'related' => customer_delete_plan($id)]);
}
$pdo->beginTransaction();
$plan = customer_delete_run($id);
$pdo->commit();
log_activity('customer.delete', 'customer', $id, "Deleted customer {$customer['name']}" . cascade_summary($plan));
json_out(['status' => 'success', 'message' => "Customer \"{$customer['name']}\" and all their records were deleted", 'related' => $plan]);
