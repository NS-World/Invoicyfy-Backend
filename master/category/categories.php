<?php
/** Category list with product counts: GET ?search=&active=1&page=&limit= */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.description LIKE ?)';
    array_push($params, "%$search%", "%$search%");
}
if (!empty($_GET['active'])) {
    $where .= ' AND c.is_active = 1';
}
$total = (int) db_value("SELECT COUNT(*) FROM categories c WHERE $where", $params);
$rows = db_all(
    "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category = c.name) AS product_count
     FROM categories c WHERE $where ORDER BY c.sort_order, c.name LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => array_map('category_row', $rows)]);
