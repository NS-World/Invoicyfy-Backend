<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

// Validate store_id
if (!isset($_GET['store_id']) || !is_numeric($_GET['store_id'])) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "store_id is required and must be numeric"
    ]);
    exit;
}

$store_id = (int) $_GET['store_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            store_id,
            name,
            address,
            phone,
            logo_url,
            created_at
        FROM stores
        WHERE store_id = ?
    ");
    $stmt->execute([$store_id]);

    $store = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$store) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "Store not found"
        ]);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "data" => $store
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
