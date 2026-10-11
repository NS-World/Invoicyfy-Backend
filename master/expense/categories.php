<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
require_auth();

$used = array_column(db_all("SELECT DISTINCT category FROM expenses ORDER BY category"), 'category');
$all = array_values(array_unique(array_merge(DEFAULT_EXPENSE_CATEGORIES, $used)));
json_out(["status" => "success", "data" => $all]);
