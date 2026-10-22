<?php
/**
 * Offers & messages: WhatsApp, Email and SMS campaigns.
 *
 * WhatsApp offers (marketing messages).
 *
 * Two ways to send:
 *  - "api": WhatsApp Business Cloud API (Meta). Needs the business number's Phone number ID, a permanent access
 *    token and an approved marketing template whose body has {{1}} = customer name and {{2}} = offer text.
 *  - "manual": no setup. The screen opens WhatsApp with the message already typed for each customer
 *    (click-to-chat) from the phone/computer where the business number is logged in.
 * The access token is stored encrypted and is never sent back to the browser.
 */

function wa_key(): string
{
    $secret = (string) (env('APP_KEY', '') ?: env('JWT_ACCESS_SECRET', ''));
    return hash('sha256', 'invoicyfy-wa|' . $secret, true);
}

function wa_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', wa_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function wa_decrypt(?string $stored): ?string
{
    if (!$stored || !str_starts_with($stored, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', wa_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/** Indian numbers: 98250 12345 / 09825012345 / +91 98250-12345 => 919825012345. Returns null when unusable. */
function wa_phone(?string $phone): ?string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    if (strlen($d) === 10) {
        return preg_match('/^[6-9]/', $d) ? '91' . $d : null;
    }
    return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
}

/** {name} and {shop} placeholders. */
function wa_personalise(string $message, string $name, string $shop): string
{
    return strtr($message, ['{name}' => $name !== '' ? $name : 'Customer', '{shop}' => $shop]);
}

function wa_settings(array $store): array
{
    $token = wa_decrypt($store['wa_access_token'] ?? null);
    return [
        'store_id' => (int) $store['store_id'],
        'store_name' => $store['name'],
        'wa_business_number' => $store['wa_business_number'] ?? null,
        'wa_phone_number_id' => $store['wa_phone_number_id'] ?? null,
        'wa_template_name' => $store['wa_template_name'] ?? null,
        'wa_template_lang' => $store['wa_template_lang'] ?: 'en',
        'wa_template_image' => (bool) ($store['wa_template_image'] ?? false),
        'token_set' => $token !== null,
        'api_ready' => $token !== null && !empty($store['wa_phone_number_id']) && !empty($store['wa_template_name']),
    ];
}

/**
 * Send one template message through the Cloud API. Returns [ok, messageId|null, error|null].
 * Template parameters may not contain new lines or tabs, so those are flattened.
 */
function wa_send_template(array $store, string $to, array $params, ?string $imageUrl = null): array
{
    $token = wa_decrypt($store['wa_access_token'] ?? null);
    if (!$token || empty($store['wa_phone_number_id']) || empty($store['wa_template_name'])) {
        return [false, null, 'WhatsApp API is not set up for this store'];
    }
    $clean = array_map(fn($p) => mb_substr(trim(preg_replace('/\s{4,}/', '   ', preg_replace('/[\r\n\t]+/', ' | ', (string) $p))), 0, 1000), $params);
    $body = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => $store['wa_template_name'],
            'language' => ['code' => $store['wa_template_lang'] ?: 'en'],
            'components' => [[
                'type' => 'body',
                'parameters' => array_map(fn($t) => ['type' => 'text', 'text' => $t !== '' ? $t : '-'], $clean),
            ]],
        ],
    ];
    // Templates approved with an IMAGE header need the picture with every message.
    if (!empty($store['wa_template_image'])) {
        if (!$imageUrl) {
            return [false, null, 'Your WhatsApp template has an image header: add an offer image'];
        }
        array_unshift($body['template']['components'], ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $imageUrl]]]]);
    }
    $base = rtrim((string) env('WHATSAPP_API_BASE', 'https://graph.facebook.com/v21.0'), '/');
    $ch = curl_init("$base/" . rawurlencode($store['wa_phone_number_id']) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return [false, null, 'Could not reach WhatsApp: ' . $curlErr];
    }
    $json = json_decode($res, true) ?: [];
    if ($code >= 200 && $code < 300 && !empty($json['messages'][0]['id'])) {
        return [true, $json['messages'][0]['id'], null];
    }
    $msg = $json['error']['error_data']['details'] ?? $json['error']['message'] ?? "WhatsApp returned HTTP $code";
    return [false, null, mb_substr((string) $msg, 0, 250)];
}

/**
 * Customers matching an audience filter, as one recipient per channel: WhatsApp / SMS need a usable mobile number,
 * Email a valid address. Customers who opted out of offers are never included.
 */
/**
 * Who gets a campaign. $preview: only the counts and the first 8 people (the audience screen) — no full recipient list,
 * which on lakhs of customers is most of the time and memory.
 */
