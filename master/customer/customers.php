<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
require_auth();

[$page, $limit, $offset, $search] = pagination();
$where = '1=1' . customer_active_sql('c');
$params = [];
if (!empty($_GET['customer_type'])) {
    $where .= " AND c.customer_type = ?";
    $params[] = strtoupper($_GET['customer_type']);
}

[$join, $joinParams, $searchWhere, $searchParams] = list_search($search, 'customers', 'customer_id', 'c',
    ['c.name', 'c.phone', 'c.email', 'c.company_name', 'c.gstin'],
    ['digits' => ['phone'], 'prefix' => ['email'], 'gstin' => 'gstin', 'text' => 'name, company_name', 'short' => 'name'],
    "FROM customers c WHERE $where", $params);
$params = array_merge($joinParams, $params, $searchParams);
$base = "FROM customers c$join WHERE $where$searchWhere";

$total = (int) db_value("SELECT COUNT(*) $base", $params);
$ids = page_ids("SELECT c.customer_id AS id $base ORDER BY c.created_at DESC, c.customer_id DESC", $params, $limit, $offset);
$rows = rows_in_order(
    "SELECT c.*,
            COALESCE((SELECT SUM(i.balance_due) FROM invoices i WHERE i.customer_id = c.customer_id AND i.status <> 'Cancelled'), 0) AS outstanding
     FROM customers c WHERE c.customer_id IN (%s)",
    $ids,
    'customer_id'
);
$rows = array_map(fn($r) => cast_numbers($r, ['outstanding'], ['customer_id']), $rows);
json_out(["status" => "success", "total" => $total, "page" => $page, "limit" => $limit, "data" => $rows]);
