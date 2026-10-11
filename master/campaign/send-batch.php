<?php
/** POST { campaign_id } — sends the next 20 pending messages through the WhatsApp API. Call again until pending = 0. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('POST');
$user = require_auth();
$c = load_campaign($user, int_field(input(), 'campaign_id') ?? 0);
if ($c['channel'] !== 'api') {
    fail('This campaign is sent manually from WhatsApp');
}
if ($c['status'] === 'Stopped') {
    fail('This campaign was stopped');
}
$store = get_store($c['store_id']);
@set_time_limit(120);
// Claim a batch so two open screens never send the same message twice.
$token = bin2hex(random_bytes(8));
db_query("UPDATE campaign_recipients SET status = 'Sending', provider_message_id = ? WHERE campaign_id = ? AND status = 'Pending' ORDER BY recipient_id LIMIT 20", [$token, $c['campaign_id']]);
$batch = db_all("SELECT * FROM campaign_recipients WHERE campaign_id = ? AND status = 'Sending' AND provider_message_id = ?", [$c['campaign_id'], $token]);
foreach ($batch as $r) {
    [$ok, $msgId, $err] = wa_send_template($store, $r['phone'], [$r['name'] ?: 'Customer', wa_personalise($c['message'], (string) $r['name'], $store['name'])]);
    db_query(
        'UPDATE campaign_recipients SET status = ?, error = ?, provider_message_id = ?, sent_at = IF(?, NOW(), NULL) WHERE recipient_id = ?',
        [$ok ? 'Sent' : 'Failed', $err, $msgId, $ok ? 1 : 0, $r['recipient_id']]
    );
}
refresh_campaign($c['campaign_id']);
$c = load_campaign($user, $c['campaign_id']);
$c['pending'] = (int) db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('Pending', 'Sending')", [$c['campaign_id']]);
json_out(['status' => 'success', 'processed' => count($batch), 'data' => $c]);
