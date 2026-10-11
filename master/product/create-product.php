<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['store_id'], $data['name'], $data['price'], $data['unit'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

$sku = $data['sku'] ?? null;
$status = $data['status'] ?? 'Active';

/* ===== Duplicate Name Check ===== */
$checkStmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE store_id = ? AND name = ?
");
$checkStmt->execute([$data['store_id'], $data['name']]);

if ($checkStmt->rowCount() > 0) {
    http_response_code(409);
    echo json_encode(["status" => "error", "message" => "Product already exists in this store"]);
    exit;
}

/* ===== Duplicate SKU Check (Optional but Recommended) ===== */
if ($sku) {
    $skuCheck = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
    $skuCheck->execute([$sku]);

    if ($skuCheck->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "SKU already exists"]);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO products 
        (store_id, name, sku, description, price, unit, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['store_id'],
        $data['name'],
        $sku,
        $data['description'] ?? null,
        $data['price'],
        $data['unit'],
        $status
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Product created successfully",
        "product_id" => $pdo->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
