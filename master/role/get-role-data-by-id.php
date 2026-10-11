<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

if (!isset($_GET['role_id']) || !is_numeric($_GET['role_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Role ID is required and must be numeric"]);
    exit;
}

$role_id = (int)$_GET['role_id'];

try {
    $stmt = $pdo->prepare("SELECT role_id, role_name, created_at FROM roles WHERE role_id = ?");
    $stmt->execute([$role_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Role not found"]);
        exit;
    }

    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $role
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
