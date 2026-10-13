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
json_out(['status' => 'success', 'ran' => $log]);
