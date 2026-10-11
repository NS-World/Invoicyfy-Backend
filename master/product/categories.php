<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
require_auth();

$rows = db_all("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category");
json_out(["status" => "success", "data" => array_column($rows, 'category')]);
