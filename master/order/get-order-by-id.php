<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php'; // JWT authentication

header('Content-Type: application/json');

// Validate `order_id`
if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "order_id is required and must be numeric"]);
    exit;
}

$order_id = (int) $_GET['order_id'];

try {
    // Fetch order main details
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.order_number, o.order_date, o.total_amount, o.status, o.created_at,
               c.customer_id, c.name AS customer_name,
               u.user_id, u.name AS user_name,
               s.store_id, s.name AS store_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN stores s ON o.store_id = s.store_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Order not found"]);
        exit;
    }

    // Fetch order items
    $itemStmt = $pdo->prepare("
        SELECT oi.order_item_id, p.product_id, p.name AS product_name,
               oi.quantity, oi.unit_price, (oi.quantity * oi.unit_price) AS total_price
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE oi.order_id = ?
    ");
    $itemStmt->execute([$order_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine and return
    echo json_encode([
        "status" => "success",
        "order" => $order,
        "items" => $items
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
