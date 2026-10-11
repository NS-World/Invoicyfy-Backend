<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'DELETE');
$me = require_auth();
require_admin($me);

$id = int_field(input(), 'role_id');
$name = $id ? db_value("SELECT role_name FROM roles WHERE role_id = ?", [$id]) : null;
if ($name === null) {
    fail('Role not found', 404);
}
if (strcasecmp($name, 'Admin') === 0) {
    fail('The Admin role cannot be deleted', 409);
}
if (db_value("SELECT 1 FROM users WHERE role_id = ? LIMIT 1", [$id])) {
    fail('Some users still have this role. Assign them another role first.', 409);
}
db_query("DELETE FROM roles WHERE role_id = ?", [$id]);
json_out(["status" => "success", "message" => "Role deleted successfully"]);
