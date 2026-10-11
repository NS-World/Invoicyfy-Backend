<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

$name = trim($data['name'] ?? '');
$phone = trim($data['phone'] ?? '');
$email = trim($data['email'] ?? '');
$address = trim($data['address'] ?? '');

if (!$name || !$phone || !$email || !$address) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields are required and must not be empty"]);
    exit;
}

try {
    // Check for duplicate customer name
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE name = ?");
    $checkStmt->execute([$name]);

    if ($checkStmt->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Customer with the same name already exists"]);
        exit;
    }

    // Insert new customer
    $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $phone, $email, $address]);

    echo json_encode([
        "status" => "success",
        "message" => "Customer added successfully",
        "customer_id" => $pdo->lastInsertId()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
