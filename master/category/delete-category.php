<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'DELETE');
require_auth();

$id = int_field(input(), 'category_id') ?? 0;
$c = db_one('SELECT * FROM categories WHERE category_id = ?', [$id]) ?: fail('Category not found', 404);
$used = (int) db_value('SELECT COUNT(*) FROM products WHERE category = ?', [$c['name']]);
if ($used) {
    fail("$used product(s) use this category. Move them to another category first, or mark the category inactive.", 409);
}
db_query('DELETE FROM categories WHERE category_id = ?', [$id]);
json_out(['status' => 'success', 'message' => 'Category deleted']);
