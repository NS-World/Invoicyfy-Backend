<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
$user = require_auth();

$d = input();
$id = int_field($d, 'expense_id');
$existing = $id ? db_one("SELECT * FROM expenses WHERE expense_id = ?", [$id]) : null;
if (!$existing) {
    fail('Expense not found', 404);
}
assert_store_access($user, $existing['store_id']);
update_row('expenses', 'expense_id', $id, expense_payload($d, $user));
json_out(["status" => "success", "message" => "Expense updated"]);
