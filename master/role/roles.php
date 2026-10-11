<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND role_name LIKE ?';
    $params[] = "%$search%";
}
$total = (int) db_value("SELECT COUNT(*) FROM roles WHERE $where", $params);
$rows = db_all("SELECT * FROM roles WHERE $where ORDER BY role_id ASC LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));
$modules = [];
foreach (PERMISSION_MODULES as $key => $m) {
    $modules[] = ["key" => $key, "label" => $m['label'], "actions" => $m['actions']];
}
json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "modules" => $modules, "data" => array_map('role_row', $rows)]);
