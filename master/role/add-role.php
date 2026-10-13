<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$me = require_auth();

$data = input();
$name = str_field($data, 'role_name');
if (!$name) {
    fail('Role name is required');
}
if (db_value("SELECT 1 FROM roles WHERE role_name = ?", [$name])) {
    fail('Role already exists', 409);
}
$perms = guard_role_permissions($me, null, (array) ($data['permissions'] ?? []));
db_query("INSERT INTO roles (role_name, permissions) VALUES (?, ?)", [$name, json_encode($perms)]);
$roleId = (int) $pdo->lastInsertId();
if ($limit = role_discount_input($me, $data)) {
    update_row('roles', 'role_id', $roleId, $limit);
}
log_activity('role.create', 'role', $roleId, "Created role $name");
json_out(["status" => "success", "message" => "Role added successfully", "role_id" => $roleId], 201);
