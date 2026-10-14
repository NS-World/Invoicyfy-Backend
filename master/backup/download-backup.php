<?php
/** GET ?file=invoicyfy-backup-....json.gz — download a backup kept on the server (Admin only). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);
$name = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^invoicyfy-backup-\d{8}-\d{6}\.json\.gz$/', $name) || !is_file($path = backup_dir() . "/$name")) {
    fail('Backup file not found', 404);
}
header_remove('Content-Encoding');
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
log_activity('backup.download', null, null, "Downloaded backup $name", [], null);
readfile($path);
exit;
