<?php
/**
 * Email (SMTP) and SMS gateway sending for offers, plus the campaign worker used by the screen
 * (send-batch.php) and by the cron job (cron/process-campaigns.php).
 *
 * Settings live in stores.channel_settings (JSON). Passwords / API keys are stored encrypted (wa_encrypt)
 * and are never sent back to the browser.
 */
require_once __DIR__ . '/whatsapp.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

const SMS_PROVIDERS = ['fast2sms', 'custom'];

function channel_settings_raw(array $store): array
{
    $raw = json_decode((string) ($store['channel_settings'] ?? ''), true);
    return is_array($raw) ? $raw + ['email' => [], 'sms' => []] : ['email' => [], 'sms' => []];
}

/** Settings safe to show on screen (secrets replaced by "is set" flags). */
function channel_settings_public(array $store): array
{
    $raw = channel_settings_raw($store);
    $e = (array) $raw['email'];
    $s = (array) $raw['sms'];
    $email = [
        'smtp_host' => $e['smtp_host'] ?? '',
        'smtp_port' => (int) ($e['smtp_port'] ?? 465),
        'smtp_secure' => $e['smtp_secure'] ?? 'ssl',
        'smtp_user' => $e['smtp_user'] ?? '',
        'from_email' => $e['from_email'] ?? '',
        'from_name' => $e['from_name'] ?? ($store['name'] ?? ''),
        'reply_to' => $e['reply_to'] ?? '',
        'pass_set' => !empty($e['smtp_pass']),
    ];
    $email['ready'] = $email['smtp_host'] !== '' && $email['from_email'] !== '' && ($email['smtp_user'] === '' || $email['pass_set']);
    $sms = [
        'provider' => $s['provider'] ?? '',
        'sender_id' => $s['sender_id'] ?? '',
        'custom_url' => $s['custom_url'] ?? '',
        'custom_method' => $s['custom_method'] ?? 'GET',
        'custom_body' => $s['custom_body'] ?? '',
        'custom_headers' => $s['custom_headers'] ?? '',
        'success_text' => $s['success_text'] ?? '',
        'key_set' => !empty($s['api_key']),
    ];
    $sms['ready'] = $sms['provider'] === 'fast2sms' ? $sms['key_set'] : ($sms['provider'] === 'custom' && $sms['custom_url'] !== '');
    return ['email' => $email, 'sms' => $sms, 'whatsapp' => wa_settings($store)];
}

