<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/jwt_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$refresh_token = $data['refresh_token'] ?? '';

if (!$refresh_token) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Refresh token is required"]);
    exit;
}

try {
    // Decode the refresh token
    $decoded = JWT::decode($refresh_token, new Key($refreshKey, 'HS256'));
    
    // Get user ID from token
    $user_id = $decoded->sub ?? null;
    
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid refresh token: missing user ID"]);
        exit;
    }
    
    // Fetch fresh user data from database
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.name, u.email, u.role_id, r.role_name, u.store_id
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "User not found"]);
        exit;
    }
    
    // Generate new access token with complete user data
    $issuer = "Invoicify";
    $audience = "Invoices Made Easy";
    $issuedAt = time();
    $accessExpire = $issuedAt + 300;  // 5 minutes

    $accessPayload = [
        "iss" => $issuer,
        "aud" => $audience,
        "iat" => $issuedAt,
        "exp" => $accessExpire,
        "sub" => $user['user_id'],
        "roleId" => $user['role_id'],
        "role" => $user['role_name'],
        "jti" => bin2hex(random_bytes(8))
    ];

    $newAccessToken = JWT::encode($accessPayload, $accessKey, 'HS256');

    echo json_encode([
        "status" => "success",
        "message" => "Access token refreshed",
        "access_token" => $newAccessToken
    ]);
    
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid or expired refresh token: " . $e->getMessage()]);
}
