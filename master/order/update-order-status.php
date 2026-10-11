<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['order_id']) || empty($data['status'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Order ID and status are required"]);
    exit;
}

$validStatuses = ['Pending', 'Approved', 'Cancelled', 'Invoiced', 'pending', 'approved', 'cancelled', 'invoiced'];
if (!in_array($data['status'], $validStatuses)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid status. Valid statuses are: Pending, Approved, Cancelled, Invoiced"]);
    exit;
}

try {
    // Check if order exists
    $checkStmt = $pdo->prepare("SELECT order_id, status FROM orders WHERE order_id = ?");
    $checkStmt->execute([$data['order_id']]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Order not found"]);
        exit;
    }

    // Normalize status to match database (capitalize first letter)
    $status = ucfirst(strtolower($data['status']));

    // Update status only if different
    if ($order['status'] !== $status) {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
        $stmt->execute([$status, $data['order_id']]);
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success", 
        "message" => "Order status updated successfully",
        "order_id" => $data['order_id'],
        "new_status" => $status
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
