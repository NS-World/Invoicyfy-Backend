<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$me = require_auth();
require_admin($me);

$p = user_payload(input(), true);
user_duplicate_check($p);
$cols = array_keys($p);
db_query(
    "INSERT INTO users (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
    array_values($p)
);
json_out(["status" => "success", "message" => "User created successfully", "user_id" => (int) $pdo->lastInsertId()], 201);
