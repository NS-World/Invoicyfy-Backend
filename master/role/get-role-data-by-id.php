<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET', 'PUT');
require_auth();

$id = int_field($_GET, 'role_id');
$row = $id ? db_one("SELECT * FROM roles WHERE role_id = ?", [$id]) : null;
if (!$row) {
    fail('Role not found', 404);
}
json_out(["status" => "success", "data" => role_row($row)]);
