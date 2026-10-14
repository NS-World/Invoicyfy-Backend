<?php
/** GET ?store_id=&from=&to=&supplier_id=&status=&search=&page=&limit= — purchase bills with totals for the period. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
[$from, $to] = date_range(date('Y-m-01', strtotime('-2 months')));
$storeId = write_store($user, $_GET['store_id'] ?? null);
$where = 'p.store_id = ? AND p.purchase_date BETWEEN ? AND ?';
$params = [$storeId, $from, $to];
if ($sid = (int) ($_GET['supplier_id'] ?? 0)) {
    $where .= ' AND p.supplier_id = ?';
    $params[] = $sid;
}
$status = (string) ($_GET['status'] ?? '');
if ($status === 'due') {
    $where .= " AND p.status IN ('Unpaid','Partial')";
} elseif (in_array($status, ['Unpaid', 'Partial', 'Paid', 'Cancelled'], true)) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= ' AND (p.bill_no LIKE ? OR p.supplier_name LIKE ?)';
    array_push($params, $like, $like);
}
$sum = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN p.status <> 'Cancelled' THEN p.total_amount END),0) AS total,
            COALESCE(SUM(CASE WHEN p.status <> 'Cancelled' THEN p.tax_amount END),0) AS tax,
            COALESCE(SUM(CASE WHEN p.status <> 'Cancelled' THEN p.balance_due END),0) AS due
     FROM purchases p WHERE $where",
    $params
);
$rows = db_all(
    "SELECT p.purchase_id, p.supplier_id, p.supplier_name, p.bill_no, p.purchase_date, p.due_date, p.taxable_amount, p.tax_amount,
            p.total_amount, p.amount_paid, p.balance_due, p.status, p.created_at,
            (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.purchase_id) AS item_count
     FROM purchases p WHERE $where ORDER BY p.purchase_date DESC, p.purchase_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out([
    'status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => (int) $sum['n'], 'range' => ['from' => $from, 'to' => $to],
    'summary' => ['total' => round((float) $sum['total'], 2), 'input_tax' => round((float) $sum['tax'], 2), 'due' => round((float) $sum['due'], 2)],
    'data' => array_map(fn($r) => purchase_cast($r) + ['item_count' => (int) $r['item_count']], $rows),
]);
