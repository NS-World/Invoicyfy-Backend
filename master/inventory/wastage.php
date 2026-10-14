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
// Totals, top items and loss by reason from one pass, grouped by item and reason (a few hundred rows at most).
$params2 = [];
$case = 'CASE';
foreach (WASTAGE_REASONS as $w) {
    $case .= ' WHEN m.notes LIKE ? THEN ?';
    array_push($params2, addcslashes($w, '%_\\') . '%', $w);
}
$case .= " ELSE 'Other' END";
$groups = db_all(
    "SELECT m.product_id, MAX(p.name) AS product_name, MAX(p.unit) AS unit, $case AS reason, COUNT(*) AS n,
            SUM(ABS(m.quantity)) AS quantity, COALESCE(SUM($value),0) AS loss
     FROM stock_movements m JOIN products p ON p.product_id = m.product_id WHERE $where GROUP BY m.product_id, reason",
    array_merge($params2, $params)
);
$sum = ['n' => 0, 'loss' => 0.0];
$items = [];
$byReason = [];
foreach ($groups as $g) {
    $sum['n'] += (int) $g['n'];
    $sum['loss'] += (float) $g['loss'];
    $id = (int) $g['product_id'];
    $items[$id] ??= ['product_name' => $g['product_name'], 'unit' => $g['unit'], 'quantity' => 0.0, 'loss' => 0.0];
    $items[$id]['quantity'] += (float) $g['quantity'];
    $items[$id]['loss'] += (float) $g['loss'];
    $byReason[$g['reason']] = round(($byReason[$g['reason']] ?? 0) + (float) $g['loss'], 2);
}
usort($items, fn($a, $b) => $b['loss'] <=> $a['loss']);
$byItem = array_slice($items, 0, 10);
arsort($byReason);
$rows = db_all(
    "SELECT m.movement_id, m.created_at, m.product_id, p.name AS product_name, p.unit, ABS(m.quantity) AS quantity, m.notes,
            COALESCE(m.unit_cost, p.cost_price) AS unit_cost, $value AS loss, m.movement_type, u.name AS user_name
     FROM stock_movements m JOIN products p ON p.product_id = m.product_id LEFT JOIN users u ON u.user_id = m.user_id
     WHERE $where ORDER BY m.created_at DESC, m.movement_id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);
json_out([
    'status' => 'success', 'page' => $page, 'limit' => $limit, 'total' => (int) $sum['n'], 'range' => ['from' => $from, 'to' => $to],
    'summary' => ['loss' => round((float) $sum['loss'], 2), 'by_reason' => $byReason,
                  'by_item' => array_map(fn($r) => cast_numbers($r, ['quantity', 'loss']), $byItem)],
    'reasons' => WASTAGE_REASONS,
    'data' => array_map(fn($r) => cast_numbers($r, ['quantity', 'unit_cost', 'loss'], ['movement_id', 'product_id']), $rows),
]);
