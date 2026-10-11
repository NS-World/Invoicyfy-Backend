<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();

$p = expense_payload(input(), $user) + ['user_id' => (int) $user['user_id']];
$id = insert_row('expenses', $p);
json_out(["status" => "success", "message" => "Expense added", "expense_id" => $id], 201);
