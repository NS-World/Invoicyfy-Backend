<?php
/**
 * Per-store JSON settings added in V3 (stores.pos_settings, loyalty_settings, automation_settings).
 * Every reader merges the saved values over defaults, so missing keys never break anything,
 * and every writer validates field by field (unknown keys are dropped).
 */

const POS_DEFAULTS = [
    'require_day_open' => false,  // block billing until the day (cash register) is opened
    'table_count' => 0,           // 0 = no table numbers; e.g. 12 shows table buttons 1..12
    'upi_qr_on_screen' => true,   // show a UPI QR with the bill amount when "UPI" is chosen
    // V4
    'auto_send_bill' => false,    // send the bill link to the customer (WhatsApp API / SMS / Email) when a bill is saved
    'bill_channels' => ['whatsapp'],
    'bill_message' => "Thank you for visiting {shop}! Your bill {bill_no} of Rs {amount} dated {date}: {link}",
    'online_platforms' => [['name' => 'Swiggy', 'commission' => 25.0], ['name' => 'Zomato', 'commission' => 25.0]],
    'expiry_alert_days' => 3,     // batches expiring within these days show on the dashboard and in the stock alert
];

const LOYALTY_DEFAULTS = [
    'enabled' => false,
    'earn_per_100' => 5.0,        // points earned for every ₹100 billed
    'point_value' => 0.5,         // ₹ value of one point when redeemed
    'min_redeem_points' => 50,
    'max_redeem_percent' => 50.0, // at most this % of a bill can be paid with points
    'min_bill_to_earn' => 0.0,
];

const AUTOMATION_DEFAULTS = [
    'owner_name' => '',
    'owner_phones' => '',         // comma separated, with or without 91
    'owner_emails' => '',
    'owner_channels' => ['email'],
    'summary' => ['enabled' => false, 'time' => '22:30'],
    'low_stock' => ['enabled' => false, 'time' => '10:00'],
    'birthday' => [
        'enabled' => false,
        'time' => '09:00',
        'channels' => ['whatsapp', 'sms', 'email'],
        'message' => "Happy Birthday {name}! 🎂 Wishing you a sweet year ahead. Enjoy a special treat at {shop} with code {coupon} (valid till {valid_till}).",
        'anniversary' => true,
        'anniversary_message' => "Happy Anniversary {name}! 💐 Celebrate with something sweet at {shop}. Use code {coupon} (valid till {valid_till}).",
        'coupon' => ['enabled' => true, 'discount_type' => 'percent', 'value' => 10.0, 'max_discount' => null, 'min_bill' => 0.0, 'valid_days' => 7],
    ],
    'reminders' => [
        'enabled' => false,
        'time' => '11:00',
        'channels' => ['whatsapp', 'sms', 'email'],
        'every_days' => 7,
        'min_days_due' => 7,
        'min_amount' => 1.0,
        'message' => "Hello {name}, a payment of ₹{due} is pending at {shop} for {bills} bill(s). Please pay at your convenience. Thank you!",
    ],
];

function settings_decode(?string $json): array
{
    $v = $json ? json_decode($json, true) : null;
    return is_array($v) ? $v : [];
}

/** Saved values over defaults, keeping only known keys (nested one level deep). */
function settings_merge(array $defaults, array $saved): array
{
    $out = $defaults;
    foreach ($defaults as $k => $def) {
        if (!array_key_exists($k, $saved)) {
            continue;
        }
        if (is_array($def) && $def && array_keys($def) !== range(0, count($def) - 1)) {
            $out[$k] = settings_merge($def, is_array($saved[$k]) ? $saved[$k] : []);
        } else {
            $out[$k] = $saved[$k];
        }
    }
    return $out;
}

function pos_settings(array $store): array
{
    return settings_merge(POS_DEFAULTS, settings_decode($store['pos_settings'] ?? null));
}

function loyalty_settings(array $store): array
{
    return settings_merge(LOYALTY_DEFAULTS, settings_decode($store['loyalty_settings'] ?? null));
}

function automation_settings(array $store): array
{
    return settings_merge(AUTOMATION_DEFAULTS, settings_decode($store['automation_settings'] ?? null));
}

/* ---------------------------------------------------------------- validation helpers */

function setting_num($v, float $min, float $max, string $label): float
{
    if (!is_numeric($v)) {
        fail("$label must be a number");
    }
    $n = (float) $v;
    if ($n < $min || $n > $max) {
        fail("$label must be between $min and $max");
    }
    return round($n, 2);
}

function setting_time($v, string $label): string
{
    if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
        fail("$label must be a time like 22:30");
    }
    return $v;
}

function setting_channels($v): array
{
    return array_values(array_unique(array_intersect(['whatsapp', 'sms', 'email'], (array) $v)));
}

function setting_text($v, int $max, string $label, bool $required = false): string
{
    $t = trim((string) $v);
    if ($required && $t === '') {
        fail("$label is required");
    }
    if (mb_strlen($t) > $max) {
        fail("$label is too long (max $max characters)");
    }
    return $t;
}

function clean_pos_settings(array $in, array $current): array
{
    $s = settings_merge($current, $in);
    return [
        'require_day_open' => (bool) $s['require_day_open'],
        'table_count' => (int) setting_num($s['table_count'], 0, 200, 'Number of tables'),
        'upi_qr_on_screen' => (bool) $s['upi_qr_on_screen'],
        'auto_send_bill' => (bool) $s['auto_send_bill'],
        'bill_channels' => setting_channels($s['bill_channels']),
        'bill_message' => setting_text($s['bill_message'], 500, 'Bill message', true),
        'online_platforms' => clean_online_platforms($s['online_platforms']),
        'expiry_alert_days' => (int) setting_num($s['expiry_alert_days'], 0, 60, 'Expiry alert days'),
    ];
}

