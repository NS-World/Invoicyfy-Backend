<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php'; // JWT validation

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['store_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "store_id is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM stores WHERE store_id = ?");
    $stmt->execute([$data['store_id']]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(["status" => "success", "message" => "Store deleted successfully"]);
    } else {
        echo json_encode(["status" => "warning", "message" => "Store not found or already deleted"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
