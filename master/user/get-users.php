<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$me = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($me);
if ($scope !== null) {
    $where .= ' AND u.store_id = ?';
    $params[] = $scope;
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.city LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$total = (int) db_value("SELECT COUNT(*) FROM users u WHERE $where", $params);
$rows = db_all(
    "SELECT " . USER_COLUMNS . " FROM users u
     LEFT JOIN roles r ON u.role_id = r.role_id LEFT JOIN stores s ON u.store_id = s.store_id
     WHERE $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out(["status" => "success", "total" => $total, "page" => $page, "limit" => $limit, "data" => $rows]);
