<?php
/** Set or remove my own discount-approval PIN. POST { current_password, pin } ("" / remove: true removes it). */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../master/user/_common.php';
allow_methods('POST', 'PUT');

$user = require_auth();
$d = input();
$hash = db_value('SELECT password FROM users WHERE user_id = ?', [$user['user_id']]);
if (!password_verify((string) ($d['current_password'] ?? ''), (string) $hash)) {
    fail('Current password is incorrect', 422);
}
if (bool_field($d, 'remove')) {
    db_query('UPDATE users SET approval_pin = NULL WHERE user_id = ?', [$user['user_id']]);
    log_activity('user.pin', 'user', $user['user_id'], "{$user['name']} removed their approval PIN");
    json_out(['status' => 'success', 'message' => 'Approval PIN removed']);
}
db_query('UPDATE users SET approval_pin = ? WHERE user_id = ?', [approval_pin_hash((string) ($d['pin'] ?? '')), $user['user_id']]);
log_activity('user.pin', 'user', $user['user_id'], "{$user['name']} set an approval PIN");
json_out(['status' => 'success', 'message' => 'Approval PIN saved']);
