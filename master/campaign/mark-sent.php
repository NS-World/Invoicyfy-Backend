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

// Values are decided in PHP (no "? = 'text'" in SQL), so hosts with a different default collation work too.
db_query(
    'UPDATE campaign_recipients SET status = ?, sent_at = ' . ($status === 'Sent' ? 'NOW()' : 'NULL') . ', error = NULL WHERE recipient_id = ?',
    [$status, $r['recipient_id']]
);
$s = db_one(
    "SELECT SUM(status = 'Sent') AS sent, SUM(status = 'Failed') AS failed, SUM(status IN ('Pending', 'Sending')) AS pending FROM campaign_recipients WHERE campaign_id = ?",
    [$c['campaign_id']]
);
$done = (int) $s['pending'] === 0;
$newStatus = $c['status'] === 'Stopped' ? 'Stopped' : ($done ? 'Completed' : 'Sending');
db_query(
    'UPDATE campaigns SET sent = ?, failed = ?, status = ?' . ($done ? ', completed_at = COALESCE(completed_at, NOW())' : '') . ' WHERE campaign_id = ?',
    [(int) $s['sent'], (int) $s['failed'], $newStatus, $c['campaign_id']]
);
json_out(['status' => 'success', 'data' => load_campaign($user, $c['campaign_id'])]);
