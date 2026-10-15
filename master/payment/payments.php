<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND p.store_id = ?';
    $params[] = $scope;
}
if (!empty($_GET['payment_method'])) {
    $where .= ' AND p.payment_method = ?';
    $params[] = $_GET['payment_method'];
}
if (!empty($_GET['from'])) {
    $where .= ' AND p.payment_date >= ?';
    $params[] = date_field($_GET, 'from');
}
if (!empty($_GET['to'])) {
    $where .= ' AND p.payment_date <= ?';
    $params[] = date_field($_GET, 'to');
}
[$join, $joinParams, $searchWhere, $searchParams] = list_search($search, 'payments', 'payment_id', 'p',
    ['i.invoice_number', 'i.customer_name', 'p.reference_no'],
    ['prefix' => ['reference_no'],
     'via' => ['invoices', 'invoice_id', 'invoice_id', ['prefix' => ['invoice_number'], 'digits' => ['customer_phone'], 'text' => 'customer_name', 'short' => 'customer_name']]],
    "FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id WHERE $where", $params);
$params = array_merge($joinParams, $params, $searchParams);
// "Contains" search inside a date range: read the range through the date index (see invoices.php).
$hint = $join === '' && $searchWhere !== '' && (!empty($_GET['from']) || !empty($_GET['to'])) ? ' FORCE INDEX (idx_payments_date, idx_payments_store_date)' : '';
$base = "FROM payments p$hint$join LEFT JOIN invoices i ON i.invoice_id = p.invoice_id WHERE $where$searchWhere";

if ($search === '' && stats_sync()) {
    // No search: count and per-mode totals come from the day summaries (same filters: store, mode, dates).
    $sw = '1=1';
    $sp = [];
    if ($scope !== null) {
        $sw .= ' AND s.store_id = ?';
        $sp[] = $scope;
    }
    if (!empty($_GET['payment_method'])) {
        $sw .= ' AND s.method = ?';
        $sp[] = $_GET['payment_method'];
    }
    if (!empty($_GET['from'])) {
        $sw .= ' AND s.day >= ?';
        $sp[] = date_field($_GET, 'from');
    }
    if (!empty($_GET['to'])) {
        $sw .= ' AND s.day <= ?';
        $sp[] = date_field($_GET, 'to');
    }
    // Purged years: day summaries are history; payments still kept there (on unpaid bills) are counted live.
    $frozen = stats_frozen_before();
    if ($frozen) {
        $sw .= ' AND s.day >= ?';
        $sp[] = $frozen;
    }
    $byMethod = array_map(
        fn($r) => cast_numbers($r, ['amount'], ['count']),
        db_all("SELECT s.method AS payment_method, SUM(s.all_count) AS count, SUM(s.all_amount) AS amount FROM stat_payments_daily s WHERE $sw
                GROUP BY s.method HAVING count > 0 ORDER BY amount DESC", $sp)
    );
    if ($frozen && (empty($_GET['from']) || date_field($_GET, 'from') < $frozen)) {
        $merged = array_column($byMethod, null, 'payment_method');
        foreach (db_all("SELECT p.payment_method, COUNT(*) AS count, SUM(p.amount) AS amount FROM payments p WHERE $where AND p.payment_date < ? GROUP BY p.payment_method",
            array_merge($params, [$frozen])) as $r) {
            $m = $r['payment_method'];
            $merged[$m] = ['payment_method' => $m, 'count' => ($merged[$m]['count'] ?? 0) + (int) $r['count'], 'amount' => round(($merged[$m]['amount'] ?? 0) + (float) $r['amount'], 2)];
        }
        $byMethod = array_values($merged);
        usort($byMethod, fn($a, $b) => $b['amount'] <=> $a['amount']);
    }
    $total = (int) array_sum(array_column($byMethod, 'count'));
} else {
    $byMethod = array_map(
        fn($r) => cast_numbers($r, ['amount'], ['count']),
        db_all("SELECT p.payment_method, COUNT(*) AS count, SUM(p.amount) AS amount $base GROUP BY p.payment_method ORDER BY amount DESC", $params)
    );
    $total = (int) array_sum(array_column($byMethod, 'count'));
}
$ids = page_ids("SELECT p.payment_id AS id $base ORDER BY p.payment_date DESC, p.payment_id DESC", $params, $limit, $offset);
$rows = rows_in_order(
    "SELECT p.*, i.invoice_number, i.invoice_type, i.customer_name, i.status AS invoice_status, u.name AS received_by
     FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id LEFT JOIN users u ON u.user_id = p.user_id
     WHERE p.payment_id IN (%s)",
    $ids,
    'payment_id'
);

json_out([
    "status" => "success", "page" => $page, "limit" => $limit, "total" => $total,
    "summary" => ["total_amount" => round(array_sum(array_column($byMethod, 'amount')), 2), "by_method" => $byMethod],
    "data" => array_map('doc_cast', $rows),
]);
