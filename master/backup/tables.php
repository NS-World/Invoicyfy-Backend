<?php
/** Backup screen: every table in restore order with row counts, plus the latest backup / restore history. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/backup.php';
allow_methods('GET');
$user = require_auth();

$tables = [];
$existing = array_flip(backup_existing_tables());
// Exact counts for normal tables; tables with lakhs of rows use the database's own estimate (counting crores of
// rows exactly takes seconds) and are marked "approx".
$estimates = [];
foreach (db_all('SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
    $estimates[$r['t']] = (int) $r['n'];
}
foreach (BACKUP_TABLES as $table => $cfg) {
    if (!isset($existing[$table])) {
        continue;
    }
    if (!empty($cfg['admin']) && !is_admin($user)) {
        continue;
    }
    $tables[] = [
        'table' => $table,
        'label' => $cfg['label'],
        'count' => ($estimates[$table] ?? 0) > 300000 ? $estimates[$table] : (int) db_value("SELECT COUNT(*) FROM `$table`"),
        'approx' => ($estimates[$table] ?? 0) > 300000,
        'columns' => array_keys(backup_columns($table)),
        'key' => $cfg['pk'],
    ];
}
$logs = db_all(
    "SELECT b.backup_id, b.action, b.file_name, b.summary, b.created_at, u.name AS user_name
     FROM backup_logs b LEFT JOIN users u ON u.user_id = b.user_id ORDER BY b.backup_id DESC LIMIT 15"
);
foreach ($logs as &$l) {
    $l['backup_id'] = (int) $l['backup_id'];
    $l['summary'] = $l['summary'] ? json_decode($l['summary'], true) : null;
}
$lastBackup = db_value("SELECT MAX(created_at) FROM backup_logs WHERE action = 'export'");

json_out(['status' => 'success', 'data' => ['tables' => $tables, 'logs' => $logs, 'last_backup_at' => $lastBackup, 'is_full' => is_admin($user)]]);
