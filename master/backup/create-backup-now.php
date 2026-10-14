<?php
/** POST — make a full backup now (server copy + Google Drive when connected). Admin only. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$r = run_backup((int) $user['user_id']);
log_activity('backup.run', null, null, $r['message'], [], null);
json_out(['status' => $r['ok'] ? 'success' : 'error', 'message' => $r['message'], 'data' => drive_public(drive_settings())], $r['ok'] ? 200 : 502);
