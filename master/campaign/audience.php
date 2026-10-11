<?php
/** GET ?customer_type=&activity=all|buyers|recent|inactive|top&days=30&top=50&city= — how many customers would get the offer. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('GET');
$user = require_auth();
$storeId = write_store($user, $_GET['store_id'] ?? null);
$a = campaign_audience($_GET, $storeId);
$cities = db_all("SELECT city, COUNT(*) AS n FROM customers WHERE city IS NOT NULL AND city <> '' GROUP BY city ORDER BY n DESC LIMIT 50");
json_out(['status' => 'success', 'data' => [
    'count' => count($a['recipients']),
    'matched' => $a['matched'],
    'opted_out' => $a['opted_out'],
    'no_phone' => $a['no_phone'],
    'sample' => array_slice($a['recipients'], 0, 8),
    'cities' => array_column($cities, 'city'),
]]);
