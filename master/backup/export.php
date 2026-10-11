<?php
/** Export one table page by page: GET ?table=invoices&page=1&limit=1000 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/backup.php';
allow_methods('GET');
$user = require_auth();

$table = (string) ($_GET['table'] ?? '');
$cfg = BACKUP_TABLES[$table] ?? null;
if (!$cfg) {
    fail('Unknown table');
}
if (!empty($cfg['admin'])) {
    require_admin($user);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(5000, max(1, (int) ($_GET['limit'] ?? 1000)));
$cols = array_keys(backup_columns($table));
$order = $cfg['pk'] ? "`{$cfg['pk']}`" : implode(', ', array_map(fn($c) => "`$c`", $cfg['unique'][0]));
$rows = db_all(
    'SELECT ' . implode(', ', array_map(fn($c) => "`$c`", $cols)) . " FROM `$table` ORDER BY $order LIMIT ? OFFSET ?",
    [$limit, ($page - 1) * $limit]
);
$total = (int) db_value("SELECT COUNT(*) FROM `$table`");
json_out(['status' => 'success', 'table' => $table, 'columns' => $cols, 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => $rows]);
