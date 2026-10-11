<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/jwt_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and password are required"]);
    exit;
}

try {
    //$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
     $stmt = $pdo->prepare("
        SELECT u.*, r.role_name 
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE u.email = ?
    ");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($data['password'], $user['password'])) {
        // JWT setup
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
            "sub" => $user['user_id'],   // subject (unique identifier)
            "roleId" => $user['role_id'],
            "role" => $user['role_name'],
            "jti" => bin2hex(random_bytes(8)) // unique token ID
        ];

        $refreshPayload = [
            "iss" => $issuer,
            "aud" => $audience,
            "iat" => $issuedAt,
            "exp" => $refreshExpire,
            "sub" => $user['user_id'],
            "roleId" => $user['role_id'],
            "role" => $user['role_name'],
            "jti" => bin2hex(random_bytes(8))
        ];

        $access_token = JWT::encode($accessPayload, $accessKey, 'HS256');
        $refresh_token = JWT::encode($refreshPayload, $refreshKey, 'HS256');

        echo json_encode([
            "status" => "success",
            "message" => "Login successful",
            "access_token" => $access_token,
            "refresh_token" => $refresh_token
            // "user" => [
            //     "user_id" => $user['user_id'],
            //     "name" => $user['name'],
            //     "email" => $user['email'],
            //     "role_id" => $user['role_id'],
            //     "role_name" => $user['role_name'], 
            //     "store_id" => $user['store_id']
            // ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
