<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$s = drive_settings();
$s['refresh_token'] = '';
$s['email'] = '';
$s['folder_id'] = '';
drive_save($s);
log_activity('backup.drive_disconnect', null, null, 'Google Drive disconnected', [], null);
json_out(['status' => 'success', 'message' => 'Google Drive disconnected. Backups are still saved on the server.', 'data' => drive_public(drive_settings())]);
