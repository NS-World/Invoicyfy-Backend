<?php
require_once __DIR__ . '/../lib/bootstrap.php';
allow_methods('POST');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$refreshToken = (string) (input()['refresh_token'] ?? '');
if ($refreshToken === '') {
    fail('Refresh token is required');
}

try {
    $decoded = JWT::decode($refreshToken, new Key($refreshKey, 'HS256'));
} catch (Throwable $e) {
    fail('Session expired. Please login again.', 401);
}
if (isset($decoded->typ) && $decoded->typ !== 'refresh') {
    fail('Invalid refresh token', 401);
}

$user = load_user((int) ($decoded->sub ?? 0));
if (!$user || !(int) $user['is_active']) {
    fail('User not found or disabled', 401);
}

[$access, $refresh] = issue_tokens($user);

json_out([
    "status" => "success",
    "message" => "Access token refreshed",
    "access_token" => $access,
    "refresh_token" => $refresh,
    "user" => public_user($user),
]);
