<?php
require_once __DIR__ . '/../lib/bootstrap.php';
allow_methods('POST');

$data = input();
$email = strtolower((string) str_field($data, 'email', ''));
$password = (string) ($data['password'] ?? '');

if ($email === '' || $password === '') {
    fail('Email and password are required');
}
// Brute-force guard: many wrong passwords from one address are blocked for 15 minutes.
try {
    $recentFails = (int) db_value("SELECT COUNT(*) FROM activity_log WHERE action = 'login.failed' AND ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)", [client_ip()]);
} catch (PDOException $e) {
    $recentFails = 0;
}
if ($recentFails >= 10) {
    fail('Too many wrong passwords. Please wait 15 minutes and try again.', 429);
}

$user = db_one(
    "SELECT u.*, r.role_name, r.permissions AS role_permissions, s.name AS store_name
     FROM users u
     LEFT JOIN roles r ON u.role_id = r.role_id
     LEFT JOIN stores s ON u.store_id = s.store_id
     WHERE LOWER(u.email) = ?",
    [$email]
);

if (!$user || !password_verify($password, $user['password'])) {
    usleep(300000); // slow down brute force a little
    log_activity('login.failed', 'user', $user['user_id'] ?? null, "Wrong password for $email", [], $user['store_id'] ?? null, ['user_id' => null, 'name' => null]);
    fail('Invalid email or password', 401);
}
if (!(int) $user['is_active']) {
    fail('Your account is disabled. Contact your administrator.', 403);
}

if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
    db_query("UPDATE users SET password = ? WHERE user_id = ?", [password_hash($password, PASSWORD_BCRYPT), $user['user_id']]);
}
db_query("UPDATE users SET last_login_at = NOW() WHERE user_id = ?", [$user['user_id']]);

[$access, $refresh] = issue_tokens($user);
log_activity('login', 'user', $user['user_id'], "{$user['name']} signed in", [], $user['store_id'], $user);

json_out([
    "status" => "success",
    "message" => "Login successful",
    "access_token" => $access,
    "refresh_token" => $refresh,
    "user" => public_user($user),
]);
