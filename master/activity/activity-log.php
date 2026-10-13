<?php
/** GET ?store_id=&from=&to=&user_id=&action=&search=&page=&limit= — who did what. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
[$from, $to] = date_range(date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
$where = 'a.created_at >= ? AND a.created_at < ?';
$params = [$from . ' 00:00:00', date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'];
$storeId = scope_store($user, $_GET['store_id'] ?? null);
if ($storeId !== null) {
    $where .= ' AND (a.store_id = ? OR a.store_id IS NULL)';
    $params[] = $storeId;
}
if ($uid = int_field($_GET, 'user_id')) {
    $where .= ' AND a.user_id = ?';
    $params[] = $uid;
}
$action = (string) ($_GET['action'] ?? '');
if ($action !== '' && preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $action)) {
    $where .= str_contains($action, '.') ? ' AND a.action = ?' : ' AND a.action LIKE ?';
    $params[] = str_contains($action, '.') ? $action : "$action.%";
}
if (!empty($_GET['important'])) {
    $where .= " AND a.action IN ('invoice.cancel', 'payment.delete', 'discount.approved', 'approval.failed', 'product.price', 'product.delete',
        'order.delete', 'cash.close', 'cash.pay_out', 'login.failed', 'user.update', 'user.delete', 'role.update', 'loyalty.adjust', 'bill.hold_delete', 'stock.damage', 'stock.adjustment')";
}
[$join, $joinParams, $searchWhere, $searchParams] = list_search($search, 'activity_log', 'log_id', 'a',
    ['a.summary', 'a.user_name'], ['text' => 'summary', 'prefix' => ['user_name']], "FROM activity_log a WHERE $where", $params);
$params = array_merge($joinParams, $params, $searchParams);
$base = "FROM activity_log a$join WHERE $where$searchWhere";
$total = (int) db_value("SELECT COUNT(*) $base", $params);
$ids = page_ids("SELECT a.log_id AS id $base ORDER BY a.log_id DESC", $params, $limit, $offset);
$rows = rows_in_order("SELECT a.log_id, a.store_id, a.user_id, a.user_name, a.action, a.entity, a.entity_id, a.summary, a.details, a.ip, a.created_at
    FROM activity_log a WHERE a.log_id IN (%s)", $ids, 'log_id');
foreach ($rows as &$r) {
    $r = cast_numbers($r, [], ['log_id', 'store_id', 'user_id', 'entity_id']);
    $r['details'] = $r['details'] ? json_decode($r['details'], true) : null;
}
unset($r);
$users = db_all('SELECT DISTINCT a.user_id, a.user_name FROM activity_log a WHERE a.user_id IS NOT NULL AND a.created_at >= ? ORDER BY a.user_name', [date('Y-m-d', strtotime('-90 days'))]);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => $rows, 'users' => $users]);
