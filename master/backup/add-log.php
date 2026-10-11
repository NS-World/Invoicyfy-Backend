<?php
/** Record a finished backup or restore: POST { action: export|import, file_name, summary: {...} } */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$action = in_array($d['action'] ?? '', ['export', 'import'], true) ? $d['action'] : fail('action must be export or import');
$summary = is_array($d['summary'] ?? null) ? json_encode($d['summary']) : null;
$id = insert_row('backup_logs', [
    'action' => $action,
    'user_id' => $user['user_id'],
    'file_name' => mb_substr((string) ($d['file_name'] ?? ''), 0, 200),
    'summary' => $summary && strlen($summary) < 60000 ? $summary : null,
]);
json_out(['status' => 'success', 'backup_id' => $id]);