/** Merge new settings from the screen; blank password / key keeps the saved one. */
function save_channel_settings(array $store, array $d): array
{
    $raw = channel_settings_raw($store);
    if (isset($d['email']) && is_array($d['email'])) {
        $e = $d['email'];
        $cur = (array) $raw['email'];
        $host = trim((string) ($e['smtp_host'] ?? ''));
        if ($host !== '' && !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            fail('SMTP server looks wrong (example: smtp.hostinger.com)');
        }
        $from = trim((string) ($e['from_email'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            fail('Enter a valid "from" email address');
        }
        $replyTo = trim((string) ($e['reply_to'] ?? ''));
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            fail('Enter a valid reply-to email address');
        }
        $port = (int) ($e['smtp_port'] ?? 465);
        $next = [
            'smtp_host' => $host,
            'smtp_port' => $port > 0 && $port < 65536 ? $port : 465,
            'smtp_secure' => in_array($e['smtp_secure'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $e['smtp_secure'] : 'ssl',
            'smtp_user' => mb_substr(trim((string) ($e['smtp_user'] ?? '')), 0, 190),
            'from_email' => $from,
            'from_name' => mb_substr(trim((string) ($e['from_name'] ?? '')), 0, 100),
            'reply_to' => $replyTo,
            'smtp_pass' => $cur['smtp_pass'] ?? null,
        ];
        $pass = (string) ($e['smtp_pass'] ?? '');
        if ($pass !== '') {
            $next['smtp_pass'] = wa_encrypt($pass);
        } elseif (!empty($e['clear_pass'])) {
            $next['smtp_pass'] = null;
        }
        $raw['email'] = $next;
    }
    if (isset($d['sms']) && is_array($d['sms'])) {
        $s = $d['sms'];
        $cur = (array) $raw['sms'];
        $provider = in_array($s['provider'] ?? '', SMS_PROVIDERS, true) ? $s['provider'] : '';
        $url = trim((string) ($s['custom_url'] ?? ''));
        if ($provider === 'custom' && !preg_match('#^https?://#i', $url)) {
            fail('SMS gateway URL must start with https://');
        }
        $next = [
            'provider' => $provider,
            'sender_id' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($s['sender_id'] ?? '')), 0, 11),
            'custom_url' => mb_substr($url, 0, 1000),
            'custom_method' => strtoupper((string) ($s['custom_method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET',
            'custom_body' => mb_substr((string) ($s['custom_body'] ?? ''), 0, 2000),
            'custom_headers' => mb_substr((string) ($s['custom_headers'] ?? ''), 0, 1000),
            'success_text' => mb_substr(trim((string) ($s['success_text'] ?? '')), 0, 100),
            'api_key' => $cur['api_key'] ?? null,
        ];
        $key = trim((string) ($s['api_key'] ?? ''));
        if ($key !== '') {
            $next['api_key'] = wa_encrypt($key);
        } elseif (!empty($s['clear_key'])) {
            $next['api_key'] = null;
        }
        $raw['sms'] = $next;
    }
    db_query('UPDATE stores SET channel_settings = ? WHERE store_id = ?', [json_encode($raw, JSON_UNESCAPED_UNICODE), $store['store_id']]);
    return channel_settings_public(get_store((int) $store['store_id']));
}

/* --------------------------------------------------------------------------------------------- Email */

/** One SMTP connection per store per run (kept open for the whole batch). */
function email_mailer(array $store): PHPMailer
{
    static $pool = [];
    $id = (int) $store['store_id'];
    if (isset($pool[$id])) {
        return $pool[$id];
    }
    $e = (array) channel_settings_raw($store)['email'];
    if (empty($e['smtp_host']) || empty($e['from_email'])) {
        throw new MailException('Email is not set up yet (SMTP server and from address)');
    }
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $e['smtp_host'];
    $m->Port = (int) ($e['smtp_port'] ?? 465);
    $secure = $e['smtp_secure'] ?? 'ssl';
    $m->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
    $m->SMTPAutoTLS = $secure !== 'none';
    $m->SMTPAuth = ($e['smtp_user'] ?? '') !== '';
    $m->Username = $e['smtp_user'] ?? '';
    $m->Password = (string) wa_decrypt($e['smtp_pass'] ?? null);
    $m->SMTPKeepAlive = true;
    $m->Timeout = 20;
    $m->CharSet = 'UTF-8';
    $m->setFrom($e['from_email'], $e['from_name'] ?: ($store['name'] ?? ''));
    if (!empty($e['reply_to'])) {
        $m->addReplyTo($e['reply_to']);
    }
    return $pool[$id] = $m;
}

function email_html(array $store, string $text, ?string $imageUrl): string
{
    $shop = htmlspecialchars((string) ($store['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $body = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    $img = $imageUrl ? '<p style="margin:0 0 16px"><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:100%;border-radius:8px"></p>' : '';
    $contact = htmlspecialchars(trim(implode(' · ', array_filter([$store['phone'] ?? '', $store['address'] ?? '']))), ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:560px;margin:0 auto;padding:16px">'
        . '<div style="background:#045864;color:#fff;padding:14px 18px;border-radius:10px 10px 0 0;font-size:18px;font-weight:bold">' . $shop . '</div>'
        . '<div style="background:#fff;padding:18px;border-radius:0 0 10px 10px;font-size:15px;line-height:1.5;color:#1a1d21">' . $img . $body . '</div>'
        . '<p style="font-size:12px;color:#777;text-align:center;margin:12px 0">' . $contact . '<br>You received this because you are a customer of ' . $shop
        . '. Reply "STOP" to stop offers.</p></div></body></html>';
}

/** Returns [ok, messageId|null, error|null]. */
function email_send(array $store, string $to, string $subject, string $text, ?string $imageUrl = null): array
{
    try {
        $m = email_mailer($store);
        $m->clearAllRecipients();
        $m->addAddress($to);
        $m->Subject = $subject !== '' ? $subject : 'Offer from ' . ($store['name'] ?? '');
        $m->isHTML(true);
        $m->Body = email_html($store, $text, $imageUrl);
        $m->AltBody = $text . ($imageUrl ? "\n\n$imageUrl" : '');
        $m->send();
        return [true, $m->getLastMessageID() ?: null, null];
    } catch (MailException $e) {
        $err = $e->getMessage();
        if (isset($m)) {
            $err = $m->ErrorInfo ?: $err;
            $m->smtpClose(); // reconnect on the next message
        }
        return [false, null, mb_substr('Email: ' . $err, 0, 250)];
    }
}

/* ----------------------------------------------------------------------------------------------- SMS */

/** Returns [ok, messageId|null, error|null]. $phone is 91XXXXXXXXXX. */
function sms_send(array $store, string $phone, string $text): array
{
    $s = (array) channel_settings_raw($store)['sms'];
    $provider = $s['provider'] ?? '';
    $key = (string) wa_decrypt($s['api_key'] ?? null);
    $phone10 = strlen($phone) === 12 && str_starts_with($phone, '91') ? substr($phone, 2) : $phone;
    $headers = [];
    $body = null;
    $method = 'GET';

    if ($provider === 'fast2sms') {
        if ($key === '') {
            return [false, null, 'SMS: Fast2SMS API key is not set'];
        }
        $base = rtrim((string) env('SMS_FAST2SMS_URL', 'https://www.fast2sms.com/dev/bulkV2'), '/');
        $url = $base . '?' . http_build_query(['route' => 'q', 'message' => $text, 'numbers' => $phone10, 'flash' => 0]);
        $headers[] = 'authorization: ' . $key;
        $success = '"return":true';
    } elseif ($provider === 'custom' && !empty($s['custom_url'])) {
        // Placeholders: {phone} 91XXXXXXXXXX, {phone10} XXXXXXXXXX, {message}, {sender}, {api_key}
        $vars = ['{phone}' => $phone, '{phone10}' => $phone10, '{message}' => $text, '{sender}' => $s['sender_id'] ?? '', '{api_key}' => $key];
        $url = strtr($s['custom_url'], array_map('rawurlencode', $vars));
        $method = ($s['custom_method'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
        foreach (preg_split('/\r?\n/', (string) ($s['custom_headers'] ?? '')) as $line) {
            if (strpos($line, ':') !== false) {
                $headers[] = trim(strtr($line, $vars));
            }
        }
        if ($method === 'POST' && trim((string) ($s['custom_body'] ?? '')) !== '') {
            $tpl = (string) $s['custom_body'];
            $json = str_starts_with(ltrim($tpl), '{');
            // JSON bodies get JSON-escaped values, form bodies url-encoded ones.
            $esc = $json ? array_map(fn($v) => substr(json_encode((string) $v, JSON_UNESCAPED_UNICODE), 1, -1), $vars) : array_map('rawurlencode', $vars);
            $body = strtr($tpl, $esc);
            $headers[] = $json ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
        }
        $success = (string) ($s['success_text'] ?? '');
    } else {
        return [false, null, 'SMS is not set up yet'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return [false, null, 'SMS: could not reach the gateway (' . $err . ')'];
    }
    $ok = $code >= 200 && $code < 300 && ($success === '' || stripos((string) $res, $success) !== false);
    if (!$ok) {
        $json = json_decode((string) $res, true);
        $msg = is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null;
        $msg = is_array($msg) ? implode(', ', $msg) : $msg;
        return [false, null, mb_substr('SMS: ' . ($msg ?: "gateway replied HTTP $code " . trim(strip_tags((string) $res))), 0, 250)];
    }
    $json = json_decode((string) $res, true);
    return [true, is_array($json) ? (string) ($json['request_id'] ?? $json['id'] ?? '') : null, null];
}

/* -------------------------------------------------------------------------------------- Campaign worker */

/** Text for one recipient, with the offer image link for channels that cannot attach images. */
function campaign_text(array $c, array $store, array $r, bool $withImageLink): string
{
    $text = wa_personalise((string) $c['message'], (string) ($r['name'] ?? ''), (string) $store['name']);
    return $withImageLink && !empty($c['image_url']) ? $text . "\n" . $c['image_url'] : $text;
}

function send_to_recipient(array $c, array $store, array $r): array
{
    switch ($r['channel']) {
        case 'email':
            return email_send($store, (string) $r['email'], wa_personalise((string) ($c['subject'] ?: $c['name']), (string) ($r['name'] ?? ''), (string) $store['name']), campaign_text($c, $store, $r, false), $c['image_url'] ?: null);
        case 'sms':
            return sms_send($store, (string) $r['phone'], campaign_text($c, $store, $r, true));
        default:
            return wa_send_template($store, (string) $r['phone'], [$r['name'] ?: 'Customer', campaign_text($c, $store, $r, empty($store['wa_template_image']))], $c['image_url'] ?: null);
    }
}

/**
 * Send the next pending messages of one campaign. WhatsApp rows of a "manual" campaign are left for the screen
 * (one tap per customer). Rows are claimed first, so the screen and the cron job never send the same message twice.
 * Returns the number of messages processed.
 */
function process_campaign(int $campaignId, int $limit = 20, ?float $deadline = null): int
{
    $c = db_one('SELECT * FROM campaigns WHERE campaign_id = ?', [$campaignId]);
    if (!$c || $c['status'] !== 'Sending') {
        return 0;
    }
    $store = get_store((int) $c['store_id']);
    $manualWa = $c['channel'] !== 'api';
    $token = bin2hex(random_bytes(8));
    db_query(
        "UPDATE campaign_recipients SET status = 'Sending', provider_message_id = ?, sent_at = NOW(), attempts = attempts + 1
         WHERE campaign_id = ? AND status = 'Pending'" . ($manualWa ? " AND channel <> 'whatsapp'" : '') . ' ORDER BY recipient_id LIMIT ' . max(1, (int) $limit),
        [$token, $campaignId]
    );
    $batch = db_all("SELECT * FROM campaign_recipients WHERE campaign_id = ? AND status = 'Sending' AND provider_message_id = ?", [$campaignId, $token]);
    $done = 0;
    foreach ($batch as $r) {
        if ($deadline !== null && microtime(true) > $deadline) {
            db_query("UPDATE campaign_recipients SET status = 'Pending', provider_message_id = NULL, sent_at = NULL WHERE recipient_id = ?", [$r['recipient_id']]);
            continue;
        }
        [$ok, $msgId, $err] = send_to_recipient($c, $store, $r);
        db_query(
            'UPDATE campaign_recipients SET status = ?, error = ?, provider_message_id = ?, sent_at = ' . ($ok ? 'NOW()' : 'NULL') . ' WHERE recipient_id = ?',
            [$ok ? 'Sent' : 'Failed', $err, $msgId, $r['recipient_id']]
        );
        $done++;
    }
    refresh_campaign($campaignId);
    return $done;
}

/** Messages claimed by a run that died (page closed, PHP timeout) go back to the queue after 10 minutes. */
function release_stuck_recipients(): int
{
    return db_query("UPDATE campaign_recipients SET status = 'Pending', provider_message_id = NULL, sent_at = NULL
                     WHERE status = 'Sending' AND (sent_at IS NULL OR sent_at < NOW() - INTERVAL 10 MINUTE)")->rowCount();
}

/** Public base URL of the API (for offer images that WhatsApp / email must download). */
function api_public_base(): string
{
    $env = rtrim((string) env('APP_URL', ''), '/');
    if ($env !== '') {
        return $env . '/api';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = preg_replace('#/master/[^/]+/[^/]+$#', '', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
