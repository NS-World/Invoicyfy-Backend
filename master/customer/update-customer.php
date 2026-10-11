<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

$customer_id = $data['customer_id'] ?? null;
$name = trim($data['name'] ?? '');
$phone = trim($data['phone'] ?? '');
$email = trim($data['email'] ?? '');
$address = trim($data['address'] ?? '');

if (!$customer_id || !$name || !$phone || !$email || !$address) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields including customer_id are required and must not be empty"]);
    exit;
}

try {
    // Check if customer exists
    $check = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $check->execute([$customer_id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Customer not found"]);
        exit;
    }

    // Check if any data actually changed
    if (
        $name === $existing['name'] &&
        $phone === $existing['phone'] &&
        $email === $existing['email'] &&
        $address === $existing['address']
    ) {
        echo json_encode(["status" => "warning", "message" => "No changes detected; data is already up to date"]);
        exit;
    }

    // Update customer
    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE customer_id = ?");
    $stmt->execute([$name, $phone, $email, $address, $customer_id]);

    echo json_encode(["status" => "success", "message" => "Customer updated successfully"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
