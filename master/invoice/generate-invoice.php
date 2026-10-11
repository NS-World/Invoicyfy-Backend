<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Dompdf

use Dompdf\Dompdf;

// ✅ CHECK DB CONNECTION
if (!isset($pdo)) {
    die("❌ PDO connection not found. Check db.php");
}

$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    echo json_encode(["status" => "error", "message" => "Order ID required"]);
    exit;
}

// ✅ 1. GET ORDER (PDO)
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$orderResult = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$orderResult) {
    echo json_encode(["status" => "error", "message" => "Order not found"]);
    exit;
}

// ✅ CHECK APPROVED
if (strtolower($orderResult['status']) !== "approved") {
    echo json_encode(["status" => "error", "message" => "Order not approved"]);
    exit;
}

// ✅ 2. GET ITEMS (PDO)
$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ 3. GENERATE HTML (BETTER DESIGN)
$html = "
<h2 style='text-align:center;'>Invoice</h2>

<p><strong>Order #:</strong> {$orderResult['order_number']}</p>
<p><strong>Date:</strong> {$orderResult['order_date']}</p>

<table border='1' cellpadding='8' width='100%' style='border-collapse: collapse;'>
<tr style='background:#f2f2f2;'>
    <th>Product</th>
    <th>Qty</th>
    <th>Price</th>
    <th>Total</th>
</tr>
";

foreach ($items as $item) {
    $total = $item['quantity'] * $item['unit_price'];

    $html .= "
    <tr>
        <td>{$item['product_id']}</td>
        <td>{$item['quantity']}</td>
        <td>₹{$item['unit_price']}</td>
        <td>₹{$total}</td>
    </tr>";
}

$html .= "
</table>

<h3 style='text-align:right;'>Total: ₹{$orderResult['total_amount']}</h3>
";

// ✅ 4. GENERATE PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$pdfOutput = $dompdf->output();

// ✅ 5. RETURN BASE64
echo json_encode([
    "status" => "success",
    "file" => base64_encode($pdfOutput),
    "file_name" => "Invoice_{$order_id}.pdf"
]);