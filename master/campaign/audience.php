<?php
/** GET ?channels=whatsapp,email,sms&customer_type=&activity=all|buyers|recent|inactive|top&days=30&top=50&city= — who would get the offer. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('GET');
$user = require_auth();
$storeId = write_store($user, $_GET['store_id'] ?? null);
$channels = array_filter(explode(',', (string) ($_GET['channels'] ?? 'whatsapp')));
$a = campaign_audience($_GET, $storeId, $channels);
$cities = db_all("SELECT city, COUNT(*) AS n FROM customers WHERE city IS NOT NULL AND city <> '' GROUP BY city ORDER BY n DESC LIMIT 50");
$people = [];
foreach ($a['recipients'] as $r) {
    $people[$r['customer_id']] = $people[$r['customer_id']] ?? $r;
}
json_out(['status' => 'success', 'data' => [
    'count' => count($people),
    'messages' => count($a['recipients']),
    'counts' => $a['counts'],
    'matched' => $a['matched'],
    'opted_out' => $a['opted_out'],
    'no_phone' => $a['no_phone'],
    'no_email' => $a['no_email'],
    'sample' => array_slice(array_values($people), 0, 8),
    'cities' => array_column($cities, 'city'),
]]);
