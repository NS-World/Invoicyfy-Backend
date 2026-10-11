<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';
header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

// Required fields
$required = ['order_id', 'order_number', 'customer_id', 'user_id', 'store_id', 'order_date', 'total_amount'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "$field is required"]);
        exit;
    }
}

try {
    // Check for duplicate order number excluding current order
    $check = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND order_id != ?");
    $check->execute([$data['order_number'], $data['order_id']]);
    if ($check->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Order number already used"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE orders SET order_number = ?, customer_id = ?, user_id = ?, store_id = ?, order_date = ?, total_amount = ? WHERE order_id = ?");
    $stmt->execute([
        $data['order_number'],
        $data['customer_id'],
        $data['user_id'],
        $data['store_id'],
        $data['order_date'],
        $data['total_amount'],
        $data['order_id']
    ]);

    echo json_encode(["status" => "success", "message" => "Order updated successfully"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
