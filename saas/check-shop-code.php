<?php
/** GET ?code= (is this shop code free?) or ?name= (suggest a code from the business name). */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$code = strtolower(trim((string) ($_GET['code'] ?? '')));
if ($code !== '') {
    json_out(['status' => 'success', 'code' => $code, 'valid' => saas_valid_slug($code), 'available' => saas_slug_free($code)]);
}
$name = trim((string) ($_GET['name'] ?? ''));
json_out(['status' => 'success', 'code' => $name !== '' ? saas_suggest_slug($name) : '', 'available' => true, 'valid' => true]);
