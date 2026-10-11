<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND (m.store_id = ? OR m.store_id IS NULL)';
    $params[] = $scope;
}
if (!empty($_GET['product_id'])) {
    $where .= ' AND m.product_id = ?';
    $params[] = (int) $_GET['product_id'];
}
if (!empty($_GET['type'])) {
    $where .= ' AND m.movement_type = ?';
    $params[] = $_GET['type'];
}
$total = (int) db_value("SELECT COUNT(*) FROM stock_movements m WHERE $where", $params);
$rows = db_all(
    "SELECT m.*, p.name AS product_name, p.unit, u.name AS user_name,
            CASE WHEN m.reference_type = 'invoice' THEN (SELECT invoice_number FROM invoices i WHERE i.invoice_id = m.reference_id) END AS reference_number
     FROM stock_movements m JOIN products p ON p.product_id = m.product_id LEFT JOIN users u ON u.user_id = m.user_id
     WHERE $where ORDER BY m.movement_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$rows = array_map(fn($r) => cast_numbers($r, ['quantity', 'balance_after', 'unit_cost'], ['movement_id', 'product_id', 'store_id', 'reference_id']), $rows);
json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "data" => $rows]);
