<?php
/** GET ?store_id= — the open day (with live figures) and the last closed day. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('GET');
$user = require_auth();

$storeId = write_store($user, $_GET['store_id'] ?? null);
$open = db_one("SELECT session_id FROM cash_sessions WHERE store_id = ? AND status = 'Open' ORDER BY session_id DESC LIMIT 1", [$storeId]);
$last = db_one("SELECT session_id FROM cash_sessions WHERE store_id = ? AND status = 'Closed' ORDER BY closed_at DESC LIMIT 1", [$storeId]);
$pos = pos_settings(get_store($storeId));
json_out(['status' => 'success', 'data' => [
    'store_id' => $storeId,
    'require_day_open' => $pos['require_day_open'],
    'denominations' => CASH_DENOMINATIONS,
    'open' => $open ? cash_session_full(load_cash_session($user, (int) $open['session_id'])) : null,
    'last_closed' => $last ? cash_session_row(load_cash_session($user, (int) $last['session_id'])) : null,
]]);
