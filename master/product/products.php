<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$store = scope_store($user, $_GET['store_id'] ?? null);
if ($store !== null) {
    $where .= ' AND (p.store_id = ? OR p.store_id IS NULL)';
    $params[] = $store;
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.category LIKE ? OR p.hsn_code LIKE ? OR p.description LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
if (!empty($_GET['status'])) {
    $where .= ' AND p.status = ?';
    $params[] = $_GET['status'];
}
if (!empty($_GET['category'])) {
    $where .= ' AND p.category = ?';
    $params[] = $_GET['category'];
}
if (!empty($_GET['low_stock'])) {
    $where .= ' AND p.track_inventory = 1 AND p.stock_qty <= p.low_stock_threshold';
}

// ?sort=popular: best sellers of the last 90 days first (quick-pick buttons on the billing screen),
// counted once from the day-wise item summaries instead of once per product.
$order = 'p.product_id DESC';
$sold = '';
$soldJoin = '';
$soldParams = [];
if (($_GET['sort'] ?? '') === 'popular') {
    [$items, $soldParams] = items_source(date('Y-m-d', strtotime('-90 days')), date('Y-m-d'), null);
    $soldJoin = " LEFT JOIN (SELECT x.product_id, SUM(x.qty) AS sold_qty, SUM(x.bills) AS sold_lines FROM $items GROUP BY x.product_id) sold ON sold.product_id = p.product_id";
    $sold = ', COALESCE(sold.sold_qty, 0) AS sold_qty, COALESCE(sold.sold_lines, 0) AS sold_lines';
    $order = 'sold_lines DESC, p.name ASC';
}
$total = (int) db_value("SELECT COUNT(*) FROM products p WHERE $where", $params);
$rows = db_all(
    "SELECT p.*, s.name AS store_name$sold FROM products p LEFT JOIN stores s ON p.store_id = s.store_id$soldJoin
     WHERE $where ORDER BY $order LIMIT ? OFFSET ?",
    array_merge($soldParams, $params, [$limit, $offset])
);
$rows = array_map(fn($r) => cast_numbers($r, PRODUCT_FLOATS, PRODUCT_INTS), $rows);

json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "data" => $rows]);
