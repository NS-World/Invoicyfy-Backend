<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

$data = json_decode(file_get_contents("php://input"));

// Validate input
if (!isset($data->order_item_ids) || !is_array($data->order_item_ids) || empty($data->order_item_ids)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "order_item_ids array is required."]);
    exit;
}

// Filter and sanitize IDs
$order_item_ids = array_filter(array_map('intval', $data->order_item_ids));

// Make sure we have valid IDs after filtering
if (empty($order_item_ids)) {
    echo json_encode(["status" => "error", "message" => "No valid order_item_ids provided."]);
    exit;
}

// Build placeholders (?, ?, ?, ...)
$placeholders = implode(',', array_fill(0, count($order_item_ids), '?'));

try {
    $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_item_id IN ($placeholders)");
    $stmt->execute($order_item_ids);

    echo json_encode([
        "status" => "success",
        "message" => "Deleted " . $stmt->rowCount() . " order item(s).",
        "deleted_ids" => $order_item_ids
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>
