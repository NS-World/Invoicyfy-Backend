<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND i.store_id = ?';
    $params[] = $scope;
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (i.invoice_number LIKE ? OR i.customer_name LIKE ? OR i.customer_phone LIKE ? OR i.customer_gstin LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if (!empty($_GET['status'])) {
    if ($_GET['status'] === 'Overdue') {
        $where .= " AND i.status IN ('Unpaid','Partial') AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
    } elseif ($_GET['status'] === 'Due') {
        $where .= " AND i.status IN ('Unpaid','Partial')";
    } else {
        $where .= ' AND i.status = ?';
        $params[] = $_GET['status'];
    }
}
if (!empty($_GET['invoice_type'])) {
    $where .= ' AND i.invoice_type = ?';
    $params[] = $_GET['invoice_type'];
}
if (!empty($_GET['customer_id'])) {
    $where .= ' AND i.customer_id = ?';
    $params[] = (int) $_GET['customer_id'];
}
if (!empty($_GET['from'])) {
    $where .= ' AND i.invoice_date >= ?';
    $params[] = date_field($_GET, 'from');
}
if (!empty($_GET['to'])) {
    $where .= ' AND i.invoice_date <= ?';
    $params[] = date_field($_GET, 'to');
}

$total = (int) db_value("SELECT COUNT(*) FROM invoices i WHERE $where", $params);
$summary = cast_numbers(db_one(
    "SELECT COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.total_amount END),0) AS total_amount,
            COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.amount_paid END),0) AS amount_paid,
            COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.balance_due END),0) AS balance_due,
            COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.tax_amount END),0) AS tax_amount
     FROM invoices i WHERE $where",
    $params
), ['total_amount', 'amount_paid', 'balance_due', 'tax_amount']);
$rows = db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_type, i.order_id, i.customer_id, i.customer_name, i.customer_phone,
            i.customer_gstin, i.store_id, s.name AS store_name, i.user_id, u.name AS user_name, i.invoice_date, i.due_date,
            i.order_type, i.taxable_amount, i.tax_amount, i.total_amount, i.amount_paid, i.balance_due, i.status, i.created_at,
            (i.status IN ('Unpaid','Partial') AND i.due_date IS NOT NULL AND i.due_date < CURDATE()) AS is_overdue
     FROM invoices i LEFT JOIN stores s ON s.store_id = i.store_id LEFT JOIN users u ON u.user_id = i.user_id
     WHERE $where ORDER BY i.invoice_date DESC, i.invoice_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$rows = array_map(fn($r) => doc_cast($r) + ['is_overdue' => (bool) $r['is_overdue']], $rows);

json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "summary" => $summary, "data" => $rows]);
