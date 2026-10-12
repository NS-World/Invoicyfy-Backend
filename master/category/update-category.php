<?php
/** Rename / edit a category. Products keep pointing to it: their category text is renamed too. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'PUT');
require_auth();

$d = input();
$id = int_field($d, 'category_id') ?? 0;
$old = db_one('SELECT * FROM categories WHERE category_id = ?', [$id]) ?: fail('Category not found', 404);
$p = category_payload($d);
if (category_name_taken($p['name'], $id)) {
    fail('A category with this name already exists', 409);
}
global $pdo;
$pdo->beginTransaction();
update_row('categories', 'category_id', $id, $p);
if ($old['name'] !== $p['name']) {
    db_query('UPDATE products SET category = ? WHERE category = ?', [$p['name'], $old['name']]);
}
$applied = 0;
if (!empty($d['apply_unit_to_products'])) {
    // Optional: give every product in this category the category's unit.
    $applied = db_query('UPDATE products SET unit = ? WHERE category = ?', [$p['default_unit'], $p['name']])->rowCount();
}
$pdo->commit();
json_out(['status' => 'success', 'message' => $applied ? "Category updated, unit set on $applied product(s)" : 'Category updated']);
