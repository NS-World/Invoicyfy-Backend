<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$order_item_id = isset($_GET['order_item_id']) ? intval($_GET['order_item_id']) : 0;
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

try {
    if ($order_item_id > 0) {
        // Fetch specific order item with complete details
        $sql = "SELECT 
                    oi.order_item_id,
                    oi.order_id,
                    oi.product_id,
                    oi.quantity,
                    oi.unit_price,
                    (oi.quantity * oi.unit_price) AS total_price,
                    oi.created_at,
                    p.product_id,
                    p.name AS product_name,
                    p.sku,
                    p.price AS product_price,
                    p.description,
                    o.order_number,
                    o.order_date,
                    o.status AS order_status,
                    c.name AS customer_name,
                    c.customer_id
                FROM order_items oi 
                LEFT JOIN products p ON oi.product_id = p.product_id 
                LEFT JOIN orders o ON oi.order_id = o.order_id
                LEFT JOIN customers c ON o.customer_id = c.customer_id
                WHERE oi.order_item_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_item_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            http_response_code(200);
            echo json_encode(["status" => "success", "data" => $data]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Order item not found"]);
        }

    } elseif ($order_id > 0) {
        // First check if order exists
        $orderCheck = $pdo->prepare("SELECT order_id FROM orders WHERE order_id = ?");
        $orderCheck->execute([$order_id]);
        if (!$orderCheck->fetch()) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Order not found"]);
            exit;
        }

        // Fetch all items for an order with complete details
        $sql = "SELECT 
                    oi.order_item_id,
                    oi.order_id,
                    oi.product_id,
                    oi.quantity,
                    oi.unit_price,
                    (oi.quantity * oi.unit_price) AS total_price,
                    oi.created_at,
                    p.product_id,
                    p.name AS product_name,
                    p.sku,
                    p.price AS product_price,
                    p.description,
                    o.order_number,
                    o.order_date,
                    o.total_amount,
                    o.status AS order_status,
                    c.name AS customer_name,
                    c.customer_id,
                    c.email,
                    c.phone,
                    u.name AS user_name,
                    u.user_id
                FROM order_items oi 
                LEFT JOIN products p ON oi.product_id = p.product_id 
                LEFT JOIN orders o ON oi.order_id = o.order_id
                LEFT JOIN customers c ON o.customer_id = c.customer_id
                LEFT JOIN users u ON o.user_id = u.user_id
                WHERE oi.order_id = ?
                ORDER BY oi.order_item_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($items)) {
            // Calculate order totals
            $grand_total = 0;
            $item_count = 0;
            foreach ($items as $item) {
                $grand_total += $item['total_price'];
                $item_count++;
            }

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "summary" => [
                    "order_id" => $order_id,
                    "item_count" => $item_count,
                    "grand_total" => round($grand_total, 2)
                ],
                "items" => $items
            ]);
        } else {
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "summary" => [
                    "order_id" => $order_id,
                    "item_count" => 0,
                    "grand_total" => 0
                ],
                "items" => [],
                "message" => "Order has no items yet"
            ]);
        }
    } else {
        // No valid parameter passed
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Provide order_item_id or order_id"]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>
