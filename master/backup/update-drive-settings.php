<?php
/** POST { enabled, time, client_id, client_secret?, keep, local_keep } (Admin only). Empty client_secret keeps the saved one. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);

$d = input();
$s = drive_settings();
$s['enabled'] = bool_field($d, 'enabled', $s['enabled']);
$s['time'] = setting_time((string) ($d['time'] ?? $s['time']), 'Backup time');
$s['keep'] = (int) setting_num($d['keep'] ?? $s['keep'], 1, 90, 'Backups kept on Drive');
$s['local_keep'] = (int) setting_num($d['local_keep'] ?? $s['local_keep'], 1, 30, 'Backups kept on the server');
$clientId = trim((string) ($d['client_id'] ?? $s['client_id']));
if ($clientId !== '' && !preg_match('/^[A-Za-z0-9._-]{10,200}\.apps\.googleusercontent\.com$/', $clientId)) {
    fail('Client ID looks wrong. It ends with .apps.googleusercontent.com');
}
if ($clientId !== $s['client_id']) {
    $s['refresh_token'] = ''; // a different Google app: connect again
    $s['email'] = '';
    $s['folder_id'] = '';
}
$s['client_id'] = $clientId;
$secret = trim((string) ($d['client_secret'] ?? ''));
if ($secret !== '') {
    if (mb_strlen($secret) > 200) {
        fail('Client secret is too long');
    }
    $s['client_secret'] = wa_encrypt($secret);
}
drive_save($s);
log_activity('backup.settings', null, null, 'Automatic backup ' . ($s['enabled'] ? "on at {$s['time']}" : 'off'), [], null);
json_out(['status' => 'success', 'message' => 'Backup settings saved', 'data' => drive_public(drive_settings())]);
