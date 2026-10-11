<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

$data = json_decode(file_get_contents("php://input"));

if (empty($data->items) || !is_array($data->items)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Items array is required."]);
    exit;
}

$updated = [];
$skipped = [];

foreach ($data->items as $item) {
    if (empty($item->order_item_id) || empty($item->quantity) || empty($item->unit_price)) {
        $skipped[] = [
            "order_item_id" => $item->order_item_id ?? null,
            "reason" => "Missing required fields"
        ];
        continue;
    }

    $order_item_id = $item->order_item_id;
    $quantity = $item->quantity;
    $unit_price = $item->unit_price;
    $total_price = $quantity * $unit_price;

    $stmt = $pdo->prepare("UPDATE order_items SET quantity = ?, unit_price = ?, total_price = ? WHERE order_item_id = ?");
    $success = $stmt->execute([$quantity, $unit_price, $total_price, $order_item_id]);

    if ($success && $stmt->rowCount() > 0) {
        $updated[] = $order_item_id;
    } else {
        $skipped[] = [
            "order_item_id" => $order_item_id,
            "reason" => "Update failed or item not found"
        ];
    }
}

echo json_encode([
    "status" => "success",
    "message" => "Processed items",
    "updated" => $updated,
    "skipped" => $skipped
]);
