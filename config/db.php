<?php
require_once __DIR__ . '/../lib/env.php';

if (!isset($pdo)) {
    $host = env('DB_HOST', 'localhost');
    $port = env('DB_PORT', '3306');
    $db_name = env('DB_NAME', 'invoice_system');

    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4",
            env('DB_USER', 'root'),
            (string) env('DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
        $offset = (new DateTime())->format('P');
        $pdo->exec("SET time_zone = '$offset'");
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit;
    }
}
