<?php
/**
 * Khanak SaaS control system: plans, sign-ups, payments (Razorpay), new shop set-up, renewals, Khanak's own GST
 * invoices and the welcome email with the login details.
 *
 * Every shop gets its own database (khanak_t_<shop>), made from the same schema as a single-shop install, so the
 * billing app itself is unchanged. The control database (SAAS_DB_NAME) only holds the list of shops and payments.
 *
 * Payment modes (SAAS_PAYMENT_MODE): "razorpay" (live or test keys) or "mock" (no gateway; for local testing and
 * demos — the checkout screen shows a test payment button).
 */
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/migrator.php';

use PHPMailer\PHPMailer\PHPMailer;

/* ------------------------------------------------------------------ control database */

/** Creates the control tables and the starting plans. Safe to run again. */
function saas_install(PDO $pdo): array
{
    $log = [];
    $tables = [
        'saas_plans' => "code VARCHAR(20) PRIMARY KEY, name VARCHAR(60) NOT NULL, tagline VARCHAR(120) NULL,
            price_year INT NOT NULL DEFAULT 0, price_month INT NOT NULL DEFAULT 0, per_store TINYINT(1) NOT NULL DEFAULT 0,
            max_users INT NOT NULL DEFAULT 0, max_stores INT NOT NULL DEFAULT 0, features TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        'saas_tenants' => "tenant_id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(30) NOT NULL UNIQUE, db_name VARCHAR(64) NOT NULL UNIQUE,
            business_name VARCHAR(150) NOT NULL, owner_name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(20) NOT NULL,
            city VARCHAR(80) NULL, gstin VARCHAR(15) NULL, plan_code VARCHAR(20) NOT NULL, billing_cycle VARCHAR(10) NOT NULL DEFAULT 'year',
            store_quota INT NOT NULL DEFAULT 1, status VARCHAR(12) NOT NULL DEFAULT 'trial', trial_ends_at DATE NULL, current_period_end DATE NULL,
            notes VARCHAR(500) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_saas_tenants_status (status, current_period_end), INDEX idx_saas_tenants_email (email)",
        'saas_orders' => "order_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'signup',
            plan_code VARCHAR(20) NOT NULL, billing_cycle VARCHAR(10) NOT NULL, quantity INT NOT NULL DEFAULT 1,
            amount INT NOT NULL, gst INT NOT NULL, total INT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'INR',
            gateway VARCHAR(12) NOT NULL, gateway_order_id VARCHAR(64) NOT NULL UNIQUE, gateway_payment_id VARCHAR(64) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'created', signup TEXT NULL, credentials TEXT NULL, error VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL,
            INDEX idx_saas_orders_tenant (tenant_id, created_at), INDEX idx_saas_orders_status (status, created_at)",
        'saas_invoices' => "invoice_id INT AUTO_INCREMENT PRIMARY KEY, invoice_no VARCHAR(30) NOT NULL UNIQUE, tenant_id INT NOT NULL,
            order_id INT NOT NULL UNIQUE, amount INT NOT NULL, gst INT NOT NULL, total INT NOT NULL, details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_saas_invoices_tenant (tenant_id, created_at)",
        'saas_counters' => "name VARCHAR(40) PRIMARY KEY, value INT NOT NULL",
        'saas_admins' => "admin_id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
            password VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME NULL",
        'saas_db_pool' => "db_name VARCHAR(64) PRIMARY KEY, tenant_id INT NULL, assigned_at DATETIME NULL",
        'saas_events' => "event_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NULL, type VARCHAR(40) NOT NULL, message VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_saas_events_tenant (tenant_id, event_id), INDEX idx_saas_events_type (type, event_id)",
    ];
    foreach ($tables as $name => $cols) {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetch();
        if (!$exists) {
            $pdo->exec("CREATE TABLE `$name` ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $log[] = "+ created table $name";
        }
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM saas_plans')->fetchColumn()) {
        $st = $pdo->prepare('INSERT INTO saas_plans (code, name, tagline, price_year, price_month, per_store, max_users, max_stores, features, sort) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach (SAAS_DEFAULT_PLANS as $p) {
            $st->execute([$p['code'], $p['name'], $p['tagline'], $p['price_year'], $p['price_month'], $p['per_store'], $p['max_users'], $p['max_stores'], json_encode($p['features']), $p['sort']]);
        }
        $log[] = '+ added plans: ' . implode(', ', array_column(SAAS_DEFAULT_PLANS, 'name'));
    }
    // Pre-made empty databases (shared hosting where the app cannot create databases itself).
    foreach (array_filter(array_map('trim', explode(',', (string) env('SAAS_DB_POOL', '')))) as $db) {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
            $pdo->prepare('INSERT IGNORE INTO saas_db_pool (db_name) VALUES (?)')->execute([$db]);
        }
    }
    return $log;
}

