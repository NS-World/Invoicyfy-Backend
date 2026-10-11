<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

$data = json_decode(file_get_contents("php://input"));

// Read all fields from body, not query string
if (
    empty($data->invoice_id) ||
    empty($data->product_id) ||
    empty($data->quantity) ||
    empty($data->unit_price)
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit;
}

$invoice_id = $data->invoice_id;
$product_id = $data->product_id;
$quantity = $data->quantity;
$unit_price = $data->unit_price;
$total_price = $quantity * $unit_price;

try {
    $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$invoice_id, $product_id, $quantity, $unit_price, $total_price]);

    echo json_encode(["status" => "success", "message" => "Invoice item added successfully."]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>
