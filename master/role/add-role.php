<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$me = require_auth();
require_admin($me);

$name = str_field(input(), 'role_name');
if (!$name) {
    fail('Role name is required');
}
if (db_value("SELECT 1 FROM roles WHERE role_name = ?", [$name])) {
    fail('Role already exists', 409);
}
db_query("INSERT INTO roles (role_name) VALUES (?)", [$name]);
json_out(["status" => "success", "message" => "Role added successfully", "role_id" => (int) $pdo->lastInsertId()], 201);
