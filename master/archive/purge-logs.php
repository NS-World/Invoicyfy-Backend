<?php
/** POST {confirm: "PURGE"} — remove activity / message / backup logs older than the chosen period (Admin). */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
if (strtoupper(trim((string) (input()['confirm'] ?? ''))) !== 'PURGE') {
    fail('Type PURGE to confirm');
}
if (!logs_cutoff()) {
    fail('Choose how long to keep logs first');
}
$id = data_job_queue('logs', null, (int) $user['user_id']);
data_jobs_run(6);
json_out(['status' => 'success', 'message' => 'Removing old logs', 'job_id' => $id, 'data' => archive_overview()]);
