<?php
/**
 * API TESTING SCRIPT
 * Tests: Order creation with items, Order retrieval, Order status update
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/auth/jwt_config.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Generate valid JWT token
$secretKey = $accessKey;
$issuedAt = time();
$payload = [
    "iss" => "Invoicify",
    "aud" => "Invoices Made Easy",
    "iat" => $issuedAt,
    "exp" => $issuedAt + 3600,
    "sub" => 1,
    "user_id" => 1,
    "email" => "admin@example.com"
];
$token = JWT::encode($payload, $secretKey, 'HS256');

echo "=== API TESTING SCRIPT ===\n";
echo "Valid Token: " . $token . "\n\n";

// Test 1: Create order with items
echo "TEST 1: Creating order with items...\n";
echo "========================================\n";

$orderData = [
    "customer_id" => 1,
    "user_id" => 1,
    "store_id" => 1,
    "total_amount" => 250.00,
    "items" => [
        [
            "product_id" => 1,
            "quantity" => 2,
            "unit_price" => 50.00
        ],
        [
            "product_id" => 2,
            "quantity" => 3,
            "unit_price" => 50.00
        ]
    ]
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost/invoice-api/master/order/create-order.php",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($orderData),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $token
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);
echo "Response Code: $httpCode\n";
echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";

if (isset($result['order_id'])) {
    $order_id = $result['order_id'];
    echo "\n✓ Order created with ID: $order_id\n\n";

    // Test 2: Get order items
    echo "TEST 2: Getting order items...\n";
    echo "========================================\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://localhost/invoice-api/master/order_items/get-order-items.php?order_id=" . $order_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    echo "Response Code: $httpCode\n";
    echo "Response: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    if (isset($result['summary'])) {
        echo "\n✓ Order items retrieved.\n";
        echo "  - Item count: " . $result['summary']['item_count'] . "\n";
        echo "  - Grand total: $" . $result['summary']['grand_total'] . "\n\n";

        // Test 3: Update order status
        echo "TEST 3: Updating order status...\n";
        echo "========================================\n";

        $statusData = [
            "order_id" => $order_id,
            "status" => "Approved"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "http://localhost/invoice-api/master/order/update-order-status.php",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($statusData),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $token
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
        echo "Response Code: $httpCode\n";
        echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";

        if ($result['status'] === 'success') {
            echo "\n✓ Order status updated to: " . $result['new_status'] . "\n\n";
        }
    }
} else {
    echo "\n✗ Failed to create order\n";
}

echo "\n=== TESTING COMPLETE ===\n";
?>
