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
// Soonest expiry first, batches without an expiry date last: each part is read in (expiry_date, batch_id)
// index order, so a page is a short index walk even with many thousands of batches.
$src = 'FROM stock_batches b' . ($search !== '' ? ' JOIN products p ON p.product_id = b.product_id' : '');
$cnt = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(b.expiry_date IS NOT NULL), 0) AS dated $src WHERE $where", $params);
$total = (int) $cnt['n'];
$dated = (int) $cnt['dated'];
$ids = [];
if ($offset < $dated) {
    $ids = page_ids("SELECT b.batch_id AS id $src WHERE $where AND b.expiry_date IS NOT NULL ORDER BY b.expiry_date, b.batch_id", $params, $limit, $offset);
}
if (count($ids) < $limit && $total > $dated) {
    array_push($ids, ...page_ids("SELECT b.batch_id AS id $src WHERE $where AND b.expiry_date IS NULL ORDER BY b.batch_id", $params,
        $limit - count($ids), max(0, $offset - $dated)));
}
$rows = rows_in_order(
    "SELECT b.*, p.name AS product_name, p.unit, s.name AS supplier_name, pu.bill_no,
            COALESCE(b.unit_cost, p.cost_price) AS cost, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
     FROM stock_batches b JOIN products p ON p.product_id = b.product_id
     LEFT JOIN purchases pu ON pu.purchase_id = b.purchase_id LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id
     WHERE b.batch_id IN (%s)",
    $ids,
    'batch_id'
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => array_map(fn($r) => cast_numbers($r,
    ['qty_received', 'qty_left', 'unit_cost', 'cost'], ['batch_id', 'product_id', 'store_id', 'purchase_id', 'days_left']), $rows)]);
