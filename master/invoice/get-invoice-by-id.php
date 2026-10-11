<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if (!$invoice_id) {
    echo json_encode(["status" => "error", "message" => "Invoice ID is required."]);
    exit;
}

$sql = "SELECT i.*, c.name AS customer_name, u.name AS user_name, s.name AS store_name
        FROM invoices i
        JOIN customers c ON i.customer_id = c.customer_id
        JOIN users u ON i.user_id = u.user_id
        JOIN stores s ON i.store_id = s.store_id
        WHERE i.invoice_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$invoice_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if ($data) {
    echo json_encode(["status" => "success", "data" => $data]);
} else {
    echo json_encode(["status" => "error", "message" => "Invoice not found."]);
}
?>
