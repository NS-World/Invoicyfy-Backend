<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

if (
    empty($data['store_id']) ||
    empty($data['name']) ||
    empty($data['address']) ||
    empty($data['phone']) ||
    !isset($data['logo_url'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields including store_id are required"]);
    exit;
}

try {
    // Check for duplicate store name
    $dup = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE name = ? AND store_id != ?");
    $dup->execute([$data['name'], $data['store_id']]);
    if ($dup->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Another store with this name already exists"]);
        exit;
    }

    // Check if data is unchanged
    $check = $pdo->prepare("SELECT * FROM stores WHERE store_id = ?");
    $check->execute([$data['store_id']]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if (
        $existing &&
        $existing['name'] === $data['name'] &&
        $existing['address'] === $data['address'] &&
        $existing['phone'] === $data['phone'] &&
        $existing['logo_url'] === $data['logo_url']
    ) {
        echo json_encode(["status" => "warning", "message" => "No changes detected"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE stores SET name = ?, address = ?, phone = ?, logo_url = ? WHERE store_id = ?");
    $stmt->execute([
        $data['name'],
        $data['address'],
        $data['phone'],
        $data['logo_url'],
        $data['store_id']
    ]);

    echo json_encode(["status" => "success", "message" => "Store updated successfully"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
