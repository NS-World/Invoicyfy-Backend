<?php
/**
 * Database migration + seed. CLI only:
 *
 *   php database/migrate.php                 # create / upgrade schema, seed defaults if empty
 *   php database/migrate.php --admin-email=you@shop.com --admin-password=Secret123
 *
 * Without SSH an Admin can run the same upgrade from Settings > Database update.
 * Safe to run any number of times. It never drops tables or data.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/migrator.php';

$opts = getopt('', ['admin-email::', 'admin-password::', 'admin-name::']);
$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$dbName = env('DB_NAME', 'invoice_system');

$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", env('DB_USER', 'root'), (string) env('DB_PASS', ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbName`");
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$result = run_migrations($pdo, $dbName, $opts);
echo implode(PHP_EOL, $result['log']) . PHP_EOL;
