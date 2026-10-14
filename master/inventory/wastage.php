<?php
/** GET ?store_id=&from=&to=&page=&limit= — wastage / damage entries with their cost, plus totals by reason and item. */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset] = pagination();
[$from, $to] = date_range();
$scope = scope_store($user, $_GET['store_id'] ?? null);
$where = "m.movement_type IN ('wastage','damage') AND m.created_at >= ? AND m.created_at < ? + INTERVAL 1 DAY";
$params = [$from, $to];
if ($scope !== null) {
    $where .= ' AND (m.store_id = ? OR m.store_id IS NULL)';
    $params[] = $scope;
}
$value = 'ABS(m.quantity) * COALESCE(m.unit_cost, p.cost_price)';
$sum = db_one("SELECT COUNT(*) AS n, COALESCE(SUM($value),0) AS loss FROM stock_movements m JOIN products p ON p.product_id = m.product_id WHERE $where", $params);
$rows = db_all(
    "SELECT m.movement_id, m.created_at, m.product_id, p.name AS product_name, p.unit, ABS(m.quantity) AS quantity, m.notes,
            COALESCE(m.unit_cost, p.cost_price) AS unit_cost, $value AS loss, m.movement_type, u.name AS user_name
     FROM stock_movements m JOIN products p ON p.product_id = m.product_id LEFT JOIN users u ON u.user_id = m.user_id
     WHERE $where ORDER BY m.created_at DESC, m.movement_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
$byItem = db_all(
    "SELECT p.name AS product_name, p.unit, SUM(ABS(m.quantity)) AS quantity, SUM($value) AS loss
     FROM stock_movements m JOIN products p ON p.product_id = m.product_id WHERE $where GROUP BY m.product_id ORDER BY loss DESC LIMIT 10",
    $params
);
$byReason = [];
foreach (db_all("SELECT m.notes, $value AS loss FROM stock_movements m JOIN products p ON p.product_id = m.product_id WHERE $where LIMIT 20000", $params) as $r) {
    $reason = 'Other';
    foreach (WASTAGE_REASONS as $w) {
        if (str_starts_with((string) $r['notes'], $w)) {
            $reason = $w;
            break;
        }
    }
    $byReason[$reason] = round(($byReason[$reason] ?? 0) + (float) $r['loss'], 2);
}
arsort($byReason);
json_out([
    'status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => (int) $sum['n'], 'range' => ['from' => $from, 'to' => $to],
    'summary' => ['loss' => round((float) $sum['loss'], 2), 'by_reason' => $byReason,
                  'by_item' => array_map(fn($r) => cast_numbers($r, ['quantity', 'loss']), $byItem)],
    'reasons' => WASTAGE_REASONS,
    'data' => array_map(fn($r) => cast_numbers($r, ['quantity', 'unit_cost', 'loss'], ['movement_id', 'product_id']), $rows),
]);
