<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "user_id is required and must be numeric"
    ]);
    exit;
}

$user_id = (int) $_GET['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.user_id,
            u.name,
            u.email,
            u.phone,
            u.city,
            u.store_id,
            u.role_id,
            u.created_at,
            r.role_name,
            s.name AS store_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN stores s ON u.store_id = s.store_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "User not found"
        ]);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "data" => $user
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
