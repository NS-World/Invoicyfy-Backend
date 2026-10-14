<?php
/** GET ?return=<backup screen URL> — Google sign-in link to connect Drive (Admin only). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);

$s = drive_settings();
if ($s['client_id'] === '' || $s['client_secret'] === '') {
    fail('Save the Google Client ID and Client secret first', 422);
}
$return = (string) ($_GET['return'] ?? '');
$host = parse_url($return, PHP_URL_HOST);
$apiHost = parse_url(api_public_base(), PHP_URL_HOST);
if (!preg_match('#^https?://#', $return) || !$host || ($host !== $apiHost && !in_array($host, ['localhost', '127.0.0.1'], true))) {
    $return = preg_replace('#/api$#', '', api_public_base()) . '/backup';
}
json_out(['status' => 'success', 'url' => drive_auth_url($s, drive_state((int) $user['user_id'], $return)), 'redirect_uri' => drive_redirect_uri()]);
