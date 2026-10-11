<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Dompdf autoload

use Dompdf\Dompdf;
use Dompdf\Options;

$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if (!$invoice_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "invoice_id is required"]);
    exit;
}

// Fetch invoice data here (simplified)
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Invoice not found"]);
    exit;
}

// Build your invoice HTML
$html = "
    <h1>Invoice #{$invoice['invoice_id']}</h1>
    <p>Customer ID: {$invoice['customer_id']}</p>
    <p>Total: {$invoice['total_amount']}</p>
    <p>Status: {$invoice['status']}</p>
    <small>Generated on: " . date('Y-m-d H:i:s') . "</small>
";

// Setup Dompdf
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Get output as string (not direct download)
$pdfOutput = $dompdf->output();
$base64 = base64_encode($pdfOutput);

// Return base64 response
echo json_encode([
    "status" => "success",
    "invoice_id" => $invoice_id,
    "filename" => "invoice-$invoice_id.pdf",
    "base64_pdf" => $base64
]);
?>
