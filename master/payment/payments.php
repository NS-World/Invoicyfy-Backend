<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND p.store_id = ?';
    $params[] = $scope;
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (i.invoice_number LIKE ? OR i.customer_name LIKE ? OR p.reference_no LIKE ?)';
    array_push($params, $like, $like, $like);
}
if (!empty($_GET['payment_method'])) {
    $where .= ' AND p.payment_method = ?';
    $params[] = $_GET['payment_method'];
}
if (!empty($_GET['from'])) {
    $where .= ' AND p.payment_date >= ?';
    $params[] = date_field($_GET, 'from');
}
if (!empty($_GET['to'])) {
    $where .= ' AND p.payment_date <= ?';
    $params[] = date_field($_GET, 'to');
}
$from = "FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id WHERE $where";

$total = (int) db_value("SELECT COUNT(*) $from", $params);
$byMethod = array_map(
    fn($r) => cast_numbers($r, ['amount'], ['count']),
    db_all("SELECT p.payment_method, COUNT(*) AS count, SUM(p.amount) AS amount $from GROUP BY p.payment_method ORDER BY amount DESC", $params)
);
$rows = db_all(
    "SELECT p.*, i.invoice_number, i.invoice_type, i.customer_name, i.status AS invoice_status, u.name AS received_by
     FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id LEFT JOIN users u ON u.user_id = p.user_id
     WHERE $where ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);

json_out([
    "status" => "success", "page" => $page, "limit" => $limit, "total" => $total,
    "summary" => ["total_amount" => round(array_sum(array_column($byMethod, 'amount')), 2), "by_method" => $byMethod],
    "data" => array_map('doc_cast', $rows),
]);
