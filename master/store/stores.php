<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user);
if ($scope !== null) {
    $where .= ' AND store_id = ?';
    $params[] = $scope;
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (name LIKE ? OR city LIKE ? OR gstin LIKE ? OR phone LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$total = (int) db_value("SELECT COUNT(*) FROM stores WHERE $where", $params);
$rows = db_all("SELECT * FROM stores WHERE $where ORDER BY store_id ASC LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));

json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "data" => $rows]);
