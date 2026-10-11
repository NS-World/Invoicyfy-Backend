<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['role_name'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Role name is required"]);
    exit;
}

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_name = ?");
    $check->execute([$data['role_name']]);
    if ($check->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Role already exists"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)");
    $stmt->execute([$data['role_name']]);

    echo json_encode([
        "status" => "success",
        "message" => "Role added successfully",
        "role_id" => $pdo->lastInsertId()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
