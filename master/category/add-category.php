<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST');
require_auth();

$p = category_payload(input());
if (category_name_taken($p['name'])) {
    fail('A category with this name already exists', 409);
}
$id = insert_row('categories', $p);
json_out(['status' => 'success', 'message' => 'Category added', 'category_id' => $id,
    'data' => category_row(db_one('SELECT *, 0 AS product_count FROM categories WHERE category_id = ?', [$id]))], 201);
