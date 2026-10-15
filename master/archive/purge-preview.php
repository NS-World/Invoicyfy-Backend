<?php
/** GET ?fy= — what a purge of that year removes and keeps, and whether it is allowed now (Admin). */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);
$fy = (int) ($_GET['fy'] ?? 0);
if ($fy < 2000 || $fy > fy_current()) {
    fail('Choose a financial year');
}
json_out(['status' => 'success', 'data' => purge_preview($fy) + ['blocker' => purge_blocker($fy), 'archive' => fy_archive($fy)]]);
