<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND e.store_id = ?';
    $params[] = $scope;
}
if (!empty($_GET['category'])) {
    $where .= ' AND e.category = ?';
    $params[] = $_GET['category'];
}
if (!empty($_GET['from'])) {
    $where .= ' AND e.expense_date >= ?';
    $params[] = date_field($_GET, 'from');
}
if (!empty($_GET['to'])) {
    $where .= ' AND e.expense_date <= ?';
    $params[] = date_field($_GET, 'to');
}

[$join, $joinParams, $searchWhere, $searchParams] = list_search($search, 'expenses', 'expense_id', 'e',
    ['e.category', 'e.description', 'e.vendor', 'e.reference_no'],
    ['prefix' => ['category', 'reference_no'], 'text' => 'description, vendor', 'short' => 'vendor'],
    "FROM expenses e WHERE $where", $params);
$params = array_merge($joinParams, $params, $searchParams);
$base = "FROM expenses e$join WHERE $where$searchWhere";

$total = (int) db_value("SELECT COUNT(*) $base", $params);
$byCategory = array_map(
    fn($r) => cast_numbers($r, ['amount'], ['count']),
    db_all("SELECT e.category, COUNT(*) AS count, SUM(e.amount) AS amount $base GROUP BY e.category ORDER BY amount DESC", $params)
);
$ids = page_ids("SELECT e.expense_id AS id $base ORDER BY e.expense_date DESC, e.expense_id DESC", $params, $limit, $offset);
$rows = rows_in_order(
    "SELECT e.*, s.name AS store_name, u.name AS user_name FROM expenses e
     LEFT JOIN stores s ON s.store_id = e.store_id LEFT JOIN users u ON u.user_id = e.user_id
     WHERE e.expense_id IN (%s)",
    $ids,
    'expense_id'
);
json_out([
    "status" => "success", "page" => $page, "limit" => $limit, "total" => $total,
    "summary" => ["total_amount" => round(array_sum(array_column($byCategory, 'amount')), 2), "by_category" => $byCategory],
    "data" => array_map(fn($r) => cast_numbers($r, ['amount', 'tax_amount'], ['expense_id', 'store_id', 'user_id']), $rows),
]);