function saas_q(string $sql, array $params = []): PDOStatement
{
    $st = saas_control_pdo()->prepare($sql);
    $st->execute($params);
    return $st;
}

function saas_event(?int $tenantId, string $type, string $message): void
{
    try {
        saas_q('INSERT INTO saas_events (tenant_id, type, message) VALUES (?, ?, ?)', [$tenantId, $type, mb_substr($message, 0, 500)]);
    } catch (Throwable $e) {
        error_log('[Khanak saas event] ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ plans and prices */

function saas_plan_row(array $p): array
{
    return [
        'code' => $p['code'], 'name' => $p['name'], 'tagline' => $p['tagline'],
        'price_year' => (int) $p['price_year'], 'price_month' => (int) $p['price_month'], 'per_store' => (bool) $p['per_store'],
        'max_users' => (int) $p['max_users'], 'max_stores' => (int) $p['max_stores'],
        'features' => array_values(array_unique(array_merge(SAAS_BASE_FEATURES, json_decode((string) $p['features'], true) ?: []))),
        'is_active' => (bool) $p['is_active'], 'sort' => (int) $p['sort'],
    ];
}

function saas_plans(bool $activeOnly = true): array
{
    $rows = saas_q('SELECT * FROM saas_plans' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort, code')->fetchAll();
    return array_map('saas_plan_row', $rows);
}

function saas_plan(string $code): ?array
{
    $p = saas_q('SELECT * FROM saas_plans WHERE code = ?', [$code])->fetch();
    return $p ? saas_plan_row($p) : null;
}

function saas_gst_rate(): float
{
    return (float) env('SAAS_GST_RATE', 18);
}

/** [amount, gst, total] in rupees for a plan, billing cycle and number of stores. */
function saas_price(array $plan, string $cycle, int $quantity = 1): array
{
    $unit = $cycle === 'month' ? $plan['price_month'] : $plan['price_year'];
    if ($unit <= 0) {
        throw new ApiException(($plan['name'] ?? 'This plan') . ' has no ' . ($cycle === 'month' ? 'monthly' : 'yearly') . ' price. Choose the other billing period.');
    }
    $amount = $unit * ($plan['per_store'] ? max(1, $quantity) : 1);
    $gst = (int) round($amount * saas_gst_rate() / 100);
    return [$amount, $gst, $amount + $gst];
}

/* ------------------------------------------------------------------ sign-up details */

function saas_slugify(string $name): string
{
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name));
    $s = trim(substr(trim($s, '-'), 0, 24), '-');
    return strlen($s) >= 3 ? $s : 'shop-' . substr(bin2hex(random_bytes(3)), 0, 5);
}

const SAAS_RESERVED_SLUGS = ['api', 'admin', 'khanak', 'www', 'app', 'saas', 'support', 'help', 'login', 'pricing', 'signup', 'demo', 'test', 'mail'];

function saas_slug_free(string $slug): bool
{
    if (!saas_valid_slug($slug) || in_array($slug, SAAS_RESERVED_SLUGS, true)) {
        return false;
    }
    if (saas_q('SELECT 1 FROM saas_tenants WHERE slug = ?', [$slug])->fetch()) {
        return false;
    }
    // Held by an unpaid sign-up of the last hour (someone is paying for it right now).
    foreach (saas_q("SELECT signup FROM saas_orders WHERE kind = 'signup' AND status IN ('created', 'processing') AND created_at > NOW() - INTERVAL 1 HOUR")->fetchAll() as $o) {
        if ((json_decode((string) $o['signup'], true)['slug'] ?? null) === $slug) {
            return false;
        }
    }
    return true;
}

function saas_suggest_slug(string $name): string
{
    $base = saas_slugify($name);
    if (saas_slug_free($base)) {
        return $base;
    }
    for ($i = 2; $i < 50; $i++) {
        $s = substr($base, 0, 26) . "-$i";
        if (saas_slug_free($s)) {
            return $s;
        }
    }
    return $base . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
}

/** Validated sign-up form. */
function saas_signup_details(array $d): array
{
    $name = trim((string) ($d['business_name'] ?? ''));
    $owner = trim((string) ($d['owner_name'] ?? ''));
    $email = strtolower(trim((string) ($d['email'] ?? '')));
    $phone = preg_replace('/[^0-9+]/', '', (string) ($d['phone'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        throw new ApiException('Enter your shop / business name');
    }
    if ($owner === '' || mb_strlen($owner) > 120) {
        throw new ApiException("Enter the owner's name");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Enter a valid email address — the login details are sent there');
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 10 || strlen($digits) > 13) {
        throw new ApiException('Enter a valid mobile number');
    }
    $gstin = strtoupper(trim((string) ($d['gstin'] ?? '')));
    if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z0-9]{10}[0-9A-Z]{3}$/', $gstin)) {
        throw new ApiException('GSTIN looks wrong (15 characters, e.g. 24ABCDE1234F1Z5) — or leave it empty');
    }
    $slug = strtolower(trim((string) ($d['shop_code'] ?? '')));
    if ($slug === '') {
        $slug = saas_suggest_slug($name);
    } elseif (!saas_valid_slug($slug)) {
        throw new ApiException('Shop code: 3–30 small letters, numbers or "-" (e.g. scoopiez-surat)');
    } elseif (!saas_slug_free($slug)) {
        throw new ApiException("Shop code \"$slug\" is already taken. Try another.", 409, ['code' => 'shop_code_taken']);
    }
    return [
        'business_name' => $name, 'owner_name' => $owner, 'email' => $email, 'phone' => $phone,
        'city' => mb_substr(trim((string) ($d['city'] ?? '')), 0, 80) ?: null, 'gstin' => $gstin ?: null, 'slug' => $slug,
    ];
}

/* ------------------------------------------------------------------ payments */

function saas_payment_mode(): string
{
    return env('SAAS_PAYMENT_MODE', 'mock') === 'razorpay' ? 'razorpay' : 'mock';
}

/** Creates the gateway order; returns its id. */
function saas_gateway_order(int $totalRupees, string $receipt, array $notes): string
{
    if (saas_payment_mode() === 'mock') {
        return 'order_mock_' . bin2hex(random_bytes(7));
    }
    $key = (string) env('SAAS_RAZORPAY_KEY_ID', '');
    $secret = (string) env('SAAS_RAZORPAY_KEY_SECRET', '');
    if ($key === '' || $secret === '') {
        throw new ApiException('Online payment is not set up yet. Please contact Khanak support.', 503);
    }
    $ch = curl_init(rtrim((string) env('SAAS_RAZORPAY_API', 'https://api.razorpay.com/v1'), '/') . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERPWD => "$key:$secret",
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['amount' => $totalRupees * 100, 'currency' => 'INR', 'receipt' => $receipt, 'notes' => $notes]),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $res = json_decode((string) $raw, true);
    if ($code !== 200 || empty($res['id'])) {
        error_log('[Khanak razorpay] order failed: ' . $code . ' ' . substr((string) $raw, 0, 300));
        throw new ApiException('Could not start the payment. Please try again in a minute.', 502);
    }
    return (string) $res['id'];
}

/** Razorpay checkout signature: HMAC-SHA256 of "order_id|payment_id" with the key secret. */
function saas_payment_signature(string $orderId, string $paymentId): string
{
    $secret = saas_payment_mode() === 'mock' ? saas_secret('mock-gateway', 'control') : (string) env('SAAS_RAZORPAY_KEY_SECRET', '');
    return hash_hmac('sha256', "$orderId|$paymentId", $secret);
}

function saas_verify_payment_signature(string $orderId, string $paymentId, string $signature): bool
{
    return $orderId !== '' && $paymentId !== '' && $signature !== '' && hash_equals(saas_payment_signature($orderId, $paymentId), $signature);
}

/** New order for a sign-up (tenant null) or a renewal / upgrade of a shop. */
function saas_create_order(string $kind, array $plan, string $cycle, int $quantity, ?array $tenant, ?array $signup): array
{
    if (!in_array($cycle, ['year', 'month'], true)) {
        throw new ApiException('Choose yearly or monthly billing');
    }
    $quantity = $plan['per_store'] ? max(1, min(500, $quantity)) : 1;
    [$amount, $gst, $total] = saas_price($plan, $cycle, $quantity);
    $receipt = ($tenant ? "t{$tenant['tenant_id']}" : 'new') . '-' . date('ymdHis');
    $gwId = saas_gateway_order($total, $receipt, ['plan' => $plan['code'], 'shop' => $tenant['slug'] ?? ($signup['slug'] ?? '')]);
    saas_q('INSERT INTO saas_orders (tenant_id, kind, plan_code, billing_cycle, quantity, amount, gst, total, gateway, gateway_order_id, signup)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$tenant['tenant_id'] ?? null, $kind, $plan['code'], $cycle, $quantity, $amount, $gst, $total, saas_payment_mode(), $gwId,
         $signup ? json_encode($signup, JSON_UNESCAPED_UNICODE) : null]);
    return [
        'order_id' => $gwId, 'amount' => $amount, 'gst' => $gst, 'total' => $total, 'amount_paise' => $total * 100, 'currency' => 'INR',
        'plan' => $plan['code'], 'plan_name' => $plan['name'], 'cycle' => $cycle, 'quantity' => $quantity,
        'mode' => saas_payment_mode(), 'key_id' => saas_payment_mode() === 'razorpay' ? (string) env('SAAS_RAZORPAY_KEY_ID', '') : null,
        'name' => $signup['business_name'] ?? ($tenant['business_name'] ?? ''), 'email' => $signup['email'] ?? ($tenant['email'] ?? ''),
        'phone' => $signup['phone'] ?? ($tenant['phone'] ?? ''),
    ];
}

/**
 * Marks an order paid (once — the browser and the webhook may both report it) and does what was bought:
 * a sign-up gets its shop set up, a renewal extends the plan. Returns [order row, tenant row].
 */
function saas_complete_order(string $gatewayOrderId, string $paymentId): array
{
    $o = saas_q('SELECT * FROM saas_orders WHERE gateway_order_id = ?', [$gatewayOrderId])->fetch();
    if (!$o) {
        throw new ApiException('Payment order not found', 404);
    }
    if ($o['status'] === 'paid') {
        return [$o, saas_tenant_by_id((int) $o['tenant_id'])];
    }
    // Claim the order (the browser and the webhook may arrive together). A new database cannot be made inside a
    // transaction (MySQL commits on CREATE DATABASE), so the claim is one atomic UPDATE instead of a row lock.
    $claimed = saas_q("UPDATE saas_orders SET status = 'processing', error = NULL WHERE order_id = ?
                       AND (status = 'created' OR (status = 'processing' AND created_at < NOW() - INTERVAL 10 MINUTE AND paid_at IS NULL))", [$o['order_id']])->rowCount();
    if (!$claimed) {
        $o = saas_q('SELECT * FROM saas_orders WHERE order_id = ?', [$o['order_id']])->fetch();
        if ($o['status'] === 'paid') {
            return [$o, saas_tenant_by_id((int) $o['tenant_id'])];
        }
        throw new ApiException('Your payment is received and the shop is being set up. Please wait a few seconds.', 409, ['code' => 'processing']);
    }
    $creds = null;
    try {
        $plan = saas_plan($o['plan_code']);
        if (!$plan) {
            throw new ApiException('Plan not found', 404);
        }
        if ($o['kind'] === 'signup') {
            $signup = json_decode((string) $o['signup'], true) ?: [];
            [$tenant, $creds] = saas_provision($signup, $plan, 'active', $o['billing_cycle'], (int) $o['quantity']);
            $o['tenant_id'] = $tenant['tenant_id'];
        } else {
            $tenant = saas_extend((int) $o['tenant_id'], $plan, $o['billing_cycle'], (int) $o['quantity']);
        }
        saas_q("UPDATE saas_orders SET status = 'paid', paid_at = NOW(), gateway_payment_id = ?, tenant_id = ?, credentials = ? WHERE order_id = ?",
            [$paymentId, $o['tenant_id'], $creds ? saas_seal(json_encode($creds)) : null, $o['order_id']]);
        $o = saas_q('SELECT * FROM saas_orders WHERE order_id = ?', [$o['order_id']])->fetch();
        saas_create_invoice($o, $tenant);
    } catch (Throwable $e) {
        saas_q("UPDATE saas_orders SET status = 'created', error = ? WHERE order_id = ? AND status = 'processing'", [mb_substr($e->getMessage(), 0, 250), $o['order_id']]);
        saas_event($o['tenant_id'] ? (int) $o['tenant_id'] : null, 'payment_error', "{$o['gateway_order_id']} paid ($paymentId) but not completed: " . $e->getMessage());
        throw $e;
    }
    saas_forget_tenant($tenant['slug']);
    saas_event((int) $tenant['tenant_id'], 'payment', "₹{$o['total']} paid for {$plan['name']} ({$o['billing_cycle']}) — {$paymentId}");
    if ($o['kind'] === 'signup' && $creds) {
        saas_send_welcome($tenant, $creds);
    } else {
        saas_send_receipt($tenant, $o);
    }
    return [$o, $tenant];
}

/** Login details of a sign-up, shown once on the welcome screen (then removed). */
function saas_reveal_credentials(array $order): ?array
{
    if (empty($order['credentials'])) {
        return null;
    }
    $creds = json_decode((string) saas_unseal($order['credentials']), true);
    saas_q('UPDATE saas_orders SET credentials = NULL WHERE order_id = ?', [$order['order_id']]);
    return is_array($creds) ? $creds : null;
}

/* ------------------------------------------------------------------ shops */

function saas_tenant_by_id(int $id): ?array
{
    return saas_q('SELECT * FROM saas_tenants WHERE tenant_id = ?', [$id])->fetch() ?: null;
}

function saas_tenant_by_slug(string $slug): ?array
{
    return saas_q('SELECT * FROM saas_tenants WHERE slug = ?', [$slug])->fetch() ?: null;
}

function saas_period_add(string $from, string $cycle): string
{
    return date('Y-m-d', strtotime($from . ($cycle === 'month' ? ' +1 month' : ' +1 year')));
}

function saas_random_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) {
        $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $p . random_int(10, 99); // letters + digits, easy to type from a phone
}

/**
 * Sets up a new shop: its own database with the full schema, the owner's Admin login and the business details.
 * Returns [tenant row, login details]. $status 'trial' (free days) or 'active' (paid).
 */
function saas_provision(array $signup, array $plan, string $status, string $cycle = 'year', int $quantity = 1): array
{
    $slug = $signup['slug'];
    if (saas_tenant_by_slug($slug)) {
        throw new ApiException("Shop code \"$slug\" is already taken", 409);
    }
    $dbName = saas_new_database($slug);
    $password = saas_random_password();

    $pdo = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ";dbname=$dbName;charset=utf8mb4",
        env('DB_USER', 'root'), (string) env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    // The schema set-up (report summaries) works through the global connection: point it at the new shop meanwhile.
    $control = $GLOBALS['pdo'] ?? null;
    $GLOBALS['pdo'] = $pdo;
    try {
        run_migrations($pdo, $dbName, ['admin-email' => $signup['email'], 'admin-password' => $password, 'admin-name' => $signup['owner_name']]);
    } finally {
        $GLOBALS['pdo'] = $control;
    }
    // A set-up that was retried keeps one login: the password shared now is the one that works.
    $pdo->prepare('UPDATE users SET password = ? WHERE email = ?')->execute([password_hash($password, PASSWORD_BCRYPT), $signup['email']]);
    $pdo->prepare('UPDATE stores SET name = ?, phone = ?, city = COALESCE(?, city), gstin = ? ORDER BY store_id LIMIT 1')
        ->execute([$signup['business_name'], $signup['phone'], $signup['city'], $signup['gstin']]);
    $pdo->prepare('UPDATE users SET phone = ? WHERE email = ?')->execute([$signup['phone'], $signup['email']]);

    $trialDays = (int) env('SAAS_TRIAL_DAYS', 14);
    $trialEnd = $status === 'trial' ? date('Y-m-d', strtotime("+$trialDays days")) : null;
    $periodEnd = $status === 'trial' ? null : saas_period_add(date('Y-m-d'), $cycle);
    saas_q('INSERT INTO saas_tenants (slug, db_name, business_name, owner_name, email, phone, city, gstin, plan_code, billing_cycle, store_quota, status, trial_ends_at, current_period_end)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$slug, $dbName, $signup['business_name'], $signup['owner_name'], $signup['email'], $signup['phone'], $signup['city'], $signup['gstin'],
         $plan['code'], $cycle, max(1, $quantity), $status, $trialEnd, $periodEnd]);
    $tenant = saas_tenant_by_slug($slug);
    saas_q('UPDATE saas_db_pool SET tenant_id = ?, assigned_at = NOW() WHERE db_name = ?', [$tenant['tenant_id'], $dbName]);
    saas_event((int) $tenant['tenant_id'], 'signup', "New shop {$signup['business_name']} ($slug) on {$plan['name']}" . ($status === 'trial' ? " — $trialDays-day trial" : ''));
    $creds = ['shop_code' => $slug, 'email' => $signup['email'], 'password' => $password, 'login_url' => saas_login_url($slug),
              'business_name' => $signup['business_name'], 'plan' => $plan['name'], 'status' => $status,
              'valid_till' => $trialEnd ?? $periodEnd];
    return [$tenant, $creds];
}

