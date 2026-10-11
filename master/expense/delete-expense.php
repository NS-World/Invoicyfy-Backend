<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('DELETE', 'POST', 'PUT');
$user = require_auth();

$id = int_field(input(), 'expense_id');
$row = $id ? db_one("SELECT store_id FROM expenses WHERE expense_id = ?", [$id]) : null;
if (!$row) {
    fail('Expense not found', 404);
}
assert_store_access($user, $row['store_id']);
db_query("DELETE FROM expenses WHERE expense_id = ?", [$id]);
json_out(["status" => "success", "message" => "Expense deleted"]);
