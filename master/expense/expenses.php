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
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (e.category LIKE ? OR e.description LIKE ? OR e.vendor LIKE ? OR e.reference_no LIKE ?)';
    array_push($params, $like, $like, $like, $like);
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

$total = (int) db_value("SELECT COUNT(*) FROM expenses e WHERE $where", $params);
$byCategory = array_map(
    fn($r) => cast_numbers($r, ['amount'], ['count']),
    db_all("SELECT e.category, COUNT(*) AS count, SUM(e.amount) AS amount FROM expenses e WHERE $where GROUP BY e.category ORDER BY amount DESC", $params)
);
$rows = db_all(
    "SELECT e.*, s.name AS store_name, u.name AS user_name FROM expenses e
     LEFT JOIN stores s ON s.store_id = e.store_id LEFT JOIN users u ON u.user_id = e.user_id
     WHERE $where ORDER BY e.expense_date DESC, e.expense_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out([
    "status" => "success", "page" => $page, "limit" => $limit, "total" => $total,
    "summary" => ["total_amount" => round(array_sum(array_column($byCategory, 'amount')), 2), "by_category" => $byCategory],
    "data" => array_map(fn($r) => cast_numbers($r, ['amount', 'tax_amount'], ['expense_id', 'store_id', 'user_id']), $rows),
]);
