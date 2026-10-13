<?php
/** GET ?store_id=&from=&to=&page=&limit= — past days with expected / counted cash and difference. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset] = pagination();
[$from, $to] = date_range(date('Y-m-d', strtotime('-60 days')), date('Y-m-d'));
$where = 'DATE(cs.opened_at) BETWEEN ? AND ?';
$params = [$from, $to];
$storeId = scope_store($user, $_GET['store_id'] ?? null);
if ($storeId !== null) {
    $where .= ' AND cs.store_id = ?';
    $params[] = $storeId;
}
$total = (int) db_value("SELECT COUNT(*) FROM cash_sessions cs WHERE $where", $params);
$rows = db_all(
    "SELECT cs.*, uo.name AS opened_by_name, uc.name AS closed_by_name, st.name AS store_name,
            (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.cash_session_id = cs.session_id) AS received_total
     FROM cash_sessions cs LEFT JOIN users uo ON uo.user_id = cs.opened_by LEFT JOIN users uc ON uc.user_id = cs.closed_by
     LEFT JOIN stores st ON st.store_id = cs.store_id
     WHERE $where ORDER BY cs.opened_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total,
    'data' => array_map(fn($r) => cash_session_row($r) + ['received_total' => money($r['received_total'])], $rows)]);
