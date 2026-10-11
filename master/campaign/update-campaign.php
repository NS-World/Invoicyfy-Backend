<?php
/** POST { campaign_id, action: stop | resume | retry_failed } */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('POST');
$user = require_auth();
$d = input();
$c = load_campaign($user, int_field($d, 'campaign_id') ?? 0);
$action = $d['action'] ?? '';
if ($action === 'stop') {
    db_query("UPDATE campaigns SET status = 'Stopped' WHERE campaign_id = ?", [$c['campaign_id']]);
} elseif ($action === 'resume') {
    db_query("UPDATE campaigns SET status = 'Sending' WHERE campaign_id = ?", [$c['campaign_id']]);
    db_query("UPDATE campaign_recipients SET status = 'Pending' WHERE campaign_id = ? AND status = 'Sending'", [$c['campaign_id']]);
    refresh_campaign($c['campaign_id']);
} elseif ($action === 'retry_failed') {
    db_query("UPDATE campaign_recipients SET status = 'Pending', error = NULL WHERE campaign_id = ? AND status = 'Failed'", [$c['campaign_id']]);
    db_query("UPDATE campaigns SET status = 'Sending', completed_at = NULL WHERE campaign_id = ?", [$c['campaign_id']]);
    refresh_campaign($c['campaign_id']);
} else {
    fail('Unknown action');
}
json_out(['status' => 'success', 'data' => load_campaign($user, $c['campaign_id'])]);
