<?php
/** GET ?session_id= — one day with its full report (Z report). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('GET');
$user = require_auth();

json_out(['status' => 'success', 'data' => cash_session_full(load_cash_session($user, int_field($_GET, 'session_id') ?? 0))]);
