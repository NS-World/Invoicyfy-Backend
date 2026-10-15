<?php
/** GET ?fy= — download the FINAL archive file of a year kept on the server (Admin). Remembered as "downloaded". */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);
$a = fy_archive((int) ($_GET['fy'] ?? 0));
if (!$a || !is_file($path = archive_dir() . '/' . basename($a['file_name']))) {
    fail('Archive file not found on the server', 404);
}
db_query('UPDATE fy_archives SET downloaded_at = NOW() WHERE fy = ?', [$a['fy']]);
log_activity('data.download', null, null, 'Downloaded archive ' . $a['file_name'], [], null);
header_remove('Content-Encoding');
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . basename($a['file_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
