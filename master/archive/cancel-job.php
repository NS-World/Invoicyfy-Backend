<?php
/** POST {job_id} — stop a waiting / running job (rows already removed by a purge stay removed). Admin. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$id = (int) (input()['job_id'] ?? 0);
$n = db_query("UPDATE data_jobs SET status = 'cancelled', stage = 'cancelled', finished_at = NOW(), message = CONCAT(COALESCE(message, ''), ' Stopped by ', ?) WHERE job_id = ? AND status IN ('queued','running')",
    [$user['name'] ?? 'Admin', $id])->rowCount();
if (!$n) {
    fail('This job is not running', 404);
}
log_activity('data.cancel', null, null, "Stopped data job #$id", [], null);
json_out(['status' => 'success', 'message' => 'Stopped', 'data' => archive_overview()]);
