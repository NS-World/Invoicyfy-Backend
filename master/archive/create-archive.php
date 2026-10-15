<?php
/** POST {fy} — make (or make again) the FINAL archive file of a finished financial year (Admin). Runs in steps. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$fy = (int) (input()['fy'] ?? 0);
if ($fy < 2000 || $fy >= fy_current()) {
    fail('Choose a finished financial year');
}
$a = fy_archive($fy);
if ($a && $a['purged_at']) {
    fail(fy_label($fy) . ' was purged; its archive file is the record now', 409);
}
$id = data_job_queue('archive', $fy, (int) $user['user_id']);
data_jobs_run(6);
json_out(['status' => 'success', 'message' => fy_label($fy) . ' archive started', 'job_id' => $id, 'data' => archive_overview()]);
