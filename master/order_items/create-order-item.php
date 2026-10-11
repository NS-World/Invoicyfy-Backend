<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"));

if (empty($data->order_id) || !isset($data->items) || !is_array($data->items)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Order ID and items are required."]);
    exit;
}

$order_id = $data->order_id;
$items = $data->items;

$inserted = [];
$skipped = [];

try {
    foreach ($items as $item) {
        if (
            empty($item->product_id) || empty($item->quantity) || empty($item->unit_price)
        ) {
            $skipped[] = ["product_id" => $item->product_id ?? null, "reason" => "Missing fields"];
            continue;
        }

        $product_id = $item->product_id;
        $quantity = $item->quantity;
        $unit_price = $item->unit_price;

        // Check if product already exists in order
        $check = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? AND product_id = ?");
        $check->execute([$order_id, $product_id]);

        if ($check->rowCount() > 0) {
            $skipped[] = ["product_id" => $product_id, "reason" => "Already added"];
            continue;
        }

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        $success = $stmt->execute([$order_id, $product_id, $quantity, $unit_price]);

        if ($success) {
            $inserted[] = $product_id;
        } else {
            $skipped[] = ["product_id" => $product_id, "reason" => "Insert failed"];
        }
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Processed items",
        "inserted" => $inserted,
        "skipped" => $skipped
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>
