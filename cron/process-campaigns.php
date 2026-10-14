<?php
/**
 * Background sender for offers, so campaigns finish even when nobody keeps the screen open.
 * Hostinger: hPanel > Advanced > Cron Jobs > "Every minute", command:
 *   /usr/bin/php /home/<user>/domains/<domain>/public_html/billing/api/cron/process-campaigns.php
 * Runs for at most ~50 seconds per call.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/channels.php';
set_exception_handler(null);

require_once __DIR__ . '/../lib/automation.php';

$deadline = microtime(true) + 50;
// Daily automatic messages (summary, low stock, birthdays, reminders) when their time has come.
$jobs = run_scheduled_jobs(microtime(true) + 20);
// Report summaries: refresh the days changed since the last minute, so reports never wait for it.
// Once a day also re-check today and yesterday from scratch (safety net for changes made outside the app).
if (claim_job('stats_recheck', 0, date('Y-m-d'))) {
    foreach (db_all('SELECT store_id FROM stores') as $st) {
        stats_touch($st['store_id'], date('Y-m-d'));
        stats_touch($st['store_id'], date('Y-m-d', strtotime('-1 day')));
    }
}
stats_sync();
// V4: nightly backup (server copy + Google Drive).
require_once __DIR__ . '/../lib/drive.php';
if (backup_due()) {
    $jobs[] = run_backup()['message'];
}
db_query("REPLACE INTO job_runs (job, store_id, run_date, ran_at, result) VALUES ('cron', 0, CURDATE(), NOW(), 'ok')");
$released = release_stuck_recipients();
$sent = 0;
$ids = array_map('intval', array_column(db_all("SELECT campaign_id FROM campaigns WHERE status = 'Sending' ORDER BY campaign_id"), 'campaign_id'));
foreach ($ids as $id) {
    while (microtime(true) < $deadline) {
        $n = process_campaign($id, 25, $deadline);
        $sent += $n;
        if ($n === 0) {
            break;
        }
    }
}
echo date('Y-m-d H:i:s') . " campaigns=" . count($ids) . " processed=$sent released=$released" . ($jobs ? ' jobs: ' . implode(' | ', $jobs) : '') . PHP_EOL;
