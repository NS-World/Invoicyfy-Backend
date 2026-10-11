<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = 'p.track_inventory = 1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND (p.store_id = ? OR p.store_id IS NULL)';
    $params[] = $scope;
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.category LIKE ?)';
    array_push($params, $like, $like, $like);
}
$filter = $_GET['filter'] ?? '';
if ($filter === 'low') {
    $where .= ' AND p.stock_qty <= p.low_stock_threshold AND p.stock_qty > 0';
} elseif ($filter === 'out') {
    $where .= ' AND p.stock_qty <= 0';
}

$summary = cast_numbers(db_one(
    "SELECT COUNT(*) AS products,
            SUM(p.stock_qty <= 0) AS out_of_stock,
            SUM(p.stock_qty > 0 AND p.stock_qty <= p.low_stock_threshold) AS low_stock,
            COALESCE(SUM(GREATEST(p.stock_qty,0) * p.cost_price),0) AS stock_value_cost,
            COALESCE(SUM(GREATEST(p.stock_qty,0) * p.price),0) AS stock_value_sale
     FROM products p WHERE p.track_inventory = 1" . ($scope !== null ? ' AND (p.store_id = ? OR p.store_id IS NULL)' : ''),
    $scope !== null ? [$scope] : []
), ['stock_value_cost', 'stock_value_sale'], ['products', 'out_of_stock', 'low_stock']);

$total = (int) db_value("SELECT COUNT(*) FROM products p WHERE $where", $params);
$rows = db_all(
    "SELECT p.product_id, p.name, p.sku, p.category, p.unit, p.price, p.cost_price, p.stock_qty, p.low_stock_threshold,
            p.status, p.store_id, s.name AS store_name,
            ROUND(GREATEST(p.stock_qty,0) * p.cost_price, 2) AS stock_value,
            (SELECT MAX(m.created_at) FROM stock_movements m WHERE m.product_id = p.product_id) AS last_movement_at
     FROM products p LEFT JOIN stores s ON s.store_id = p.store_id
     WHERE $where ORDER BY (p.stock_qty <= p.low_stock_threshold) DESC, p.name ASC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$rows = array_map(fn($r) => cast_numbers($r, ['price', 'cost_price', 'stock_qty', 'low_stock_threshold', 'stock_value'], ['product_id', 'store_id']), $rows);

json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "summary" => $summary, "data" => $rows]);
