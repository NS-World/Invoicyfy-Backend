<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/validate.php'; // JWT check

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

// Trim and validate required fields
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$store_id = $data['store_id'] ?? null;
$role_id = $data['role_id'] ?? null;

if (!$name || !$email || !$password || !$store_id || !$role_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields are required and must not be empty"]);
    exit;
}

try {
    // Check for duplicate name or email
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR name = ?");
    $check->execute([$email, $name]);
    if ($check->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "User with the same email or name already exists"]);
        exit;
    }

    // Insert user
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, store_id, role_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $hashedPassword, $store_id, $role_id]);

    echo json_encode([
        "status" => "success",
        "message" => "User registered successfully",
        "user_id" => $pdo->lastInsertId()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
