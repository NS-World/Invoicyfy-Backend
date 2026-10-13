<?php
/** GET ?store_id=&search=&state=active|all&source=manual|birthday&page=&limit= */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$storeId = write_store($user, $_GET['store_id'] ?? null);
$where = 'c.store_id = ?';
$params = [$storeId];
if ($search !== '') {
    $where .= ' AND (c.code LIKE ? OR c.description LIKE ? OR cu.name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if (($_GET['state'] ?? '') === 'active') {
    $where .= ' AND c.is_active = 1 AND (c.valid_to IS NULL OR c.valid_to >= CURDATE())';
}
if (in_array($_GET['source'] ?? '', ['manual', 'birthday', 'anniversary'], true)) {
    $where .= ' AND c.source = ?';
    $params[] = $_GET['source'];
}
$total = (int) db_value("SELECT COUNT(*) FROM coupons c LEFT JOIN customers cu ON cu.customer_id = c.customer_id WHERE $where", $params);
$rows = db_all(
    "SELECT c.*, cu.name AS customer_name,
            (SELECT COALESCE(SUM(r.discount),0) FROM coupon_redemptions r WHERE r.coupon_id = c.coupon_id AND r.status = 'Used') AS total_discount
     FROM coupons c LEFT JOIN customers cu ON cu.customer_id = c.customer_id
     WHERE $where ORDER BY c.coupon_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => array_map('coupon_row', $rows)]);