/** A database for a new shop: created here when allowed, otherwise taken from the pre-made pool. */
function saas_new_database(string $slug): string
{
    $pool = saas_q('SELECT db_name FROM saas_db_pool WHERE tenant_id IS NULL ORDER BY db_name LIMIT 1')->fetch();
    if ($pool) {
        return $pool['db_name'];
    }
    $name = env('SAAS_DB_PREFIX', 'khanak_t_') . str_replace('-', '_', $slug);
    try {
        saas_control_pdo()->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('[Khanak saas] create database: ' . $e->getMessage());
        throw new ApiException('Could not create the shop database. Please contact Khanak support.', 503);
    }
    return $name;
}

/** Paid renewal / upgrade: plan from today (or from the end of the running period, whichever is later). */
function saas_extend(int $tenantId, array $plan, string $cycle, int $quantity): array
{
    $t = saas_tenant_by_id($tenantId);
    if (!$t) {
        throw new ApiException('Shop not found', 404);
    }
    $runningEnd = $t['status'] === 'trial' ? null : $t['current_period_end'];
    $from = $runningEnd && $runningEnd > date('Y-m-d') && $t['plan_code'] === $plan['code'] ? $runningEnd : date('Y-m-d');
    saas_q("UPDATE saas_tenants SET plan_code = ?, billing_cycle = ?, store_quota = ?, status = 'active', current_period_end = ?, trial_ends_at = NULL WHERE tenant_id = ?",
        [$plan['code'], $cycle, max(1, $quantity), saas_period_add($from, $cycle), $tenantId]);
    return saas_tenant_by_id($tenantId);
}

