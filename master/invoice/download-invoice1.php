<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Dompdf
use Dompdf\Dompdf;

$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if (!$invoice_id) {
    http_response_code(400);
    echo "Invoice ID is required.";
    exit;
}

// Fetch invoice data
$stmt = $pdo->prepare("
    SELECT i.*, c.name AS customer_name, c.address AS customer_address, c.email AS customer_email, c.phone AS customer_phone, s.name AS store_name, s.address AS store_address
    FROM invoices i
    JOIN customers c ON i.customer_id = c.customer_id
    JOIN stores s ON i.store_id = s.store_id
    WHERE i.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    echo "Invoice not found.";
    exit;
}

// Fetch items
$stmt = $pdo->prepare("
    SELECT ii.*, p.name AS product_name
    FROM invoice_items ii
    JOIN products p ON ii.product_id = p.product_id
    WHERE ii.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch payments
$stmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create HTML template
ob_start();
require __DIR__ . '/invoice-template.php'; // Separate template file
$html = ob_get_clean();

// Generate PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Invoice-{$invoice['invoice_number']}.pdf", ["Attachment" => false]); // true to force download
exit;
