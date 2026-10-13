<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('PUT', 'POST', 'DELETE');
$me = require_auth();

$id = int_field(input(), 'user_id');
if (!$id) {
    fail('user_id is required');
}
$target = load_user($id);
if ($target && is_admin($target) && !is_admin($me)) {
    fail('Only an Admin can delete an Admin account', 403);
}
if ($id === (int) $me['user_id']) {
    fail('You cannot delete your own account', 409);
}
$hasHistory = db_value("SELECT 1 FROM invoices WHERE user_id = ? LIMIT 1", [$id]) || db_value("SELECT 1 FROM orders WHERE user_id = ? LIMIT 1", [$id]);
log_activity('user.delete', 'user', $id, ($hasHistory ? 'Disabled' : 'Deleted') . ' user ' . ($target['name'] ?? "#$id"), [], $target['store_id'] ?? null);
if ($hasHistory) {
    db_query("UPDATE users SET is_active = 0 WHERE user_id = ?", [$id]);
    json_out(["status" => "success", "message" => "User has billing history, so the account was disabled instead of deleted"]);
}
if (!db_query("DELETE FROM users WHERE user_id = ?", [$id])->rowCount()) {
    fail('User not found', 404);
}
json_out(["status" => "success", "message" => "User deleted successfully"]);
