<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['role_id']) || empty($data['role_name'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "role_id and role_name are required"]);
    exit;
}

try {
    // Duplicate role name check
    $dup = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_name = ? AND role_id != ?");
    $dup->execute([$data['role_name'], $data['role_id']]);
    if ($dup->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Another role with this name already exists"]);
        exit;
    }

    // Same data check
    $check = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
    $check->execute([$data['role_id']]);
    $existing = $check->fetchColumn();

    if ($existing === $data['role_name']) {
        echo json_encode(["status" => "warning", "message" => "No changes detected"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE roles SET role_name = ? WHERE role_id = ?");
    $stmt->execute([$data['role_name'], $data['role_id']]);

    echo json_encode(["status" => "success", "message" => "Role updated successfully"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
