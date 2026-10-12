<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
require_auth();

// Names for filters: the category master plus any old free-text category still on a product.
$rows = db_all("SELECT name FROM categories UNION SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY 1");
json_out(["status" => "success", "data" => array_column($rows, 'name')]);
