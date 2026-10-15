<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1';
$params = [];
$scope = scope_store($user, $_GET['store_id'] ?? null);
if ($scope !== null) {
    $where .= ' AND i.store_id = ?';
    $params[] = $scope;
}
if (!empty($_GET['status'])) {
    if ($_GET['status'] === 'Overdue') {
        $where .= " AND i.status IN ('Unpaid','Partial') AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
    } elseif ($_GET['status'] === 'Due') {
        $where .= " AND i.status IN ('Unpaid','Partial')";
    } else {
        $where .= ' AND i.status = ?';
        $params[] = $_GET['status'];
    }
}
if (!empty($_GET['invoice_type'])) {
    $where .= ' AND i.invoice_type = ?';
    $params[] = $_GET['invoice_type'];
}
if (!empty($_GET['customer_id'])) {
    $where .= ' AND i.customer_id = ?';
    $params[] = (int) $_GET['customer_id'];
}
if (!empty($_GET['from'])) {
    $where .= ' AND i.invoice_date >= ?';
    $params[] = date_field($_GET, 'from');
}
if (!empty($_GET['to'])) {
    $where .= ' AND i.invoice_date <= ?';
    $params[] = date_field($_GET, 'to');
}

// Search: "contains" on a small filtered set; on very large sets bill number / mobile "starts with",
// exact GSTIN or customer name words (FULLTEXT index).
[$join, $joinParams, $searchWhere, $searchParams] = list_search($search, 'invoices', 'invoice_id', 'i',
    ['i.invoice_number', 'i.customer_name', 'i.customer_phone', 'i.customer_gstin'],
    ['prefix' => ['invoice_number'], 'digits' => ['customer_phone'], 'gstin' => 'customer_gstin', 'text' => 'customer_name', 'short' => 'customer_name'],
    "FROM invoices i WHERE $where", $params);
$params = array_merge($joinParams, $params, $searchParams);
// "Contains" search inside a date range: read the range through the date index. (For a common word the optimizer
// otherwise reads the whole table, which is several times slower than the few months asked for.)
$hint = $join === '' && $searchWhere !== '' && (!empty($_GET['from']) || !empty($_GET['to'])) ? ' FORCE INDEX (idx_invoices_date, idx_invoices_store_date)' : '';
$base = "FROM invoices i$hint$join WHERE $where$searchWhere";

$status = (string) ($_GET['status'] ?? '');
if ($search === '' && empty($_GET['customer_id']) && $status !== 'Overdue' && stats_sync()) {
    // Count and totals from the day summaries (same filters: store, status, bill type, dates) — no bill is read.
    $sw = '1=1';
    $sp = [];
    if ($scope !== null) {
        $sw .= ' AND s.store_id = ?';
        $sp[] = $scope;
    }
    if ($status === 'Due') {
        $sw .= " AND s.status IN ('Unpaid','Partial')";
    } elseif ($status !== '') {
        $sw .= ' AND s.status = ?';
        $sp[] = $status;
    }
    if (!empty($_GET['invoice_type'])) {
        $sw .= ' AND s.invoice_type = ?';
        $sp[] = $_GET['invoice_type'];
    }
    if (!empty($_GET['from'])) {
        $sw .= ' AND s.day >= ?';
        $sp[] = date_field($_GET, 'from');
    }
    if (!empty($_GET['to'])) {
        $sw .= ' AND s.day <= ?';
        $sp[] = date_field($_GET, 'to');
    }
    // Purged years: their day summaries are history; the few bills still kept there (unpaid) are counted live.
    $frozen = stats_frozen_before();
    $liveOld = $frozen && (empty($_GET['from']) || date_field($_GET, 'from') < $frozen);
    if ($frozen) {
        $sw .= ' AND s.day >= ?';
        $sp[] = $frozen;
    }
    $sql = "SELECT COALESCE(SUM(s.bills),0) AS total_rows,
                   COALESCE(SUM(CASE WHEN s.status <> 'Cancelled' THEN s.total END),0) AS total_amount,
                   COALESCE(SUM(CASE WHEN s.status <> 'Cancelled' THEN s.paid END),0) AS amount_paid,
                   COALESCE(SUM(CASE WHEN s.status <> 'Cancelled' THEN s.balance END),0) AS balance_due,
                   COALESCE(SUM(CASE WHEN s.status <> 'Cancelled' THEN s.tax END),0) AS tax_amount
            FROM stat_sales_daily s WHERE $sw";
    $sumParams = $sp;
} else {
    // Count and totals in one pass over the matching bills.
    $sql = "SELECT COUNT(*) AS total_rows, COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.total_amount END),0) AS total_amount,
                   COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.amount_paid END),0) AS amount_paid,
                   COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.balance_due END),0) AS balance_due,
                   COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.tax_amount END),0) AS tax_amount
            $base";
    $sumParams = $params;
}
$summary = cast_numbers(db_one($sql, $sumParams), ['total_amount', 'amount_paid', 'balance_due', 'tax_amount'], ['total_rows']);
if (!empty($liveOld)) {
    $old = db_one(
        "SELECT COUNT(*) AS total_rows, COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.total_amount END),0) AS total_amount,
                COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.amount_paid END),0) AS amount_paid,
                COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.balance_due END),0) AS balance_due,
                COALESCE(SUM(CASE WHEN i.status <> 'Cancelled' THEN i.tax_amount END),0) AS tax_amount
         FROM invoices i WHERE $where AND i.invoice_date < ?",
        array_merge($params, [$frozen])
    );
    foreach (['total_rows', 'total_amount', 'amount_paid', 'balance_due', 'tax_amount'] as $k) {
        $summary[$k] = $k === 'total_rows' ? $summary[$k] + (int) $old[$k] : round($summary[$k] + (float) $old[$k], 2);
    }
}
$total = $summary['total_rows'];
unset($summary['total_rows']);
// One page of ids from the (store, date) / (date) index, then just those rows.
$ids = page_ids("SELECT i.invoice_id AS id $base ORDER BY i.invoice_date DESC, i.invoice_id DESC", $params, $limit, $offset);
$rows = rows_in_order(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_type, i.order_id, i.customer_id, i.customer_name, i.customer_phone,
            i.customer_gstin, i.store_id, s.name AS store_name, i.user_id, u.name AS user_name, i.invoice_date, i.due_date,
            i.order_type, i.taxable_amount, i.tax_amount, i.total_amount, i.amount_paid, i.balance_due, i.status, i.created_at,
            (i.status IN ('Unpaid','Partial') AND i.due_date IS NOT NULL AND i.due_date < CURDATE()) AS is_overdue
     FROM invoices i LEFT JOIN stores s ON s.store_id = i.store_id LEFT JOIN users u ON u.user_id = i.user_id
     WHERE i.invoice_id IN (%s)",
    $ids,
    'invoice_id'
);
$rows = array_map(fn($r) => doc_cast($r) + ['is_overdue' => (bool) $r['is_overdue']], $rows);

json_out(["status" => "success", "page" => $page, "limit" => $limit, "total" => $total, "summary" => $summary, "data" => $rows]);