/** [{name, commission %}] — up to 8 platforms, unique names. */
function clean_online_platforms($raw): array
{
    $out = [];
    $seen = [];
    foreach (is_array($raw) ? $raw : [] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $name = setting_text($p['name'] ?? '', 30, 'Platform name');
        if ($name === '') {
            continue;
        }
        if (!preg_match('/^[\p{L}\p{N} .&_-]+$/u', $name)) {
            fail("Platform name \"$name\" can have letters, numbers, space . & _ - only");
        }
        $key = mb_strtolower($name);
        if (isset($seen[$key])) {
            fail("Platform \"$name\" is added twice");
        }
        $seen[$key] = true;
        $out[] = ['name' => $name, 'commission' => setting_num($p['commission'] ?? 0, 0, 60, "Commission % for $name")];
    }
    if (count($out) > 8) {
        fail('Add at most 8 online platforms');
    }
    return $out;
}

function clean_loyalty_settings(array $in, array $current): array
{
    $s = settings_merge($current, $in);
    return [
        'enabled' => (bool) $s['enabled'],
        'earn_per_100' => setting_num($s['earn_per_100'], 0, 100, 'Points per ₹100'),
        'point_value' => setting_num($s['point_value'], 0.01, 100, 'Value of one point'),
        'min_redeem_points' => (int) setting_num($s['min_redeem_points'], 0, 100000, 'Minimum points to redeem'),
        'max_redeem_percent' => setting_num($s['max_redeem_percent'], 1, 100, 'Maximum % of bill paid by points'),
        'min_bill_to_earn' => setting_num($s['min_bill_to_earn'], 0, 1000000, 'Minimum bill to earn points'),
    ];
}

/** Comma / space separated phone numbers -> "919825012345,9198..." (country code added for 10 digits). */
function clean_phone_list(string $raw): array
{
    $out = [];
    // Numbers are separated by comma / semicolon / new line; spaces inside a number are fine (98250 12345).
    foreach (preg_split('/[,;\n]+/', $raw) as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $d = preg_replace('/\D+/', '', $p);
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $d = '91' . $d;
        }
        if (strlen($d) < 11 || strlen($d) > 15) {
            fail("Phone number \"$p\" looks wrong. Use 10 digits or country code + number.");
        }
        $out[] = $d;
    }
    return array_values(array_unique($out));
}

function clean_email_list(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) as $e) {
        if ($e === '') {
            continue;
        }
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            fail("Email \"$e\" is not valid");
        }
        $out[] = strtolower($e);
    }
    return array_values(array_unique($out));
}

function clean_automation_settings(array $in, array $current): array
{
    $s = settings_merge($current, $in);
    $b = $s['birthday'];
    $c = $b['coupon'];
    $r = $s['reminders'];
    if (!in_array($c['discount_type'], ['percent', 'flat'], true)) {
        fail('Birthday coupon type must be percent or flat');
    }
    $out = [
        'owner_name' => setting_text($s['owner_name'], 100, 'Owner name'),
        'owner_phones' => implode(',', clean_phone_list((string) $s['owner_phones'])),
        'owner_emails' => implode(',', clean_email_list((string) $s['owner_emails'])),
        'owner_channels' => setting_channels($s['owner_channels']),
        'summary' => ['enabled' => (bool) $s['summary']['enabled'], 'time' => setting_time($s['summary']['time'], 'Summary time')],
        'low_stock' => ['enabled' => (bool) $s['low_stock']['enabled'], 'time' => setting_time($s['low_stock']['time'], 'Low stock alert time')],
        'birthday' => [
            'enabled' => (bool) $b['enabled'],
            'time' => setting_time($b['time'], 'Birthday wish time'),
            'channels' => setting_channels($b['channels']),
            'message' => setting_text($b['message'], 1000, 'Birthday message', true),
            'anniversary' => (bool) $b['anniversary'],
            'anniversary_message' => setting_text($b['anniversary_message'], 1000, 'Anniversary message', true),
            'coupon' => [
                'enabled' => (bool) $c['enabled'],
                'discount_type' => $c['discount_type'],
                'value' => setting_num($c['value'], 0.01, $c['discount_type'] === 'percent' ? 100 : 100000, 'Birthday coupon value'),
                'max_discount' => $c['max_discount'] === null || $c['max_discount'] === '' ? null : setting_num($c['max_discount'], 0, 100000, 'Maximum discount'),
                'min_bill' => setting_num($c['min_bill'], 0, 1000000, 'Minimum bill'),
                'valid_days' => (int) setting_num($c['valid_days'], 1, 365, 'Coupon valid days'),
            ],
        ],
        'reminders' => [
            'enabled' => (bool) $r['enabled'],
            'time' => setting_time($r['time'], 'Reminder time'),
            'channels' => setting_channels($r['channels']),
            'every_days' => (int) setting_num($r['every_days'], 1, 90, 'Remind every (days)'),
            'min_days_due' => (int) setting_num($r['min_days_due'], 0, 365, 'Remind after (days)'),
            'min_amount' => setting_num($r['min_amount'], 0, 10000000, 'Minimum due amount'),
            'message' => setting_text($r['message'], 1000, 'Reminder message', true),
        ],
    ];
    if (($out['summary']['enabled'] || $out['low_stock']['enabled']) && !$out['owner_phones'] && !$out['owner_emails']) {
        fail('Add the owner mobile number or email to receive the summary / alerts');
    }
    if (($out['summary']['enabled'] || $out['low_stock']['enabled']) && !$out['owner_channels']) {
        fail('Choose how the owner gets the summary: WhatsApp, SMS or Email');
    }
    return $out;
}
