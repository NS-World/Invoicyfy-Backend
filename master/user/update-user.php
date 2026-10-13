<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('PUT', 'POST');
$me = require_auth();

$data = input();
$id = int_field($data, 'user_id');
if (!$id || !db_value("SELECT 1 FROM users WHERE user_id = ?", [$id])) {
    fail('User not found', 404);
}
$p = user_payload($data, false);
if ($id !== (int) $me['user_id']) {
    guard_admin_role($me, (int) $p['role_id'], $id);
}
if ($id === (int) $me['user_id'] && (!$p['is_active'] || $p['role_id'] !== (int) $me['role_id'])) {
    fail('You cannot disable yourself or change your own role', 409);
}
user_duplicate_check($p, $id);
$set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($p)));
db_query("UPDATE users SET $set WHERE user_id = ?", array_merge(array_values($p), [$id]));
log_activity('user.update', 'user', $id, "Updated user {$p['name']} ({$p['email']})" . (array_key_exists('password', $p) ? ', password changed' : '') . (array_key_exists('approval_pin', $p) ? ', approval PIN ' . ($p['approval_pin'] ? 'set' : 'removed') : ''), [], $p['store_id']);
json_out(["status" => "success", "message" => "User updated successfully"]);
