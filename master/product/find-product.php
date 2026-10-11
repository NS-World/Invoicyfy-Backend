<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

if ($productId === 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "product_id is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT p.*, s.name AS store_name FROM products p LEFT JOIN stores s ON p.store_id = s.store_id WHERE p.product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        echo json_encode(["status" => "success", "data" => $product]);
    } else {
        echo json_encode(["status" => "error", "message" => "Product not found"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
