<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

try {
     $input = json_decode(file_get_contents("php://input"), true);

    if (!isset($input['role_id'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing role_id"]);
        exit;
    }

    $role_id = (int) $input['role_id'];

    // Start a transaction
    $pdo->beginTransaction();

    // Step 1: Set role_id to NULL in users table
    $nullifyUsers = $pdo->prepare("UPDATE users SET role_id = NULL WHERE role_id = ?");
    $nullifyUsers->execute([$role_id]);

    // TODO: Add other tables where role_id is used if necessary

    // Step 2: Delete the role
    $deleteRole = $pdo->prepare("DELETE FROM roles WHERE role_id = ?");
    $deleteRole->execute([$role_id]);

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "Role deleted and references cleared."]);
    

} catch (PDOException $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
