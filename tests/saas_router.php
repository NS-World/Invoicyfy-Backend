<?php
/**
 * PHP built-in server router for the SaaS tests: does what .htaccess does on Hostinger for public shop links,
 *   /t/<shop>/api/<script>  ->  <script> with KHANAK_TENANT=<shop>
 * and serves every other PHP script as usual.
 *   SAAS_MODE=true ... php -S 127.0.0.1:8095 tests/saas_router.php
 */
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/t/([a-z0-9-]{3,30})/api/(.+)$#', $path, $m)) {
    $_SERVER['KHANAK_TENANT'] = $m[1];
    $path = '/' . $m[2];
}
$file = realpath($root . $path);
if (!$file || strpos($file, $root) !== 0 || !is_file($file) || substr($file, -4) !== '.php') {
    http_response_code(404);
    echo '{"status":"error","message":"Not found"}';
    return true;
}
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $path;
chdir(dirname($file));
require $file;