function saas_login_url(string $slug): string
{
    $base = rtrim((string) env('SAAS_APP_URL', env('SAAS_PUBLIC_URL', '')), '/');
    return ($base !== '' ? $base : '') . '/login?shop=' . rawurlencode($slug);
}

/** What the shop sees about its plan. */
function saas_tenant_public(array $t): array
{
    $plan = saas_plan($t['plan_code']);
    $status = saas_effective_status($t);
    $end = saas_period_end($t);
    return [
        'shop_code' => $t['slug'], 'business_name' => $t['business_name'], 'owner_name' => $t['owner_name'], 'email' => $t['email'],
        'phone' => $t['phone'], 'plan' => $plan, 'billing_cycle' => $t['billing_cycle'], 'store_quota' => (int) $t['store_quota'],
        'status' => $status, 'valid_till' => $end ? date('Y-m-d', $end) : null,
        'days_left' => $end ? (int) floor(($end - time()) / 86400) : null,
        'grace_days' => (int) env('SAAS_GRACE_DAYS', 7),
    ];
}

/* ------------------------------------------------------------------ Khanak's own GST invoices */

function saas_next_number(string $series): int
{
    saas_q('INSERT INTO saas_counters (name, value) VALUES (?, 1) ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)', [$series]);
    $id = (int) saas_control_pdo()->lastInsertId();
    return $id ?: 1;
}

