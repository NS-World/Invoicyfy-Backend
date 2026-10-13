<?php
/**
 * Health check for uptime monitors / a load balancer: GET /api/health.php
 * 200 {"status":"ok"} when PHP and the database answer, 503 otherwise. No login, no business data.
 */
require_once __DIR__ . '/lib/env.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$t = microtime(true);
try {
    $pdo = new PDO(
        'mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_NAME', 'invoice_system') . ';charset=utf8mb4',
        env('DB_USER', 'root'),
        (string) env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->query('SELECT 1')->fetchColumn();
    echo json_encode(['status' => 'ok', 'db_ms' => round((microtime(true) - $t) * 1000, 1)]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Database not reachable']);
}
