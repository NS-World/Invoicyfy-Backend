<?php
/** GET ?store_id=&product_id=&state=all|in_stock|expiring&days=7&page=&limit= — stock batches with expiry. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$scope = scope_store($user, $_GET['store_id'] ?? null);
$where = '1=1';
$params = [];
if ($scope !== null) {
    $where .= ' AND (b.store_id = ? OR b.store_id IS NULL)';
    $params[] = $scope;
}
if ($pid = (int) ($_GET['product_id'] ?? 0)) {
    $where .= ' AND b.product_id = ?';
    $params[] = $pid;
}
$state = (string) ($_GET['state'] ?? 'in_stock');
if ($state !== 'all') {
    $where .= ' AND b.qty_left > 0';
}
if ($state === 'expiring') {
    $days = max(0, min(365, (int) ($_GET['days'] ?? 7)));
    $where .= ' AND b.expiry_date IS NOT NULL AND b.expiry_date <= ?';
    $params[] = date('Y-m-d', strtotime("+$days days"));
}
if ($search !== '') {
    $where .= ' AND (p.name LIKE ? OR b.batch_no LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like);
}
$total = (int) db_value("SELECT COUNT(*) FROM stock_batches b JOIN products p ON p.product_id = b.product_id WHERE $where", $params);
$rows = db_all(
    "SELECT b.*, p.name AS product_name, p.unit, s.name AS supplier_name, pu.bill_no,
            COALESCE(b.unit_cost, p.cost_price) AS cost, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
     FROM stock_batches b JOIN products p ON p.product_id = b.product_id
     LEFT JOIN purchases pu ON pu.purchase_id = b.purchase_id LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id
     WHERE $where ORDER BY b.expiry_date IS NULL, b.expiry_date, b.batch_id LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => array_map(fn($r) => cast_numbers($r,
    ['qty_received', 'qty_left', 'unit_cost', 'cost'], ['batch_id', 'product_id', 'store_id', 'purchase_id', 'days_left']), $rows)]);
