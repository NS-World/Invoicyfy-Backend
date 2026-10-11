<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$me = require_auth();

$id = int_field($_GET, 'user_id');
$row = $id ? db_one("SELECT " . USER_COLUMNS . " FROM users u LEFT JOIN roles r ON u.role_id = r.role_id LEFT JOIN stores s ON u.store_id = s.store_id WHERE u.user_id = ?", [$id]) : null;
if (!$row) {
    fail('User not found', 404);
}
if (!is_admin($me) && (int) $row['user_id'] !== (int) $me['user_id']) {
    assert_store_access($me, $row['store_id']);
}
json_out(["status" => "success", "data" => $row]);
