<?php
/** POST { campaign_id } — sends the next 20 automatic messages (WhatsApp API, Email, SMS). Call again until pending = 0. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST');
$user = require_auth();
$c = load_campaign($user, int_field(input(), 'campaign_id') ?? 0);
if ($c['status'] === 'Stopped') {
    fail('This campaign was stopped');
}
@set_time_limit(120);
$processed = process_campaign($c['campaign_id'], 20, microtime(true) + 50);
$c = load_campaign($user, $c['campaign_id']);
$auto = $c['channel'] === 'api' ? '' : " AND channel <> 'whatsapp'";
$c['pending'] = (int) db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('Pending', 'Sending')", [$c['campaign_id']]);
$c['pending_auto'] = (int) db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('Pending', 'Sending')$auto", [$c['campaign_id']]);
json_out(['status' => 'success', 'processed' => $processed, 'data' => $c]);
