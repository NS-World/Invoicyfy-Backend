<?php
/** POST { store_id, name, message, channel: api|manual, audience: {...} } — saves the campaign and its recipient list. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/whatsapp.php';
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
$channel = ($d['channel'] ?? 'manual') === 'api' ? 'api' : 'manual';
if ($channel === 'api' && !wa_settings($store)['api_ready']) {
    fail('WhatsApp API is not set up yet. Fill WhatsApp settings first or use "Send from my WhatsApp".');
}
$audience = is_array($d['audience'] ?? null) ? $d['audience'] : [];
$aud = campaign_audience($audience, (int) $store['store_id']);
if (!$aud['recipients']) {
    fail('No customers match this audience (customers need a mobile number and must not have opted out)');
}
global $pdo;
$pdo->beginTransaction();
$id = insert_row('campaigns', [
    'store_id' => $store['store_id'],
    'user_id' => $user['user_id'],
    'name' => $name,
    'message' => $message,
    'channel' => $channel,
    'audience' => json_encode(array_intersect_key($audience, array_flip(['customer_type', 'activity', 'days', 'top', 'city']))),
    'total' => count($aud['recipients']),
    'status' => 'Sending',
]);
$stmt = $pdo->prepare('INSERT INTO campaign_recipients (campaign_id, customer_id, name, phone) VALUES (?, ?, ?, ?)');
foreach ($aud['recipients'] as $r) {
    $stmt->execute([$id, $r['customer_id'], $r['name'], $r['phone']]);
}
$pdo->commit();
json_out(['status' => 'success', 'message' => 'Campaign created', 'data' => load_campaign($user, $id)], 201);
