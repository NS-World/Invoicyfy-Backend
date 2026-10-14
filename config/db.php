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
        // Same collation as the tables, so text comparisons never mix collations (some hosts default to another one).
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '$offset'");
        try {
            // Report GROUP BYs over a year of data stay in memory instead of spilling to a disk temp table
            // (memory is only used by queries that need it). Ignored where the host does not allow it.
            $pdo->exec('SET SESSION tmp_table_size = 67108864, max_heap_table_size = 67108864');
        } catch (PDOException $e) {
        }
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        $message = "Database connection failed";
        if (filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
            // e.g. "Access denied for user ..." (wrong DB_USER / DB_PASS) or "Unknown database ..." (wrong DB_NAME)
            $message .= ': ' . preg_replace('/^SQLSTATE\[\w+\] \[\d+\] /', '', $e->getMessage());
        }
        echo json_encode(["status" => "error", "message" => $message]);
        exit;
    }
}
