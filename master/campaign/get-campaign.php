<?php
/** GET ?campaign_id=&status=Pending|Sent|Failed&page=&limit= — campaign with its recipients. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('GET');
$user = require_auth();
$c = load_campaign($user, int_field($_GET, 'campaign_id') ?? 0);
[$page, $limit, $offset, $search] = pagination();
$where = 'campaign_id = ?';
$params = [$c['campaign_id']];
if (in_array($_GET['status'] ?? '', ['Pending', 'Sent', 'Failed', 'Skipped'], true)) {
    $where .= ' AND status = ?';
    $params[] = $_GET['status'];
}
if (in_array($_GET['channel'] ?? '', ['whatsapp', 'email', 'sms'], true)) {
    $where .= ' AND channel = ?';
    $params[] = $_GET['channel'];
}
if ($search !== '') {
    $where .= ' AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$total = (int) db_value("SELECT COUNT(*) FROM campaign_recipients WHERE $where", $params);
$rows = db_all("SELECT * FROM campaign_recipients WHERE $where ORDER BY recipient_id LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));
$c['pending'] = (int) db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('Pending', 'Sending')", [$c['campaign_id']]);
$store = get_store($c['store_id']);
$c['by_channel'] = array_map(fn($r) => cast_numbers($r, [], ['total', 'sent', 'failed', 'pending']), db_all(
    "SELECT channel, COUNT(*) AS total, SUM(status = 'Sent') AS sent, SUM(status = 'Failed') AS failed, SUM(status IN ('Pending', 'Sending')) AS pending
     FROM campaign_recipients WHERE campaign_id = ? GROUP BY channel", [$c['campaign_id']]
));
foreach ($rows as &$r) {
    $r = cast_numbers($r, [], ['recipient_id', 'campaign_id', 'customer_id']);
    $r['text'] = campaign_text($c, $store, $r, true);
}
json_out(['status' => 'success', 'data' => $c, 'recipients' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'data' => $rows]]);
