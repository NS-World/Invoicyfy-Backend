<?php
require_once __DIR__ . '/jwt_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$issuer = "Invoicify";
$audience = "Invoices Made Easy";
$issuedAt = time();
$accessExpire = $issuedAt + 300;      // 5 mins
$refreshExpire = $issuedAt + 86400;   // 24 hours

$accessPayload = [
    "iss" => $issuer,
    "aud" => $audience,
    "iat" => $issuedAt,
    "exp" => $accessExpire,
    "sub" => $userId // ✅ This is the key part you missed
];

$refreshPayload = [
    "iss" => $issuer,
    "aud" => $audience,
    "iat" => $issuedAt,
    "exp" => $refreshExpire,
    "sub" => $userId // ✅ Keep consistent across both tokens
];

$access_token = JWT::encode($accessPayload, $accessKey, 'HS256');
$refresh_token = JWT::encode($refreshPayload, $refreshKey, 'HS256');

header('Content-Type: application/json');
echo json_encode([
    "access_token" => $access_token,
    "refresh_token" => $refresh_token
]);
