<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
$me = require_auth();

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
if (strcasecmp($existing, 'Admin') === 0) {
    db_query("UPDATE roles SET role_name = ? WHERE role_id = ?", [$name, $id]); // Admin always has every permission
} else {
    $perms = array_key_exists('permissions', $data) ? guard_role_permissions($me, $id, (array) $data['permissions']) : null;
    db_query("UPDATE roles SET role_name = ?, permissions = COALESCE(?, permissions) WHERE role_id = ?", [$name, $perms === null ? null : json_encode($perms), $id]);
}
json_out(["status" => "success", "message" => "Role updated successfully"]);
