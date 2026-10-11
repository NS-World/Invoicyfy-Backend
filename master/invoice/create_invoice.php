<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

$data = json_decode(file_get_contents("php://input"));

if (empty($data->customer_id) || empty($data->user_id) || empty($data->store_id) || empty($data->invoice_number) || empty($data->invoice_date) || empty($data->total_amount)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Required fields missing."]);
    exit;
}

$customer_id = $data->customer_id;
$user_id = $data->user_id;
$store_id = $data->store_id;
$invoice_number = $data->invoice_number;
$invoice_date = $data->invoice_date;
$total_amount = $data->total_amount;

$stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, user_id, store_id, invoice_date, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$invoice_number, $customer_id, $user_id, $store_id, $invoice_date, $total_amount]);

$invoice_id = $pdo->lastInsertId();

echo json_encode(["status" => "success", "invoice_id" => $invoice_id, "message" => "Invoice created successfully."]);
?>
