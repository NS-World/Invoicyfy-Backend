<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['customer_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "customer_id is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM customers WHERE customer_id = ?");
    $stmt->execute([$data['customer_id']]);

    echo json_encode([
        "status" => "success",
        "message" => "Customer deleted successfully"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
