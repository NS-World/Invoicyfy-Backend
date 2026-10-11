<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST');
$me = require_auth();
require_admin($me);

$data = input();
$id = int_field($data, 'role_id');
$name = str_field($data, 'role_name');
if (!$id || !$name) {
    fail('role_id and role_name are required');
}
$existing = db_value("SELECT role_name FROM roles WHERE role_id = ?", [$id]);
if ($existing === null) {
    fail('Role not found', 404);
}
if (strcasecmp($existing, 'Admin') === 0 && strcasecmp($name, 'Admin') !== 0) {
    fail('The Admin role cannot be renamed', 409);
}
if (db_value("SELECT 1 FROM roles WHERE role_name = ? AND role_id <> ?", [$name, $id])) {
    fail('Another role with this name already exists', 409);
}
db_query("UPDATE roles SET role_name = ? WHERE role_id = ?", [$name, $id]);
json_out(["status" => "success", "message" => "Role updated successfully"]);
