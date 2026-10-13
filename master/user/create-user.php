<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$me = require_auth();

$p = user_payload(input(), true);
guard_admin_role($me, (int) $p['role_id']);
user_duplicate_check($p);
$cols = array_keys($p);
db_query(
    "INSERT INTO users (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")",
    array_values($p)
);
$newId = (int) $pdo->lastInsertId();
log_activity('user.create', 'user', $newId, "Created user {$p['name']} ({$p['email']})", [], $p['store_id']);
json_out(["status" => "success", "message" => "User created successfully", "user_id" => $newId], 201);
