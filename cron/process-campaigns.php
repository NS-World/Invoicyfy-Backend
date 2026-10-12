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

$deadline = microtime(true) + 50;
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
echo date('Y-m-d H:i:s') . " campaigns=" . count($ids) . " processed=$sent released=$released" . PHP_EOL;
