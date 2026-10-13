<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND o.store_id = ?';
    $params[] = $scope;
}
if (!empty($_GET['status'])) {
    $where .= ' AND o.status = ?';
    $params[] = $_GET['status'];
}
if (!empty($_GET['order_type'])) {
    $where .= ' AND o.order_type = ?';
    $params[] = $_GET['order_type'];
}
if (!empty($_GET['customer_id'])) {
    $where .= ' AND o.customer_id = ?';
    $params[] = (int) $_GET['customer_id'];
}
if (!empty($_GET['from'])) {
    $where .= ' AND o.order_date >= ?';
    $params[] = date_field($_GET, 'from');
}
if (!empty($_GET['to'])) {
    $where .= ' AND o.order_date <= ?';
    $params[] = date_field($_GET, 'to');
}

[$join, $joinParams, $searchWhere, $searchParams] = list_search($search, 'orders', 'order_id', 'o',
    ['o.order_number', 'c.name', 'o.customer_name', 'o.customer_phone', 'c.phone'],
    ['suffix' => ['order_number'], 'digits' => ['customer_phone'], 'text' => 'customer_name', 'short' => 'customer_name',
     'via' => ['customers', 'customer_id', 'customer_id', ['digits' => ['phone'], 'text' => 'name, company_name']]],
    "FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id WHERE $where", $params);
$params = array_merge($joinParams, $params, $searchParams);
$base = "FROM orders o$join LEFT JOIN customers c ON o.customer_id = c.customer_id WHERE $where$searchWhere";

$total = (int) db_value("SELECT COUNT(*) $base", $params);
$ids = page_ids("SELECT o.order_id AS id $base ORDER BY o.order_id DESC", $params, $limit, $offset);
$rows = rows_in_order(
    "SELECT o.*, COALESCE(o.customer_name, c.name, 'Walk-in') AS customer_name, COALESCE(o.customer_phone, c.phone) AS customer_phone,
            c.gstin AS customer_gstin, c.customer_type,
            u.name AS user_name, s.name AS store_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count,
            (SELECT i.invoice_id FROM invoices i WHERE i.order_id = o.order_id AND i.status <> 'Cancelled' ORDER BY i.invoice_id DESC LIMIT 1) AS invoice_id
     FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id
     LEFT JOIN users u ON o.user_id = u.user_id LEFT JOIN stores s ON o.store_id = s.store_id
     WHERE o.order_id IN (%s)",
    $ids,
    'order_id'
);
json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "data" => array_map('doc_cast', $rows)]);