function campaign_audience(array $a, int $storeId, array $channels = ['whatsapp'], bool $preview = false): array
{
    $where = ['1=1' . customer_active_sql('c')];
    $params = [];
    $type = strtoupper((string) ($a['customer_type'] ?? ''));
    if (in_array($type, ['B2C', 'B2B'], true)) {
        $where[] = 'c.customer_type = ?';
        $params[] = $type;
    }
    if (!empty($a['city'])) {
        $where[] = 'c.city = ?';
        $params[] = (string) $a['city'];
    }
    $days = max(1, min(3650, (int) ($a['days'] ?? 30)));
    $since = date('Y-m-d', strtotime("-$days days"));
    $activity = (string) ($a['activity'] ?? 'all');
    $bought = "SELECT 1 FROM invoices i WHERE i.customer_id = c.customer_id AND i.store_id = ? AND i.status <> 'Cancelled'";
    if ($activity === 'recent') {
        $where[] = "EXISTS ($bought AND i.invoice_date >= ?)";
        array_push($params, $storeId, $since);
    } elseif ($activity === 'inactive') {
        $where[] = "EXISTS ($bought) AND NOT EXISTS ($bought AND i.invoice_date >= ?)";
        array_push($params, $storeId, $storeId, $since);
    } elseif ($activity === 'buyers') {
        $where[] = "EXISTS ($bought)";
        $params[] = $storeId;
    }
    $order = 'c.customer_id'; // table order: no sort of every customer (the audience can be lakhs of people)
    $limit = '';
    if ($activity === 'top') {
        $order = "(SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i WHERE i.customer_id = c.customer_id AND i.store_id = " . (int) $storeId . " AND i.status <> 'Cancelled') DESC";
        $limit = ' LIMIT ' . max(1, min(5000, (int) ($a['top'] ?? 50)));
    }
    $rows = db_all("SELECT c.customer_id, c.name, c.phone, c.email, c.marketing_opt_in FROM customers c WHERE " . implode(' AND ', $where) . " ORDER BY $order$limit", $params);

    $channels = array_values(array_intersect(['whatsapp', 'sms', 'email'], $channels)) ?: ['whatsapp'];
    $out = ['recipients' => [], 'opted_out' => 0, 'no_phone' => 0, 'no_email' => 0, 'matched' => count($rows), 'counts' => array_fill_keys($channels, 0)];
    $seen = [];
    $out['people'] = 0;
    foreach ($rows as $r) {
        if (!(int) $r['marketing_opt_in']) {
            $out['opted_out']++;
            continue;
        }
        $phone = wa_phone($r['phone']);
        $email = filter_var(trim((string) $r['email']), FILTER_VALIDATE_EMAIL) ?: null;
        $phoneNeeded = in_array('whatsapp', $channels, true) || in_array('sms', $channels, true);
        if ($phoneNeeded && !$phone) {
            $out['no_phone']++;
        }
        if (in_array('email', $channels, true) && !$email) {
            $out['no_email']++;
        }
        $reached = false;
        foreach ($channels as $ch) {
            $to = $ch === 'email' ? $email : $phone;
            if (!$to || isset($seen["$ch|$to"])) {
                continue;
            }
            $seen["$ch|$to"] = true;
            $out['counts'][$ch]++;
            $reached = true;
            if (!$preview || $out['people'] < 8) {
                $out['recipients'][] = ['customer_id' => (int) $r['customer_id'], 'name' => $r['name'], 'channel' => $ch,
                    'phone' => $ch === 'email' ? null : $phone, 'email' => $ch === 'email' ? $email : null];
            }
        }
        $out['people'] += $reached ? 1 : 0;
    }
    return $out;
}

function campaign_row(array $c): array
{
    $c = cast_numbers($c, [], ['campaign_id', 'store_id', 'user_id', 'total', 'sent', 'failed']);
    $c['audience'] = $c['audience'] ? json_decode($c['audience'], true) : null;
    return $c;
}

function load_campaign(array $user, int $id): array
{
    $c = db_one('SELECT c.*, u.name AS user_name FROM campaigns c LEFT JOIN users u ON u.user_id = c.user_id WHERE c.campaign_id = ?', [$id]);
    if (!$c) {
        fail('Campaign not found', 404);
    }
    assert_store_access($user, $c['store_id']);
    return campaign_row($c);
}

/** Recount sent / failed and close the campaign when nothing is pending. */
function refresh_campaign(int $id): void
{
    $s = db_one(
        "SELECT SUM(status = 'Sent') AS sent, SUM(status = 'Failed') AS failed, SUM(status IN ('Pending', 'Sending')) AS pending FROM campaign_recipients WHERE campaign_id = ?",
        [$id]
    );
    $done = (int) $s['pending'] === 0;
    $stopped = db_value('SELECT status FROM campaigns WHERE campaign_id = ?', [$id]) === 'Stopped';
    db_query(
        'UPDATE campaigns SET sent = ?, failed = ?, status = ?' . ($done ? ', completed_at = COALESCE(completed_at, NOW())' : '') . ' WHERE campaign_id = ?',
        [(int) $s['sent'], (int) $s['failed'], $stopped ? 'Stopped' : ($done ? 'Completed' : 'Sending'), $id]
    );
}
