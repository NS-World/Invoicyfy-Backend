<?php
/**
 * Restore rows into one table: POST { table, rows: [{ __row: 2, col: value, ... }] } (send up to 500 rows per call,
 * tables in the order given by tables.php). Existing records are never overwritten: rows whose id or natural key
 * (phone, GSTIN, invoice number...) already exists come back as "duplicate" so the screen can build an error file.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/backup.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$table = (string) ($d['table'] ?? '');
$cfg = BACKUP_TABLES[$table] ?? null;
if (!$cfg) {
    fail('Unknown table');
}
if (!empty($cfg['admin'])) {
    require_admin($user);
}
$rows = $d['rows'] ?? [];
if (!is_array($rows) || count($rows) > 1000) {
    fail('Send between 1 and 1000 rows per request');
}
@set_time_limit(300);

$counts = ['inserted' => 0, 'merged' => 0, 'duplicate' => 0, 'error' => 0];
$issues = [];
$notes = [];
global $pdo;
$pdo->beginTransaction();
foreach ($rows as $i => $raw) {
    $rowNo = is_array($raw) && isset($raw['__row']) ? (int) $raw['__row'] : $i + 2;
    if (!is_array($raw)) {
        $counts['error']++;
        $issues[] = ['row' => $rowNo, 'status' => 'error', 'message' => 'Invalid row'];
        continue;
    }
    unset($raw['__row']);
    $pdo->exec('SAVEPOINT r');
    try {
        [$status, $message] = backup_import_row($table, $raw);
    } catch (Throwable $e) {
        error_log('[Invoicyfy import] ' . $e->getMessage());
        [$status, $message] = ['error', 'Could not save this row'];
    }
    if ($status === 'error') {
        $pdo->exec('ROLLBACK TO SAVEPOINT r');
    }
    $counts[$status]++;
    if ($status === 'duplicate' || $status === 'error') {
        $issues[] = ['row' => $rowNo, 'status' => $status, 'message' => $message];
    } elseif ($message) {
        $notes[$message] = ($notes[$message] ?? 0) + 1;
    }
}
$pdo->commit();

json_out(['status' => 'success', 'table' => $table, 'counts' => $counts, 'issues' => $issues, 'notes' => $notes]);
