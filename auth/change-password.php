<?php
require_once __DIR__ . '/../lib/bootstrap.php';
allow_methods('POST', 'PUT');

$user = require_auth();
$data = input();
$current = (string) ($data['current_password'] ?? '');
$new = (string) ($data['new_password'] ?? '');

if (strlen($new) < 8) {
    fail('New password must be at least 8 characters');
}
$hash = db_value("SELECT password FROM users WHERE user_id = ?", [$user['user_id']]);
if (!password_verify($current, (string) $hash)) {
    fail('Current password is incorrect', 422);
}
db_query("UPDATE users SET password = ? WHERE user_id = ?", [password_hash($new, PASSWORD_BCRYPT), $user['user_id']]);
json_out(["status" => "success", "message" => "Password changed successfully"]);
