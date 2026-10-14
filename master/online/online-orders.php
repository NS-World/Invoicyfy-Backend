<?php
/**
 * Online platform orders (Swiggy, Zomato...) in one place.
 * GET ?store_id=&from=&to=&platform=&settled=yes|no&search=&page=&limit=
 * Summary per platform: orders, sales, commission (estimate from the % in Counter settings), expected payout,
 * received so far and pending.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
[$from, $to] = date_range();
$storeId = write_store($user, $_GET['store_id'] ?? null);
$where = "i.store_id = ? AND i.invoice_date BETWEEN ? AND ? AND i.sales_channel IS NOT NULL AND i.status <> 'Cancelled'";
$params = [$storeId, $from, $to];
$platform = trim((string) ($_GET['platform'] ?? ''));
$filters = '';
$fparams = [];
if ($platform !== '') {
    $filters .= ' AND i.sales_channel = ?';
    $fparams[] = $platform;
}
$settled = (string) ($_GET['settled'] ?? '');
if ($settled === 'yes') {
    $filters .= ' AND i.settled_at IS NOT NULL';
} elseif ($settled === 'no') {
    $filters .= ' AND i.settled_at IS NULL';
}
if ($search !== '') {
    $filters .= ' AND (i.channel_order_id LIKE ? OR i.invoice_number LIKE ? OR i.customer_name LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($fparams, $like, $like, $like);
}
$summary = array_map(fn($r) => cast_numbers($r, ['sales', 'commission', 'expected', 'received', 'pending'], ['orders', 'settled_orders']), db_all(
    "SELECT i.sales_channel AS platform, COUNT(*) AS orders, SUM(i.total_amount) AS sales, SUM(i.channel_commission) AS commission,
            SUM(i.total_amount - i.channel_commission) AS expected, COALESCE(SUM(i.settled_amount),0) AS received,
            SUM(CASE WHEN i.settled_at IS NULL THEN i.total_amount - i.channel_commission ELSE 0 END) AS pending,
            SUM(i.settled_at IS NOT NULL) AS settled_orders
     FROM invoices i WHERE $where GROUP BY i.sales_channel ORDER BY sales DESC",
    $params
));
$total = (int) db_value("SELECT COUNT(*) FROM invoices i WHERE $where$filters", array_merge($params, $fparams));
$rows = db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.created_at, i.sales_channel AS platform, i.channel_order_id, i.customer_name,
            i.total_amount, i.channel_commission, i.total_amount - i.channel_commission AS expected, i.settled_amount, i.settled_at, i.status
     FROM invoices i WHERE $where$filters ORDER BY i.invoice_date DESC, i.invoice_id DESC LIMIT ? OFFSET ?",
    array_merge($params, $fparams, [$limit, $offset])
);
json_out([
    'status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'range' => ['from' => $from, 'to' => $to],
    'platforms' => pos_settings(get_store($storeId))['online_platforms'],
    'summary' => $summary,
    'data' => array_map(fn($r) => cast_numbers($r, ['total_amount', 'channel_commission', 'expected', 'settled_amount'], ['invoice_id']), $rows),
]);
