<?php
/**
 * Automatic messages: nightly owner summary, low-stock alert, birthday / anniversary wishes (with a personal coupon)
 * and payment reminders for dues. They are sent as campaigns, so they use the same WhatsApp API / SMS / Email setup,
 * show up under Offers > Sent with delivery status, and the cron worker retries what is pending.
 *
 * Jobs run from cron/process-campaigns.php (every minute) and, as a fallback, from jobs/tick.php which the open app
 * calls every few minutes. job_runs (job, store, date) makes sure each job runs once per day.
 */
require_once __DIR__ . '/channels.php';

const AUTOMATION_JOBS = ['summary', 'low_stock', 'birthday', 'reminders'];

/** Channels from $wanted that can send without a person (WhatsApp needs the Business API). */
function automatic_channels(array $store, array $wanted): array
{
    $cs = channel_settings_public($store);
    $ready = ['whatsapp' => $cs['whatsapp']['api_ready'], 'sms' => $cs['sms']['ready'], 'email' => $cs['email']['ready']];
    return array_values(array_filter($wanted, fn($c) => !empty($ready[$c])));
}

/**
 * Recipients for people: [['customer_id','name','phone','email','vars' => []], ...] x channels.
 * One message per person per channel; people without a phone / email for a channel are skipped.
 */
