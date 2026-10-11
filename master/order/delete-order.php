<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['order_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Order ID is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM orders WHERE order_id = ?");
    $stmt->execute([$data['order_id']]);

    echo json_encode(["status" => "success", "message" => "Order deleted successfully"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
