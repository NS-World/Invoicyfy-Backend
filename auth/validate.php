<?php
require_once __DIR__ . '/jwt_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();

if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(["error" => "Authorization header missing"]);
    exit;
}

$authHeader = $headers['Authorization'];
list($type, $token) = explode(" ", $authHeader);

if ($type !== 'Bearer') {
    http_response_code(401);
    echo json_encode(["error" => "Invalid auth type"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($accessKey, 'HS256'));
    $user_id = $decoded->sub; 
    // You can now access $decoded->user_id or whatever payload you encoded
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token", "details" => $e->getMessage()]);
    exit;
}
