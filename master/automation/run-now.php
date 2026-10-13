<?php
/**
 * POST { store_id, job: summary|low_stock|birthday|reminders } — send now (to test the setup).
 * Birthday wishes and reminders count as today's run, so customers never get them twice in a day.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/automation.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$job = str_field($d, 'job');
if (!in_array($job, AUTOMATION_JOBS, true)) {
    fail('Unknown job');
}
$date = date('Y-m-d');
if (in_array($job, ['birthday', 'reminders'], true) && !claim_job($job, (int) $store['store_id'], $date)) {
    fail('Already sent today. It will run again tomorrow.', 409);
}
@set_time_limit(120);
$result = run_job($store, $job, $date, (int) $user['user_id']);
if (in_array($job, ['birthday', 'reminders'], true)) {
    db_query('UPDATE job_runs SET result = ?, ran_at = NOW() WHERE job = ? AND store_id = ? AND run_date = ?', [mb_substr($result, 0, 500), $job, $store['store_id'], $date]);
}
log_activity('automation.run', 'store', $store['store_id'], "Ran $job now: $result", [], $store['store_id']);
json_out(['status' => 'success', 'message' => $result]);
