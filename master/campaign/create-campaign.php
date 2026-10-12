<?php
/**
 * POST { store_id, name, message, subject, image_url, channels: ["whatsapp","email","sms"], whatsapp_mode: api|manual, audience: {...} }
 * Saves the campaign with one recipient row per customer per channel. Automatic channels then go out from the
 * screen (send-batch.php) and from the cron job, so one click sends to everyone.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST');
$user = require_auth();
$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$name = str_field($d, 'name') ?: fail('Give this offer a name, e.g. "Diwali 20% off"');
$message = str_field($d, 'message') ?: fail('Write the offer message');
if (mb_strlen($message) > 1000) {
    fail('Message is too long (max 1000 characters)');
}
if (mb_strlen($name) > 150) {
    fail('Name is too long');
}
$channels = array_values(array_unique(array_intersect(['whatsapp', 'email', 'sms'], (array) ($d['channels'] ?? ['whatsapp']))));
if (!$channels) {
    fail('Choose at least one way to send: WhatsApp, Email or SMS');
}
$cs = channel_settings_public($store);
// Old screens sent "channel"; new ones send whatsapp_mode.
$waMode = ($d['whatsapp_mode'] ?? $d['channel'] ?? 'manual') === 'api' ? 'api' : 'manual';
if (in_array('whatsapp', $channels, true) && $waMode === 'api' && !$cs['whatsapp']['api_ready']) {
    fail('WhatsApp API is not set up yet. Fill WhatsApp settings first or use "Send from my WhatsApp".');
}
if (in_array('email', $channels, true) && !$cs['email']['ready']) {
    fail('Email is not set up yet. Fill the Email settings (SMTP) first.');
}
if (in_array('sms', $channels, true) && !$cs['sms']['ready']) {
    fail('SMS is not set up yet. Fill the SMS settings first.');
}
$image = trim((string) ($d['image_url'] ?? ''));
if ($image !== '' && !preg_match('#^https?://[^\s]+$#i', $image)) {
    fail('Offer picture link is not valid');
}
$audience = is_array($d['audience'] ?? null) ? $d['audience'] : [];
$aud = campaign_audience($audience, (int) $store['store_id'], $channels);
if (!$aud['recipients']) {
    fail('No customers match this audience (they need a mobile number / email and must not have opted out)');
}
global $pdo;
$pdo->beginTransaction();
$id = insert_row('campaigns', [
    'store_id' => $store['store_id'],
    'user_id' => $user['user_id'],
    'name' => $name,
    'message' => $message,
    'subject' => mb_substr((string) str_field($d, 'subject', ''), 0, 200) ?: null,
    'image_url' => $image ?: null,
    'channel' => $waMode,
    'channels' => implode(',', $channels),
    'audience' => json_encode(array_intersect_key($audience, array_flip(['customer_type', 'activity', 'days', 'top', 'city']))),
    'total' => count($aud['recipients']),
    'status' => 'Sending',
]);
$stmt = $pdo->prepare('INSERT INTO campaign_recipients (campaign_id, customer_id, name, channel, phone, email) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($aud['recipients'] as $r) {
    $stmt->execute([$id, $r['customer_id'], $r['name'], $r['channel'], $r['phone'], $r['email']]);
}
$pdo->commit();
json_out(['status' => 'success', 'message' => 'Campaign created', 'data' => load_campaign($user, $id) + ['counts' => $aud['counts']]], 201);
