<?php
/** GET ?store_id=&search=&active=1&with_balance=1&page=&limit= */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$storeId = write_store($user, $_GET['store_id'] ?? null);
$where = 's.store_id = ?';
$params = [$storeId];
if ($search !== '') {
    $where .= ' AND (s.name LIKE ? OR s.phone LIKE ? OR s.gstin LIKE ? OR s.contact_person LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like);
}
if (($_GET['active'] ?? '') === '1') {
    $where .= ' AND s.is_active = 1';
}
$total = (int) db_value("SELECT COUNT(*) FROM suppliers s WHERE $where", $params);
// Balance per supplier for this page only (each part reads its own index).
$rows = db_all(
    "SELECT s.*,
            (SELECT COALESCE(SUM(p.total_amount),0) FROM purchases p WHERE p.supplier_id = s.supplier_id AND p.status <> 'Cancelled') AS purchased,
            (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id = s.supplier_id AND p.status <> 'Cancelled') AS bills,
            (SELECT COALESCE(SUM(sp.amount),0) FROM supplier_payments sp WHERE sp.supplier_id = s.supplier_id) AS paid
     FROM suppliers s WHERE $where ORDER BY s.is_active DESC, s.name LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
foreach ($rows as &$r) {
    $r['balance'] = round((float) $r['opening_balance'] + (float) $r['purchased'] - (float) $r['paid'], 2);
}
unset($r);
$owed = (float) db_value(
    "SELECT COALESCE(SUM(s.opening_balance),0)
            + (SELECT COALESCE(SUM(p.total_amount),0) FROM purchases p WHERE p.store_id = ? AND p.status <> 'Cancelled')
            - (SELECT COALESCE(SUM(sp.amount),0) FROM supplier_payments sp WHERE sp.store_id = ?)
     FROM suppliers s WHERE s.store_id = ?",
    [$storeId, $storeId, $storeId]
);
json_out(['status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => $total, 'total_owed' => round($owed, 2), 'data' => array_map('supplier_row', $rows)]);
