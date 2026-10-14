<?php
/**
 * Inventory screen: every product of the store (same table as the Products screen), with its stock.
 * GET ?filter=tracked|untracked|low|out&search=&page=&limit=&store_id=
 * Products with "Track stock" off are listed too (stock not counted for them); low / out of stock and the
 * stock value only count tracked products. Inactive (deleted / switched off) products are not listed.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$base = "p.status <> 'Inactive'";
$baseParams = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $base .= ' AND (p.store_id = ? OR p.store_id IS NULL)';
    $baseParams[] = $scope;
}
$where = $base;
$params = $baseParams;
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.category LIKE ?)';
    array_push($params, $like, $like, $like);
}
$filter = $_GET['filter'] ?? '';
if ($filter === 'tracked') {
    $where .= ' AND p.track_inventory = 1';
} elseif ($filter === 'untracked') {
    $where .= ' AND p.track_inventory = 0';
} elseif ($filter === 'low') {
    $where .= ' AND p.track_inventory = 1 AND p.stock_qty <= p.low_stock_threshold AND p.stock_qty > 0';
} elseif ($filter === 'out') {
    $where .= ' AND p.track_inventory = 1 AND p.stock_qty <= 0';
}

$summary = cast_numbers(db_one(
    "SELECT COUNT(*) AS all_products,
            SUM(p.track_inventory = 1) AS products,
            SUM(p.track_inventory = 0) AS untracked,
            SUM(p.track_inventory = 1 AND p.stock_qty <= 0) AS out_of_stock,
            SUM(p.track_inventory = 1 AND p.stock_qty > 0 AND p.stock_qty <= p.low_stock_threshold) AS low_stock,
            COALESCE(SUM(CASE WHEN p.track_inventory = 1 THEN GREATEST(p.stock_qty,0) * p.cost_price END),0) AS stock_value_cost,
            COALESCE(SUM(CASE WHEN p.track_inventory = 1 THEN GREATEST(p.stock_qty,0) * p.price END),0) AS stock_value_sale
     FROM products p WHERE $base",
    $baseParams
), ['stock_value_cost', 'stock_value_sale'], ['all_products', 'products', 'untracked', 'out_of_stock', 'low_stock']);

$total = (int) db_value("SELECT COUNT(*) FROM products p WHERE $where", $params);
$rows = db_all(
    "SELECT p.product_id, p.name, p.sku, p.category, p.unit, p.price, p.cost_price, p.stock_qty, p.low_stock_threshold,
            p.track_inventory, p.status, p.store_id, s.name AS store_name,
            CASE WHEN p.track_inventory = 1 THEN ROUND(GREATEST(p.stock_qty,0) * p.cost_price, 2) ELSE 0 END AS stock_value,
            (SELECT MAX(m.created_at) FROM stock_movements m WHERE m.product_id = p.product_id) AS last_movement_at
     FROM products p LEFT JOIN stores s ON s.store_id = p.store_id
     WHERE $where
     ORDER BY p.track_inventory DESC, (p.track_inventory = 1 AND p.stock_qty <= p.low_stock_threshold) DESC, p.name ASC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$rows = array_map(fn($r) => cast_numbers($r, ['price', 'cost_price', 'stock_qty', 'low_stock_threshold', 'stock_value'], ['product_id', 'store_id', 'track_inventory']), $rows);

json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "summary" => $summary, "data" => $rows]);
