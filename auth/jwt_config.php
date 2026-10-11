<?php
require_once __DIR__ . '/../lib/env.php';

$accessKey = (string) env('JWT_ACCESS_SECRET', '');
$refreshKey = (string) env('JWT_REFRESH_SECRET', '');

if ($accessKey === '' || $refreshKey === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Server is not configured (JWT secrets missing)"]);
    exit;
}

$key = $accessKey;
$jwt_issuer = "Invoicify";
$jwt_audience = "Invoices Made Easy";
$access_token_exp = (int) env('JWT_ACCESS_TTL', 900);     // 15 minutes
$refresh_token_exp = (int) env('JWT_REFRESH_TTL', 604800); // 7 days
