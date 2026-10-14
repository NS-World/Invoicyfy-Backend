<?php
/**
 * Set the recipe of a product: what ONE unit (1 scoop, 1 sundae, 1 kg...) uses.
 * POST { product_id, items: [{ ingredient_id, quantity }] } — an empty list removes the recipe.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$pid = int_field($d, 'product_id') ?? 0;
$p = db_one('SELECT product_id, name, store_id FROM products WHERE product_id = ?', [$pid]);
if (!$p) {
    fail('Product not found', 404);
}
if ($p['store_id'] !== null) {
    assert_store_access($user, $p['store_id']);
}
$raw = $d['items'] ?? [];
if (!is_array($raw) || count($raw) > 30) {
    fail('A recipe can have at most 30 ingredients');
}
$lines = [];
foreach ($raw as $i => $r) {
    $row = $i + 1;
    $ing = is_array($r) ? int_field($r, 'ingredient_id') : null;
    $qty = is_array($r) ? num_field($r, 'quantity', 0) : 0;
    if (!$ing) {
        fail("Select the ingredient at row $row");
    }
    if ($ing === $pid) {
        fail('A product cannot be an ingredient of itself');
    }
    if ($qty <= 0 || $qty > 100000) {
        fail("Enter the quantity used at row $row");
    }
    if (isset($lines[$ing])) {
        fail("The same ingredient is added twice (row $row)");
    }
    $lines[$ing] = round($qty, 4);
}
if ($lines) {
    $ids = array_keys($lines);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $found = (int) db_value("SELECT COUNT(*) FROM products WHERE product_id IN ($marks)", $ids);
    if ($found !== count($ids)) {
        fail('An ingredient was not found');
    }
    // One level only: an ingredient with its own recipe would hide stock use.
    if ($nested = db_value("SELECT p.name FROM recipe_items r JOIN products p ON p.product_id = r.product_id WHERE r.product_id IN ($marks) LIMIT 1", $ids)) {
        fail("$nested has its own recipe, so it cannot be used as an ingredient. Use its raw materials instead.");
    }
    if (db_value('SELECT 1 FROM recipe_items WHERE ingredient_id = ? LIMIT 1', [$pid])) {
        fail("{$p['name']} is used as an ingredient in another recipe, so it cannot have a recipe itself.");
    }
}
$pdo->beginTransaction();
db_query('DELETE FROM recipe_items WHERE product_id = ?', [$pid]);
foreach ($lines as $ing => $qty) {
    insert_row('recipe_items', ['product_id' => $pid, 'ingredient_id' => $ing, 'quantity' => $qty]);
}
if ($lines) {
    // Ingredients are tracked, so selling the product takes them out of stock.
    db_query('UPDATE products SET track_inventory = 1, is_ingredient = 1 WHERE product_id IN (' . implode(',', array_fill(0, count($lines), '?')) . ')', array_keys($lines));
}
$pdo->commit();
log_activity('recipe.save', 'product', $pid, $lines ? "Recipe of {$p['name']} saved (" . count($lines) . ' ingredients)' : "Recipe of {$p['name']} removed", ['items' => $lines], $p['store_id']);
json_out(['status' => 'success', 'message' => $lines ? "Recipe of {$p['name']} saved" : "Recipe of {$p['name']} removed"]);
