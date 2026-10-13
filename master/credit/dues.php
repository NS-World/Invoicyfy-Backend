<?php
/**
 * Udhaar / credit: customers who owe money. GET ?store_id=&search=&min_days=&page=&limit=
 * Aging buckets by bill date: 0-30, 31-60, 61-90 and 90+ days.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$storeId = scope_store($user, $_GET['store_id'] ?? null);
$where = "i.status <> 'Cancelled' AND i.balance_due > 0 AND i.customer_id IS NOT NULL";
$params = [];
if ($storeId !== null) {
    $where .= ' AND i.store_id = ?';
    $params[] = $storeId;
}
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.company_name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$minDays = max(0, (int) ($_GET['min_days'] ?? 0));
$having = $minDays ? ' HAVING MIN(i.invoice_date) <= ?' : '';
$havingParams = $minDays ? [date('Y-m-d', strtotime("-$minDays days"))] : [];

$base = "FROM invoices i JOIN customers c ON c.customer_id = i.customer_id WHERE $where GROUP BY c.customer_id$having";
$total = (int) db_value("SELECT COUNT(*) FROM (SELECT c.customer_id $base) t", array_merge($params, $havingParams));
$today = date('Y-m-d');
$rows = db_all(
    "SELECT c.customer_id, c.name, c.company_name, c.phone, c.email, c.last_reminder_at,
            SUM(i.balance_due) AS due, COUNT(*) AS bills, MIN(i.invoice_date) AS oldest,
            SUM(CASE WHEN DATEDIFF(?, i.invoice_date) <= 30 THEN i.balance_due ELSE 0 END) AS d0_30,
            SUM(CASE WHEN DATEDIFF(?, i.invoice_date) BETWEEN 31 AND 60 THEN i.balance_due ELSE 0 END) AS d31_60,
            SUM(CASE WHEN DATEDIFF(?, i.invoice_date) BETWEEN 61 AND 90 THEN i.balance_due ELSE 0 END) AS d61_90,
            SUM(CASE WHEN DATEDIFF(?, i.invoice_date) > 90 THEN i.balance_due ELSE 0 END) AS d90,
            (SELECT MAX(p.payment_date) FROM payments p WHERE p.customer_id = c.customer_id) AS last_payment
     $base ORDER BY due DESC LIMIT ? OFFSET ?",
    array_merge([$today, $today, $today, $today], $params, $havingParams, [$limit, $offset])
);
$sumWhere = "i.status <> 'Cancelled' AND i.balance_due > 0" . ($storeId !== null ? ' AND i.store_id = ?' : '');
$sumParams = $storeId !== null ? [$storeId] : [];
$summary = db_one(
    "SELECT COALESCE(SUM(i.balance_due),0) AS total, COUNT(DISTINCT i.customer_id) AS customers,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, i.invoice_date) > 30 THEN i.balance_due END),0) AS over30,
            COALESCE(SUM(CASE WHEN i.customer_id IS NULL THEN i.balance_due END),0) AS walkin
     FROM invoices i WHERE $sumWhere",
    array_merge([$today], $sumParams)
);
json_out([
    'status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total,
    'summary' => ['total' => money($summary['total']), 'customers' => (int) $summary['customers'], 'over_30_days' => money($summary['over30']), 'walk_in' => money($summary['walkin'])],
    'data' => array_map(fn($r) => cast_numbers($r, ['due', 'd0_30', 'd31_60', 'd61_90', 'd90'], ['customer_id', 'bills']), $rows),
]);
