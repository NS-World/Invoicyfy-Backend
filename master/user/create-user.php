<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php'; // JWT validation

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

// Sanitize inputs
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$store_id = $data['store_id'] ?? null;
$role_id = $data['role_id'] ?? null;
$city = trim($data['city'] ?? '');

// Validate required fields
if (!$name || !$email || !$phone || !$store_id || !$role_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields are required"]);
    exit;
}

try {
    // Check for duplicate name / email / phone
    $duplicateCheck = $pdo->prepare("
        SELECT COUNT(*) FROM users 
        WHERE email = ? OR phone = ? OR name = ?
    ");
    $duplicateCheck->execute([$email, $phone, $name]);

    if ($duplicateCheck->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => "error",
            "message" => "User with same email, phone, or name already exists"
        ]);
        exit;
    }

    // Create user
    $insert = $pdo->prepare("
        INSERT INTO users (name, email, phone, city, store_id, role_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())

    ");
   $insert->execute([$name, $email, $phone, $city, $store_id, $role_id]);


    echo json_encode([
        "status" => "success",
        "message" => "User created successfully",
        "user_id" => $pdo->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
