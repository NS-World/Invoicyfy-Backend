<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['product_id'], $data['store_id'], $data['name'], $data['price'], $data['unit'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

$sku = $data['sku'] ?? null;
$status = $data['status'] ?? 'Active';

/* ===== Duplicate Name Check ===== */
$checkStmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE store_id = ? AND name = ? AND product_id != ?
");
$checkStmt->execute([$data['store_id'], $data['name'], $data['product_id']]);

if ($checkStmt->rowCount() > 0) {
    http_response_code(409);
    echo json_encode(["status" => "error", "message" => "Duplicate product name"]);
    exit;
}

/* ===== Duplicate SKU Check ===== */
if ($sku) {
    $skuCheck = $pdo->prepare("
        SELECT * FROM products 
        WHERE sku = ? AND product_id != ?
    ");
    $skuCheck->execute([$sku, $data['product_id']]);

    if ($skuCheck->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "SKU already exists"]);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("
        UPDATE products 
        SET 
            name = ?, 
            sku = ?, 
            description = ?, 
            price = ?, 
            unit = ?, 
            store_id = ?, 
            status = ?
        WHERE product_id = ?
    ");

    $stmt->execute([
        $data['name'],
        $sku,
        $data['description'] ?? null,
        $data['price'],
        $data['unit'],
        $data['store_id'],
        $status,
        $data['product_id']
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Product updated successfully"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