function saas_fy_label(?int $ts = null): string
{
    $ts = $ts ?? time();
    $y = (int) date('Y', $ts);
    $start = (int) date('n', $ts) >= 4 ? $y : $y - 1;
    return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
}

function saas_create_invoice(array $order, array $tenant): void
{
    if (saas_q('SELECT 1 FROM saas_invoices WHERE order_id = ?', [$order['order_id']])->fetch()) {
        return;
    }
    $fy = saas_fy_label();
    $no = sprintf('%s/%s/%04d', env('SAAS_INVOICE_PREFIX', 'KH'), $fy, saas_next_number("invoice-$fy"));
    $plan = saas_plan($order['plan_code']);
    $details = [
        'seller' => ['name' => env('SAAS_SELLER_NAME', 'Khanak'), 'gstin' => env('SAAS_SELLER_GSTIN', ''), 'address' => env('SAAS_SELLER_ADDRESS', ''),
                     'state_code' => env('SAAS_SELLER_STATE_CODE', '24')],
        'buyer' => ['name' => $tenant['business_name'], 'owner' => $tenant['owner_name'], 'gstin' => $tenant['gstin'], 'email' => $tenant['email'],
                    'phone' => $tenant['phone'], 'city' => $tenant['city'], 'shop_code' => $tenant['slug']],
        'item' => sprintf('Khanak %s plan — %s%s', $plan['name'] ?? $order['plan_code'], $order['billing_cycle'] === 'month' ? '1 month' : '1 year',
                          (int) $order['quantity'] > 1 ? ' × ' . (int) $order['quantity'] . ' stores' : ''),
        'sac' => env('SAAS_SAC_CODE', '998314'), 'gst_rate' => saas_gst_rate(), 'payment_id' => $order['gateway_payment_id'],
    ];
    saas_q('INSERT INTO saas_invoices (invoice_no, tenant_id, order_id, amount, gst, total, details) VALUES (?,?,?,?,?,?,?)',
        [$no, $tenant['tenant_id'], $order['order_id'], $order['amount'], $order['gst'], $order['total'], json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

function saas_invoice_signature(string $no): string
{
    return substr(hash_hmac('sha256', $no, saas_secret('invoice', 'control')), 0, 24);
}

function saas_invoice_url(string $no): string
{
    return saas_api_base() . '/saas/invoice.php?no=' . rawurlencode($no) . '&s=' . saas_invoice_signature($no);
}

/** Public address of the API root, e.g. https://app.khanak.in/api (SAAS_PUBLIC_URL, or worked out from this request). */
function saas_api_base(): string
{
    $public = rtrim((string) env('SAAS_PUBLIC_URL', ''), '/');
    if ($public !== '') {
        return "$public/api";
    }
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = preg_match('#^(.*?)/(saas|master|auth|reports)/#', $script, $m) ? $m[1] : '';
    return ($https ? 'https' : 'http') . "://$host$dir";
}

/* ------------------------------------------------------------------ sealing (login details kept until shown once) */

function saas_seal(string $plain): string
{
    $key = hash('sha256', saas_secret('seal', 'control'), true);
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}

function saas_unseal(string $sealed): ?string
{
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $key = hash('sha256', saas_secret('seal', 'control'), true);
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

/* ------------------------------------------------------------------ email */

function saas_mailer(): ?PHPMailer
{
    if ((string) env('SAAS_SMTP_HOST', '') === '' || (string) env('SAAS_MAIL_FROM', '') === '') {
        return null;
    }
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = (string) env('SAAS_SMTP_HOST');
    $m->Port = (int) env('SAAS_SMTP_PORT', 465);
    $secure = (string) env('SAAS_SMTP_SECURE', 'ssl');
    $m->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
    $m->SMTPAutoTLS = $secure !== 'none';
    $m->SMTPAuth = (string) env('SAAS_SMTP_USER', '') !== '';
    $m->Username = (string) env('SAAS_SMTP_USER', '');
    $m->Password = (string) env('SAAS_SMTP_PASS', '');
    $m->Timeout = 20;
    $m->CharSet = 'UTF-8';
    $m->setFrom((string) env('SAAS_MAIL_FROM'), (string) env('SAAS_MAIL_FROM_NAME', 'Khanak'));
    return $m;
}

function saas_mail(array $tenant, string $subject, string $html, string $text): bool
{
    try {
        $m = saas_mailer();
        if (!$m) {
            saas_event((int) $tenant['tenant_id'], 'email_skipped', "Email not set up (SAAS_SMTP_HOST): \"$subject\" not sent to {$tenant['email']}");
            return false;
        }
        $m->addAddress($tenant['email'], $tenant['owner_name']);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
        saas_event((int) $tenant['tenant_id'], 'email', "Sent \"$subject\" to {$tenant['email']}");
        return true;
    } catch (Throwable $e) {
        saas_event((int) $tenant['tenant_id'], 'email_failed', "\"$subject\" to {$tenant['email']}: " . $e->getMessage());
        return false;
    }
}

function saas_mail_frame(string $title, string $body): string
{
    return '<!doctype html><html><body style="margin:0;background:#f6f7f4;font-family:Arial,Helvetica,sans-serif;color:#142026">'
        . '<div style="max-width:560px;margin:0 auto;padding:16px"><div style="background:#0b4f5c;color:#fff;padding:16px 20px;border-radius:12px 12px 0 0;font-size:20px;font-weight:bold">khanak</div>'
        . '<div style="background:#fff;padding:20px;border-radius:0 0 12px 12px;font-size:15px;line-height:1.55"><h2 style="margin:0 0 12px;font-size:19px">' . $title . '</h2>' . $body . '</div>'
        . '<p style="font-size:12px;color:#5a6b70;text-align:center">Khanak — Har bill ki khanak</p></div></body></html>';
}

function saas_send_welcome(array $tenant, array $c): void
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach (['Shop code' => $c['shop_code'], 'Email' => $c['email'], 'Password' => $c['password'], 'Plan' => $c['plan'], 'Valid till' => $c['valid_till']] as $k => $v) {
        $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#5a6b70">' . $k . '</td><td style="padding:6px 0;font-weight:bold;font-family:monospace;font-size:16px">' . $h($v) . '</td></tr>';
    }
    $html = saas_mail_frame('Welcome to Khanak, ' . $h($tenant['owner_name']) . '!',
        '<p>Your shop <b>' . $h($tenant['business_name']) . '</b> is ready. Use these details to log in:</p><table>' . $rows . '</table>'
        . '<p style="margin:18px 0"><a href="' . $h($c['login_url']) . '" style="background:#f2a93b;color:#3d2600;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Open Khanak</a></p>'
        . '<p style="color:#5a6b70;font-size:13px">Please change the password after the first login (Users &gt; your name). Keep the shop code — every login on a new phone or computer asks for it.</p>');
    $text = "Welcome to Khanak!\nShop code: {$c['shop_code']}\nEmail: {$c['email']}\nPassword: {$c['password']}\nLogin: {$c['login_url']}";
    saas_mail($tenant, 'Your Khanak login details', $html, $text);
}

function saas_send_receipt(array $tenant, array $order): void
{
    $inv = saas_q('SELECT invoice_no FROM saas_invoices WHERE order_id = ?', [$order['order_id']])->fetch();
    $t = saas_tenant_by_id((int) $tenant['tenant_id']) ?? $tenant;
    $html = saas_mail_frame('Payment received — thank you!',
        '<p>₹' . number_format((int) $order['total']) . ' received for your Khanak plan. It is now valid till <b>' . htmlspecialchars((string) $t['current_period_end'], ENT_QUOTES) . '</b>.</p>'
        . ($inv ? '<p><a href="' . htmlspecialchars(saas_invoice_url($inv['invoice_no']), ENT_QUOTES) . '">GST invoice ' . htmlspecialchars($inv['invoice_no'], ENT_QUOTES) . '</a></p>' : ''));
    saas_mail($t, 'Khanak payment received', $html, "Payment of Rs {$order['total']} received. Valid till {$t['current_period_end']}.");
}

function saas_send_reminder(array $t, int $daysLeft): void
{
    $when = $daysLeft > 0 ? "ends in $daysLeft day" . ($daysLeft > 1 ? 's' : '') : 'has ended';
    $html = saas_mail_frame('Your Khanak plan ' . $when,
        '<p>Your ' . ($t['status'] === 'trial' ? 'free trial' : 'plan') . ' for <b>' . htmlspecialchars($t['business_name'], ENT_QUOTES) . '</b> ' . $when
        . '. Renew from <b>Settings &gt; Plan &amp; billing</b> in Khanak to keep billing without a break. Your data is always safe.</p>');
    saas_mail($t, 'Khanak plan ' . $when, $html, "Your Khanak plan $when. Renew from Settings > Plan & billing.");
}

/* ------------------------------------------------------------------ daily job */

/** Reminders 7 / 3 / 1 days before the end and on the day after; returns what was done. */
function saas_daily(): array
{
    $done = ['reminders' => 0, 'expired' => 0];
    foreach (saas_q("SELECT * FROM saas_tenants WHERE status IN ('trial','active')")->fetchAll() as $t) {
        $end = saas_period_end($t);
        if (!$end) {
            continue;
        }
        $days = (int) ceil(($end - time()) / 86400);
        if (in_array($days, [7, 3, 1, -1], true)) {
            $key = "reminder-{$t['tenant_id']}-" . date('Y-m-d', $end) . "-$days";
            if (!saas_q('SELECT 1 FROM saas_events WHERE type = ? AND message = ?', ['reminder', $key])->fetch()) {
                saas_send_reminder($t, max(0, $days));
                saas_event((int) $t['tenant_id'], 'reminder', $key);
                $done['reminders']++;
            }
        }
        if (saas_effective_status($t) === 'expired') {
            $done['expired']++;
        }
    }
    return $done;
}
