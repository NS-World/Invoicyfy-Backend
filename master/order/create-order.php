<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

// Validate required fields for order
$required = ['customer_id', 'user_id', 'store_id', 'total_amount'];
foreach ($required as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "$field is required"]);
        exit;
    }
}

try {
    // Start transaction for order + items
    $pdo->beginTransaction();

    // Generate unique order number
    $order_number = 'ORD-' . date('Ymd') . '-' . time();
    $order_date = date('Y-m-d');

    // Insert new order with auto-generated order_number and order_date
    $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_id, user_id, store_id, order_date, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $order_number,
        $data['customer_id'],
        $data['user_id'],
        $data['store_id'],
        $order_date,
        $data['total_amount']
    ]);

    $order_id = $pdo->lastInsertId();

    $inserted_items = [];
    $skipped_items = [];

    // Insert order items if provided
    if (isset($data['items']) && is_array($data['items']) && !empty($data['items'])) {
        foreach ($data['items'] as $item) {
            if (
                !isset($item['product_id']) || 
                !isset($item['quantity']) || 
                !isset($item['unit_price'])
            ) {
                $skipped_items[] = [
                    "product_id" => $item['product_id'] ?? null, 
                    "reason" => "Missing required fields (product_id, quantity, unit_price)"
                ];
                continue;
            }

            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            $unit_price = $item['unit_price'];

            // Check if product exists
            $productCheck = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ?");
            $productCheck->execute([$product_id]);
            if (!$productCheck->fetch()) {
                $skipped_items[] = [
                    "product_id" => $product_id,
                    "reason" => "Product not found"
                ];
                continue;
            }

            $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            $itemStmt->execute([$order_id, $product_id, $quantity, $unit_price]);
            $inserted_items[] = $product_id;
        }
    }

    // Commit transaction
    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Order created successfully",
        "order_id" => $order_id,
        "items_created" => count($inserted_items),
        "items_skipped" => count($skipped_items),
        "inserted_product_ids" => $inserted_items,
        "skipped_items" => $skipped_items
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
