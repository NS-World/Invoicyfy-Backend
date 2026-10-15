<?php
/** POST {fy, confirm: "PURGE"} — remove that year's old records from the live database (Admin). Runs in steps. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$d = input();
$fy = (int) ($d['fy'] ?? 0);
if (strtoupper(trim((string) ($d['confirm'] ?? ''))) !== 'PURGE') {
    fail('Type PURGE to confirm');
}
if ($b = purge_blocker($fy)) {
    fail($b, 409);
}
$id = data_job_queue('purge', $fy, (int) $user['user_id']);
log_activity('data.purge.start', null, null, 'Started purge of ' . fy_label($fy), [], null);
data_jobs_run(6);
json_out(['status' => 'success', 'message' => 'Purge of ' . fy_label($fy) . ' started', 'job_id' => $id, 'data' => archive_overview()]);
