<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

// Get customer_id from query parameter
if (!isset($_GET['customer_id']) || !is_numeric($_GET['customer_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Customer ID is required and must be numeric"]);
    exit;
}

$customer_id = (int)$_GET['customer_id'];

try {
    $stmt = $pdo->prepare("SELECT customer_id, name, email, phone, address, created_at FROM customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Customer not found"]);
        exit;
    }

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $customer
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
