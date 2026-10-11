<?php
/** POST { recipient_id, status: Sent|Pending|Skipped } — manual WhatsApp sending marks each customer as done. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('POST');
$user = require_auth();
$d = input();
$r = db_one('SELECT * FROM campaign_recipients WHERE recipient_id = ?', [int_field($d, 'recipient_id') ?? 0]) ?: fail('Recipient not found', 404);
$c = load_campaign($user, (int) $r['campaign_id']);
$status = in_array($d['status'] ?? 'Sent', ['Sent', 'Pending', 'Skipped'], true) ? ($d['status'] ?? 'Sent') : 'Sent';
db_query('UPDATE campaign_recipients SET status = ?, sent_at = IF(? = \'Sent\', NOW(), NULL), error = NULL WHERE recipient_id = ?', [$status, $status, $r['recipient_id']]);
refresh_campaign($c['campaign_id']);
json_out(['status' => 'success', 'data' => load_campaign($user, $c['campaign_id'])]);
