<?php
/**
 * Google sends the browser back here after "Allow". No login token on this request: the signed, short-lived
 * state from drive-connect.php proves an Admin started it. Then we go back to the Backup screen.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('GET');

$state = drive_check_state((string) ($_GET['state'] ?? ''));
if (!$state) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(400);
    echo 'This Google Drive link has expired. Go back to Backup & Restore and press "Connect Google Drive" again.';
    exit;
}
$admin = load_user((int) $state['u']);
$back = (string) $state['r'];
$sep = str_contains($back, '?') ? '&' : '?';
if (!$admin || !is_admin($admin)) {
    header('Location: ' . $back . $sep . 'drive=error&message=' . rawurlencode('Only an Admin can connect Google Drive'), true, 302);
    exit;
}
if (!empty($_GET['error'])) {
    header('Location: ' . $back . $sep . 'drive=error&message=' . rawurlencode('Google: ' . mb_substr((string) $_GET['error'], 0, 80)), true, 302);
    exit;
}
try {
    $s = drive_finish_connect((string) ($_GET['code'] ?? ''));
    log_activity('backup.drive_connect', null, null, 'Google Drive connected' . ($s['email'] ? " ({$s['email']})" : ''), [], null, $admin);
    header('Location: ' . $back . $sep . 'drive=connected', true, 302);
} catch (Throwable $e) {
    header('Location: ' . $back . $sep . 'drive=error&message=' . rawurlencode(mb_substr($e->getMessage(), 0, 200)), true, 302);
}
exit;
