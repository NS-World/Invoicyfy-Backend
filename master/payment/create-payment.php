<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../auth/validate.php';

$data = json_decode(file_get_contents("php://input"));

if (empty($data->invoice_id) || empty($data->payment_date) || empty($data->amount) || empty($data->method)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Required fields missing."]);
    exit;
}

$invoice_id = $data->invoice_id;
$payment_date = $data->payment_date;
$amount = $data->amount;
$method = $data->method;

// Generate unique reference number if not provided
$random = substr(md5(uniqid()), 0, 6);
$reference_no = "PAY-" . date('Ymd') . "-" . $random;

try {
    // Insert payment
    $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, payment_date, amount, method, reference_no) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$invoice_id, $payment_date, $amount, $method, $reference_no]);

    // Calculate total paid
    $totalPaidStmt = $pdo->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE invoice_id = ?");
    $totalPaidStmt->execute([$invoice_id]);
    $total_paid = $totalPaidStmt->fetchColumn();

    // Get invoice total
    $invoiceTotalStmt = $pdo->prepare("SELECT total_amount FROM invoices WHERE invoice_id = ?");
    $invoiceTotalStmt->execute([$invoice_id]);
    $total_amount = $invoiceTotalStmt->fetchColumn();

    // Update invoice status if fully paid
    if ($total_paid >= $total_amount) {
        $updateStatus = $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE invoice_id = ?");
        $updateStatus->execute([$invoice_id]);
    }

    echo json_encode([
        "status" => "success",
        "message" => "Payment added successfully.",
        "reference_no" => $reference_no
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>
