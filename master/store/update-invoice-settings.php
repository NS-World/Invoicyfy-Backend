<?php
/** Save the invoice designer (template, colours, sections, QR codes) and optionally the logo. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('POST', 'PUT');
$user = require_auth();

$d = input();
$id = int_field($d, 'store_id', $user['store_id'] !== null ? (int) $user['store_id'] : null);
if (!$id || !db_value("SELECT 1 FROM stores WHERE store_id = ?", [$id])) {
    fail('Store not found', 404);
}
assert_store_access($user, $id);
$settings = clean_invoice_settings($d['invoice_settings'] ?? []);
$json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (strlen($json) > 1500 * 1024) {
    fail('Invoice settings are too large; use smaller QR images');
}
if (array_key_exists('logo', $d)) {
    db_query("UPDATE stores SET invoice_settings = ?, logo = ? WHERE store_id = ?", [$json, clean_image_data($d['logo'], 500 * 1024, 'Logo'), $id]);
} else {
    db_query("UPDATE stores SET invoice_settings = ? WHERE store_id = ?", [$json, $id]);
}
json_out(["status" => "success", "message" => "Invoice design saved", "data" => db_one("SELECT * FROM stores WHERE store_id = ?", [$id])]);
