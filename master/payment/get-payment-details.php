<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../auth/validate.php';

$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if (!$invoice_id) {
    echo json_encode(["status" => "error", "message" => "Invoice ID is required."]);
    exit;
}

$sql = "SELECT * FROM payments WHERE invoice_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$invoice_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["status" => "success", "payments" => $payments]);
?>
