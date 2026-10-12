<?php
/** POST { store_id, wa_business_number, wa_phone_number_id, wa_access_token (blank = keep), wa_template_name, wa_template_lang, clear_token } */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
allow_methods('POST', 'PUT');
$user = require_auth();
$d = input();
$storeId = write_store($user, $d['store_id'] ?? null);
$number = str_field($d, 'wa_business_number');
if ($number && !wa_phone($number)) {
    fail('Enter the WhatsApp business number with country code, e.g. 919825012345');
}
$phoneId = str_field($d, 'wa_phone_number_id');
if ($phoneId && !preg_match('/^\d{6,30}$/', $phoneId)) {
    fail('Phone number ID is the long number shown in Meta WhatsApp Manager (digits only)');
}
$template = str_field($d, 'wa_template_name');
if ($template && !preg_match('/^[a-z0-9_]{1,100}$/', $template)) {
    fail('Template name can only have small letters, numbers and _ (as in WhatsApp Manager)');
}
$lang = str_field($d, 'wa_template_lang', 'en') ?: 'en';
if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang)) {
    fail('Template language looks wrong (examples: en, en_US, hi, gu)');
}
$row = [
    'wa_business_number' => $number ? wa_phone($number) : null,
    'wa_phone_number_id' => $phoneId,
    'wa_template_name' => $template,
    'wa_template_lang' => $lang,
    'wa_template_image' => !empty($d['wa_template_image']) ? 1 : 0,
];
$token = trim((string) ($d['wa_access_token'] ?? ''));
if ($token !== '') {
    if (strlen($token) < 20 || preg_match('/\s/', $token)) {
        fail('Access token looks incomplete. Copy the full permanent token from Meta.');
    }
    $row['wa_access_token'] = wa_encrypt($token);
} elseif (!empty($d['clear_token'])) {
    $row['wa_access_token'] = null;
}
update_row('stores', 'store_id', $storeId, $row);
json_out(['status' => 'success', 'message' => 'WhatsApp settings saved', 'data' => wa_settings(get_store($storeId))]);
