<?php
/** Campaign history (paginated). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('GET');
$user = require_auth();
[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND c.store_id = ?';
    $params[] = $scope;
}
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.message LIKE ?)';
    array_push($params, "%$search%", "%$search%");
}
$total = (int) db_value("SELECT COUNT(*) FROM campaigns c WHERE $where", $params);
$rows = db_all(
    "SELECT c.*, u.name AS user_name FROM campaigns c LEFT JOIN users u ON u.user_id = c.user_id WHERE $where ORDER BY c.campaign_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => array_map('campaign_row', $rows)]);
