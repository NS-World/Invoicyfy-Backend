<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

// Validation
if (
    empty($data['name']) ||
    empty($data['address']) ||
    empty($data['phone'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields are required"]);
    exit;
}

try {
    // Check for duplicate
    $check = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE name = ?");
    $check->execute([$data['name']]);
    if ($check->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Store with this name already exists"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO stores (name, address, phone, logo_url) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $data['name'],
        $data['address'],
        $data['phone'],
        $data['logo_url'] ?? null
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Store added successfully",
        "store_id" => $pdo->lastInsertId()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
