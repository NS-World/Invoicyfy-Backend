<?php
$host = 'localhost';
$db_name = 'invoice_system';
$username = 'root';
$password = ''; // default is empty in XAMPP

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}
?>
