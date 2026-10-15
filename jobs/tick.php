<?php
/**
 * Fallback scheduler: the open app calls this every few minutes, so daily automatic messages still go out
 * on hosting without a cron job. Any signed-in user may call it; each job runs at most once a day.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/automation.php';
allow_methods('POST');
require_auth();

@set_time_limit(60);
$log = run_scheduled_jobs(microtime(true) + 25);
stats_sync(); // report summaries for the days changed since the last tick
// Also push pending automatic messages a little further.
release_stuck_recipients();
foreach (array_column(db_all("SELECT campaign_id FROM campaigns WHERE status = 'Sending' AND channel = 'api' ORDER BY campaign_id LIMIT 3"), 'campaign_id') as $id) {
    process_campaign((int) $id, 10, microtime(true) + 10);
}
// Nightly backup, year archives and purge (lib/datajobs.php): queued once a night; worked on after this answer is sent.
require_once __DIR__ . '/../lib/datajobs.php';
if (data_nightly_queue()) {
    $log[] = 'night jobs queued';
}
if (data_jobs_open()) {
    after_response(fn() => data_jobs_run(20), true);
    $log[] = 'data jobs running';
}
json_out(['status' => 'success', 'ran' => $log]);
