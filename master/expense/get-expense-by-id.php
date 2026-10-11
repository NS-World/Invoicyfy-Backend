<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$id = int_field($_GET, 'expense_id');
$row = $id ? db_one("SELECT * FROM expenses WHERE expense_id = ?", [$id]) : null;
if (!$row) {
    fail('Expense not found', 404);
}
assert_store_access($user, $row['store_id']);
json_out(["status" => "success", "data" => cast_numbers($row, ['amount', 'tax_amount'], ['expense_id', 'store_id', 'user_id'])]);