function build_recipients(array $people, array $channels): array
{
    $out = [];
    $seen = [];
    foreach ($people as $p) {
        foreach ($channels as $ch) {
            $phone = $ch === 'email' ? null : wa_phone($p['phone'] ?? null);
            $email = $ch === 'email' ? (filter_var(trim((string) ($p['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null) : null;
            $key = $ch . '|' . ($phone ?? $email);
            if ((!$phone && !$email) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['customer_id' => $p['customer_id'] ?? null, 'name' => $p['name'] ?? null, 'channel' => $ch, 'phone' => $phone, 'email' => $email,
                      'vars' => $p['vars'] ?? null];
        }
    }
    return $out;
}

/** Save a campaign with its recipients and send the first batch right away. Returns [campaign_id, recipients]. */
function create_system_campaign(array $store, string $kind, string $name, string $message, ?string $subject, array $recipients, ?int $userId): array
{
    global $pdo;
    if (!$recipients) {
        return [null, 0];
    }
    $channels = array_values(array_unique(array_column($recipients, 'channel')));
    $pdo->beginTransaction();
    $id = insert_row('campaigns', [
        'store_id' => (int) $store['store_id'],
        'user_id' => $userId,
        'name' => mb_substr($name, 0, 150),
        'kind' => $kind,
        'message' => $message,
        'subject' => $subject ? mb_substr($subject, 0, 200) : null,
        'channel' => 'api',
        'channels' => implode(',', $channels),
        'total' => count($recipients),
        'status' => 'Sending',
    ]);
    $stmt = $pdo->prepare('INSERT INTO campaign_recipients (campaign_id, customer_id, name, channel, phone, email, vars) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($recipients as $r) {
        $stmt->execute([$id, $r['customer_id'], $r['name'], $r['channel'], $r['phone'], $r['email'], $r['vars'] ? json_encode($r['vars'], JSON_UNESCAPED_UNICODE) : null]);
    }
    $pdo->commit();
    process_campaign($id, 30, microtime(true) + 20);
    return [$id, count($recipients)];
}

/** Owner contacts as recipients for the summary / alerts. */
function owner_recipients(array $store, array $auto): array
{
    $people = [];
    $name = $auto['owner_name'] ?: 'Owner';
    foreach (array_filter(explode(',', $auto['owner_phones'])) as $p) {
        $people[] = ['name' => $name, 'phone' => $p];
    }
    foreach (array_filter(explode(',', $auto['owner_emails'])) as $e) {
        $people[] = ['name' => $name, 'email' => $e];
    }
    return build_recipients($people, automatic_channels($store, $auto['owner_channels']));
}

function fmt_inr($v): string
{
    return '₹' . number_format((float) $v, 0);
}

/* ---------------------------------------------------------------- texts */

function daily_summary_text(array $store, string $date): string
{
    $sid = (int) $store['store_id'];
    $s = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(balance_due),0) AS credit, COALESCE(SUM(discount_amount),0) AS discount
         FROM invoices WHERE store_id = ? AND invoice_date = ? AND status <> 'Cancelled'", [$sid, $date]);
    $cancel = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total FROM invoices WHERE store_id = ? AND cancelled_at >= ? AND cancelled_at < ? AND status = 'Cancelled'", [$sid, "$date 00:00:00", date('Y-m-d', strtotime("$date +1 day")) . ' 00:00:00']);
    $pays = db_all('SELECT payment_method, SUM(amount) AS amount FROM payments WHERE store_id = ? AND payment_date = ? GROUP BY payment_method ORDER BY amount DESC', [$sid, $date]);
    $exp = (float) db_value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE store_id = ? AND expense_date = ?', [$sid, $date]);
    $top = db_all(
        "SELECT ii.product_name, SUM(ii.line_total) AS amount FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
         WHERE i.store_id = ? AND i.invoice_date = ? AND i.status <> 'Cancelled' GROUP BY ii.product_name ORDER BY amount DESC LIMIT 5", [$sid, $date]);
    $low = (int) db_value('SELECT COUNT(*) FROM products WHERE (store_id = ? OR store_id IS NULL) AND status = \'Active\' AND track_inventory = 1 AND stock_qty <= low_stock_threshold', [$sid]);
    $lastWeek = (float) db_value("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE store_id = ? AND invoice_date = ? AND status <> 'Cancelled'", [$sid, date('Y-m-d', strtotime("$date -7 days"))]);

    $lines = ["📊 {$store['name']} — " . date('d M Y', strtotime($date))];
    $trend = $lastWeek > 0 ? sprintf(' (%+d%% vs last week)', round(((float) $s['total'] - $lastWeek) / $lastWeek * 100)) : '';
    $lines[] = 'Sales: ' . fmt_inr($s['total']) . " from {$s['n']} bills$trend";
    if ($pays) {
        $lines[] = 'Received: ' . implode(', ', array_map(fn($p) => "{$p['payment_method']} " . fmt_inr($p['amount']), $pays));
    }
    if ((float) $s['credit'] > 0) {
        $lines[] = 'Credit (udhaar) given: ' . fmt_inr($s['credit']);
    }
    if ((float) $s['discount'] > 0) {
        $lines[] = 'Discounts: ' . fmt_inr($s['discount']);
    }
    if ($exp > 0) {
        $lines[] = 'Expenses: ' . fmt_inr($exp);
    }
    if ((int) $cancel['n'] > 0) {
        $lines[] = "Cancelled: {$cancel['n']} bill(s) " . fmt_inr($cancel['total']);
    }
    if ($top) {
        $lines[] = 'Top items: ' . implode(', ', array_map(fn($t) => $t['product_name'], $top));
    }
    $open = open_cash_session($sid);
    if ($open) {
        $lines[] = '⚠️ Day is not closed yet (opened ' . date('d M H:i', strtotime($open['opened_at'])) . ')';
    }
    if ($low > 0) {
        $lines[] = "⚠️ $low item(s) low on stock";
    }
    return implode("\n", $lines);
}

function low_stock_text(array $store): ?string
{
    $rows = db_all(
        "SELECT name, stock_qty, low_stock_threshold, unit FROM products
         WHERE (store_id = ? OR store_id IS NULL) AND status = 'Active' AND track_inventory = 1 AND stock_qty <= low_stock_threshold
         ORDER BY (stock_qty - low_stock_threshold), name LIMIT 30",
        [(int) $store['store_id']]
    );
    if (!$rows) {
        return null;
    }
    $num = fn($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $lines = ["⚠️ Low stock at {$store['name']} (" . count($rows) . ' items)'];
    foreach ($rows as $r) {
        $lines[] = "• {$r['name']}: " . $num($r['stock_qty']) . " {$r['unit']} left (min " . $num($r['low_stock_threshold']) . ')';
    }
    return implode("\n", $lines);
}

/* ---------------------------------------------------------------- jobs */

function job_summary(array $store, array $auto, string $date, ?int $userId = null): string
{
    $to = owner_recipients($store, $auto);
    if (!$to) {
        return 'No owner number / email with a ready channel';
    }
    $text = daily_summary_text($store, $date);
    [, $n] = create_system_campaign($store, 'summary', 'Daily summary ' . date('d M', strtotime($date)), $text, "Daily summary — {$store['name']}", $to, $userId);
    return "Summary sent to $n contact(s)";
}

function job_low_stock(array $store, array $auto, ?int $userId = null): string
{
    $text = low_stock_text($store);
    // V4: batches about to expire go in the same alert.
    $expiring = expiry_text($store, (int) pos_settings($store)['expiry_alert_days']);
    if ($expiring) {
        $text = $text ? "$text\n\n$expiring" : $expiring;
    }
    if (!$text) {
        return 'No item is low on stock';
    }
    $to = owner_recipients($store, $auto);
    if (!$to) {
        return 'No owner number / email with a ready channel';
    }
    [, $n] = create_system_campaign($store, 'stock', 'Low stock alert ' . date('d M'), $text, "Low stock — {$store['name']}", $to, $userId);
    return "Low stock alert sent to $n contact(s)";
}

/** Unique personal coupon for a wish, e.g. BDAY-K7Q2M9. */
function create_wish_coupon(array $store, array $cfg, int $customerId, string $source, int $validDays): array
{
    $prefix = $source === 'anniversary' ? 'ANNI' : 'BDAY';
    for ($i = 0; $i < 5; $i++) {
        $code = $prefix . '-' . strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(6))), 0, 6));
        if (!db_value('SELECT 1 FROM coupons WHERE store_id = ? AND code = ?', [$store['store_id'], $code])) {
            break;
        }
    }
    $till = date('Y-m-d', strtotime('+' . max(1, $validDays - 1) . ' days'));
    insert_row('coupons', [
        'store_id' => (int) $store['store_id'],
        'code' => $code,
        'description' => ucfirst($source) . ' wish',
        'discount_type' => $cfg['discount_type'],
        'value' => $cfg['value'],
        'max_discount' => $cfg['max_discount'],
        'min_bill' => $cfg['min_bill'],
        'valid_from' => date('Y-m-d'),
        'valid_to' => $till,
        'usage_limit' => 1,
        'per_customer_limit' => 1,
        'customer_id' => $customerId,
        'source' => $source,
    ]);
    return [$code, $till];
}

function job_birthday(array $store, array $auto, string $date, ?int $userId = null): string
{
    $cfg = $auto['birthday'];
    $channels = automatic_channels($store, $cfg['channels']);
    if (!$channels) {
        return 'No ready channel (set up WhatsApp API, SMS or Email)';
    }
    $md = date('m-d', strtotime($date));
    // People born on 29 Feb are wished on 28 Feb in other years.
    $mds = ($md === '02-28' && !date('L', strtotime($date))) ? ['02-28', '02-29'] : [$md];
    $in = implode(',', array_fill(0, count($mds), '?'));
    $results = [];
    foreach (['birthday' => $cfg['message'], 'anniversary' => $cfg['anniversary'] ? $cfg['anniversary_message'] : null] as $field => $message) {
        if (!$message) {
            continue;
        }
        $people = db_all("SELECT customer_id, name, phone, email FROM customers WHERE marketing_opt_in = 1" . customer_active_sql() . " AND $field IS NOT NULL AND DATE_FORMAT($field, '%m-%d') IN ($in)", $mds);
        if (!$people) {
            continue;
        }
        foreach ($people as &$p) {
            $vars = ['coupon' => '', 'valid_till' => ''];
            if ($cfg['coupon']['enabled']) {
                [$code, $till] = create_wish_coupon($store, $cfg['coupon'], (int) $p['customer_id'], $field, (int) $cfg['coupon']['valid_days']);
                $vars = ['coupon' => $code, 'valid_till' => date('d M', strtotime($till))];
            }
            $p['vars'] = $vars;
        }
        unset($p);
        $text = $cfg['coupon']['enabled'] ? $message : preg_replace('/\s*[^.!?]*\{coupon\}[^.!?]*[.!?]?/u', '', $message);
        [, $n] = create_system_campaign($store, $field, ucfirst($field) . ' wishes ' . date('d M', strtotime($date)), $text,
            ($field === 'birthday' ? 'Happy Birthday from ' : 'Happy Anniversary from ') . $store['name'], build_recipients($people, $channels), $userId);
        $results[] = count($people) . " $field wish(es), $n message(s)";
    }
    return $results ? implode('; ', $results) : 'No birthdays or anniversaries today';
}

/** Customers with dues for reminders: [customer row + due, bills, oldest]. */
function customers_with_dues(int $storeId, float $minAmount, int $minDays, ?array $onlyIds = null): array
{
    $params = [$storeId, date('Y-m-d', strtotime("-$minDays days"))];
    $only = '';
    if ($onlyIds !== null) {
        if (!$onlyIds) {
            return [];
        }
        $only = ' AND c.customer_id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
        $params = array_merge($params, array_map('intval', $onlyIds));
    }
    $params[] = $minAmount;
    return db_all(
        "SELECT c.customer_id, c.name, c.phone, c.email, c.last_reminder_at, SUM(i.balance_due) AS due, COUNT(*) AS bills, MIN(i.invoice_date) AS oldest
         FROM invoices i JOIN customers c ON c.customer_id = i.customer_id
         WHERE i.status IN ('Unpaid','Partial') AND i.store_id = ? AND i.balance_due > 0 AND i.invoice_date <= ?$only" . customer_active_sql('c') . "
         GROUP BY c.customer_id, c.name, c.phone, c.email, c.last_reminder_at HAVING due >= ? ORDER BY due DESC",
        $params
    );
}

/** Build and send reminder messages. Returns [campaign_id, people, messages]. */
function send_due_reminders(array $store, array $dues, array $channels, string $message, ?int $userId): array
{
    $people = array_map(fn($d) => [
        'customer_id' => (int) $d['customer_id'], 'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'],
        'vars' => ['due' => number_format((float) $d['due'], 2), 'bills' => (int) $d['bills'], 'oldest' => date('d M Y', strtotime($d['oldest']))],
    ], $dues);
    $recipients = build_recipients($people, $channels);
    if (!$recipients) {
        return [null, 0, 0];
    }
    $ids = array_values(array_unique(array_filter(array_column($recipients, 'customer_id'))));
    [$cid, $n] = create_system_campaign($store, 'reminder', 'Payment reminders ' . date('d M'), $message, "Payment reminder — {$store['name']}", $recipients, $userId);
    if ($ids) {
        db_query('UPDATE customers SET last_reminder_at = NOW() WHERE customer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }
    return [$cid, count($ids), $n];
}

function job_reminders(array $store, array $auto, ?int $userId = null): string
{
    $cfg = $auto['reminders'];
    $channels = automatic_channels($store, $cfg['channels']);
    if (!$channels) {
        return 'No ready channel (set up WhatsApp API, SMS or Email)';
    }
    $cut = strtotime('-' . (int) $cfg['every_days'] . ' days');
    $dues = array_values(array_filter(
        customers_with_dues((int) $store['store_id'], (float) $cfg['min_amount'], (int) $cfg['min_days_due']),
        fn($d) => !$d['last_reminder_at'] || strtotime($d['last_reminder_at']) <= $cut
    ));
    if (!$dues) {
        return 'Nobody needs a reminder today';
    }
    [, $people, $n] = send_due_reminders($store, $dues, $channels, $cfg['message'], $userId);
    return "Reminders: $people customer(s), $n message(s)";
}

function run_job(array $store, string $job, string $date, ?int $userId = null): string
{
    $auto = automation_settings($store);
    switch ($job) {
        case 'summary':
            return job_summary($store, $auto, $date, $userId);
        case 'low_stock':
            return job_low_stock($store, $auto, $userId);
        case 'birthday':
            return job_birthday($store, $auto, $date, $userId);
        case 'reminders':
            return job_reminders($store, $auto, $userId);
    }
    fail('Unknown job');
    return '';
}

/** Claim today's run of a job for a store. False when it already ran (or another worker is running it). */
function claim_job(string $job, int $storeId, string $date): bool
{
    return db_query("INSERT IGNORE INTO job_runs (job, store_id, run_date, ran_at, result) VALUES (?, ?, ?, NOW(), 'running')", [$job, $storeId, $date])->rowCount() > 0;
}

/** Run every job whose time has come today. Returns log lines. */
function run_scheduled_jobs(float $deadline): array
{
    $log = [];
    $date = date('Y-m-d');
    $now = date('H:i');
    foreach (db_all('SELECT * FROM stores WHERE automation_settings IS NOT NULL') as $store) {
        $auto = automation_settings($store);
        foreach (AUTOMATION_JOBS as $job) {
            if (microtime(true) > $deadline) {
                return $log;
            }
            if (empty($auto[$job]['enabled']) || $now < $auto[$job]['time'] || !claim_job($job, (int) $store['store_id'], $date)) {
                continue;
            }
            try {
                $result = run_job($store, $job, $date);
            } catch (Throwable $e) {
                $result = 'Error: ' . $e->getMessage();
                error_log("[Invoicyfy job $job] " . $e->getMessage());
            }
            db_query('UPDATE job_runs SET result = ?, ran_at = NOW() WHERE job = ? AND store_id = ? AND run_date = ?', [mb_substr($result, 0, 500), $job, $store['store_id'], $date]);
            $log[] = "{$store['name']} $job: $result";
        }
    }
    return $log;
}
