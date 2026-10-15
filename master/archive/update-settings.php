<?php
/** POST {keep_years: 0|3..10, logs_keep_months: 0|3|6|12|24|36, auto_archive, auto_purge, allow_local_archive} (Admin). */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$d = input();
$s = retention_settings();
$keep = (int) ($d['keep_years'] ?? $s['keep_years']);
if ($keep !== 0 && ($keep < 3 || $keep > 10)) {
    fail('Keep data for 3 to 10 years, or choose "Never delete"');
}
$logs = (int) ($d['logs_keep_months'] ?? $s['logs_keep_months']);
if (!in_array($logs, [0, 3, 6, 12, 24, 36], true)) {
    fail('Choose how long to keep logs');
}
$s['keep_years'] = $keep;
$s['logs_keep_months'] = $logs;
$s['auto_archive'] = bool_field($d, 'auto_archive', (bool) $s['auto_archive']);
$s['auto_purge'] = $keep > 0 && bool_field($d, 'auto_purge', (bool) $s['auto_purge']);
$s['allow_local_archive'] = bool_field($d, 'allow_local_archive', (bool) $s['allow_local_archive']);
retention_save($s);
log_activity('data.settings', null, null, 'Data keeping: ' . ($keep ? "$keep years" : 'never delete') . ', logs ' . ($logs ? "$logs months" : 'forever')
    . ', auto purge ' . ($s['auto_purge'] ? 'on' : 'off'), [], null);
json_out(['status' => 'success', 'message' => 'Data keeping settings saved', 'data' => archive_overview()]);
