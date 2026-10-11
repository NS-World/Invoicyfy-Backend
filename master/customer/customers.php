<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
if ($search !== '') {
    $like = "%$search%";
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.gstin LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
if (!empty($_GET['customer_type'])) {
    $where .= " AND c.customer_type = ?";
    $params[] = strtoupper($_GET['customer_type']);
}

$total = (int) db_value("SELECT COUNT(*) FROM customers c WHERE $where", $params);
$rows = db_all(
    "SELECT c.*,
            COALESCE((SELECT SUM(i.balance_due) FROM invoices i WHERE i.customer_id = c.customer_id AND i.status <> 'Cancelled'), 0) AS outstanding
     FROM customers c WHERE $where
     ORDER BY c.created_at DESC, c.customer_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$rows = array_map(fn($r) => cast_numbers($r, ['outstanding'], ['customer_id']), $rows);

json_out(["status" => "success", "total" => $total, "page" => $page, "limit" => $limit, "data" => $rows]);
