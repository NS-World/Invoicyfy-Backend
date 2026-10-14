<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();
json_out(['status' => 'success', 'data' => load_purchase($user, (int) ($_GET['purchase_id'] ?? 0))]);
