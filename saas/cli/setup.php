<?php
/**
 * Khanak SaaS set-up (command line). Creates the control database, its tables, the starting plans and a super admin.
 * Safe to run again (also to reset an admin password).
 *
 *   SAAS_MODE=true php saas/cli/setup.php --admin-email=you@khanak.in --admin-password=Secret@123 [--admin-name="Nirav"]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../lib/env.php';
$opts = getopt('', ['admin-email:', 'admin-password:', 'admin-name::']);
$db = (string) env('SAAS_DB_NAME', 'khanak_control');
$root = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';charset=utf8mb4', env('DB_USER', 'root'), (string) env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $root->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    fwrite(STDERR, "Could not create database $db (create it in the hosting panel, then run this again): " . $e->getMessage() . PHP_EOL);
}
require_once __DIR__ . '/../../lib/saas.php';
foreach (saas_install(saas_control_pdo()) as $line) {
    echo $line, PHP_EOL;
}
if (!empty($opts['admin-email']) && !empty($opts['admin-password'])) {
    if (strlen($opts['admin-password']) < 8) {
        fwrite(STDERR, "Admin password must be at least 8 characters\n");
        exit(1);
    }
    $email = strtolower(trim($opts['admin-email']));
    saas_control_pdo()->prepare('INSERT INTO saas_admins (email, name, password) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password = VALUES(password), name = VALUES(name)')
        ->execute([$email, $opts['admin-name'] ?? 'Khanak Admin', password_hash($opts['admin-password'], PASSWORD_BCRYPT)]);
    echo "+ super admin ready: $email", PHP_EOL;
}
echo "Khanak control database \"$db\" is ready.", PHP_EOL;
