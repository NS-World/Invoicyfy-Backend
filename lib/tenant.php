<?php
/**
 * Khanak SaaS: which shop (tenant) a request belongs to. Loaded by bootstrap.php before the database connects.
 *
 * Off unless SAAS_MODE=true: then nothing here runs and the app works exactly as a single-shop install.
 *
 * With SAAS_MODE=true every shop has its own database. The shop comes from (first match wins):
 *   - the X-Tenant header (sent by the web app after login with the shop code),
 *   - the KHANAK_TENANT server variable (set by the /t/<shop>/api/... rewrite in .htaccess, used by bill links),
 *   - TENANT in the environment (command line: cron, migrations), or ?tenant= (local testing).
 * For that request it points DB_NAME at the shop's database, gives the shop its own token secrets (a login of one
 * shop never works for another), its own storage folder and its own public address, and applies the plan:
 * expired plans become read-only, features outside the plan and users / stores over the limit are refused.
 * Scripts under /saas/ (sign-up, payments, super admin) use the control database instead.
 */
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/saas_plans.php';

function saas_mode(): bool
{
    return filter_var(env('SAAS_MODE', false), FILTER_VALIDATE_BOOLEAN);
}

/** Connection to the control database (shops, plans, payments). */
function saas_control_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . saas_control_db() . ';charset=utf8mb4',
            env('DB_USER', 'root'),
            (string) env('DB_PASS', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function saas_control_db(): string
{
    return (string) env('SAAS_DB_NAME', 'khanak_control');
}

/** Per-shop secret derived from the master key (token signing, encryption). */
function saas_secret(string $purpose, string $slug): string
{
    $master = (string) env('SAAS_MASTER_KEY', env('JWT_ACCESS_SECRET', ''));
    return hash_hmac('sha256', "$purpose|$slug", $master);
}

function saas_valid_slug(?string $slug): bool
{
    return is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{2,29}$/', $slug) === 1;
}

function saas_request_slug(): ?string
{
    $candidates = [
        $_SERVER['HTTP_X_TENANT'] ?? null,
        $_SERVER['KHANAK_TENANT'] ?? null,
        $_SERVER['REDIRECT_KHANAK_TENANT'] ?? null,
        PHP_SAPI === 'cli' ? (getenv('TENANT') ?: null) : null,
        $_GET['tenant'] ?? null,
    ];
    foreach ($candidates as $c) {
        if ($c !== null && $c !== '') {
            $c = strtolower(trim((string) $c));
            return saas_valid_slug($c) ? $c : null;
        }
    }
    return null;
}

/** Shop row with its plan, cached for 30 seconds in the storage folder (one less query per request). */
function saas_tenant_with_plan(string $slug): ?array
{
    $dir = __DIR__ . '/../storage/saas';
    $file = "$dir/tenant-$slug.json";
    if (is_file($file) && filemtime($file) > time() - 30) {
        $row = json_decode((string) @file_get_contents($file), true);
        if (is_array($row)) {
            return $row;
        }
    }
    $st = saas_control_pdo()->prepare('SELECT t.*, p.name AS plan_name, p.features AS plan_features, p.max_users AS plan_max_users,
            p.max_stores AS plan_max_stores, p.per_store AS plan_per_store
        FROM saas_tenants t LEFT JOIN saas_plans p ON p.code = t.plan_code WHERE t.slug = ?');
    $st->execute([$slug]);
    $row = $st->fetch() ?: null;
    if ($row && (is_dir($dir) || @mkdir($dir, 0750, true))) {
        @file_put_contents("$file.tmp", json_encode($row));
        @rename("$file.tmp", $file);
    }
    return $row;
}

function saas_forget_tenant(string $slug): void
{
    @unlink(__DIR__ . "/../storage/saas/tenant-$slug.json");
}

/** trial | active | grace | expired | suspended */
function saas_effective_status(array $t, ?int $now = null): string
{
    $now = $now ?? time();
    if (($t['status'] ?? '') === 'suspended') {
        return 'suspended';
    }
    $end = saas_period_end($t);
    if ($end === null || $now <= $end) {
        return ($t['status'] ?? '') === 'trial' ? 'trial' : 'active';
    }
    $grace = (int) env('SAAS_GRACE_DAYS', 7) * 86400;
    return $now <= $end + $grace ? 'grace' : 'expired';
}

/** End of the paid period (or of the trial), as a timestamp at the end of that day. */
function saas_period_end(array $t): ?int
{
    $d = ($t['status'] ?? '') === 'trial' ? ($t['trial_ends_at'] ?? null) : ($t['current_period_end'] ?? null);
    return $d ? strtotime(substr((string) $d, 0, 10) . ' 23:59:59') : null;
}

/** Features the shop's plan includes. */
function saas_tenant_features(array $t): array
{
    $features = json_decode((string) ($t['plan_features'] ?? '[]'), true);
    return array_values(array_unique(array_merge(SAAS_BASE_FEATURES, is_array($features) ? $features : [])));
}

function saas_stop(int $code, string $message, array $extra = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Path of the running script relative to the API root, e.g. "master/user/create-user.php". */
function saas_script_rel(): string
{
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $script = str_replace('\\', '/', (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
    return ltrim($script !== '' && strpos($script, $root) === 0 ? substr($script, strlen($root)) : (string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/');
}

function saas_boot(): void
{
    if (!saas_mode() || defined('KHANAK_SAAS_BOOTED')) {
        return;
    }
    define('KHANAK_SAAS_BOOTED', true);
    // CORS first: a browser preflight (OPTIONS) never carries the X-Tenant value, and the
    // "shop code" errors below must reach the browser with CORS headers to be readable.
    require_once __DIR__ . '/../config/cors.php';
    $rel = saas_script_rel();

    // Sign-up, payments and the super admin work on the control database.
    if (strncmp($rel, 'saas/', 5) === 0) {
        putenv('DB_NAME=' . saas_control_db());
        define('KHANAK_CONTROL', true);
        return;
    }
    if ($rel === 'health.php') {
        return;
    }

    $slug = saas_request_slug();
    if ($slug === null) {
        if (PHP_SAPI === 'cli') {
            return; // command-line tools without TENANT work on DB_NAME as before
        }
        saas_stop(400, 'Enter your shop code to continue', ['code' => 'shop_required']);
    }
    try {
        $t = saas_tenant_with_plan($slug);
    } catch (Throwable $e) {
        error_log('[Khanak saas] control database: ' . $e->getMessage());
        saas_stop(503, 'Service is starting, please try again in a minute');
    }
    if (!$t) {
        saas_stop(404, 'Shop code not found. Check the code in your welcome message.', ['code' => 'shop_not_found']);
    }
    $status = saas_effective_status($t);
    if ($status === 'suspended') {
        saas_stop(403, 'This shop account is on hold. Please contact Khanak support.', ['code' => 'shop_suspended']);
    }

    // This request now belongs to the shop.
    putenv('DB_NAME=' . $t['db_name']);
    putenv('JWT_ACCESS_SECRET=' . saas_secret('jwt-access', $slug));
    putenv('JWT_REFRESH_SECRET=' . saas_secret('jwt-refresh', $slug));
    putenv('APP_KEY=' . saas_secret('app-key', $slug));
    putenv('STORAGE_DIR=' . __DIR__ . '/../storage/tenants/' . $slug);
    $public = rtrim((string) env('SAAS_PUBLIC_URL', ''), '/');
    if ($public === '' && PHP_SAPI !== 'cli') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $public = ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }
    if ($public !== '') {
        putenv("APP_URL=$public/t/$slug"); // public links (bills, pictures) carry the shop: /t/<shop>/api/...
    }
    $GLOBALS['khanak_tenant'] = $t + ['effective_status' => $status];
    if (!headers_sent()) {
        header('X-Khanak-Shop: ' . $slug);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $write = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    $always = strncmp($rel, 'auth/', 5) === 0 || strncmp($rel, 'master/plan/', 12) === 0 || $rel === 'bill.php';

    // Plan over (after the grace days): everything can be seen and exported, nothing new can be saved.
    if ($status === 'expired' && $write && !$always && PHP_SAPI !== 'cli') {
        saas_stop(402, 'Your Khanak plan has expired. Renew from Settings > Plan & billing to continue billing. Your data is safe.', ['code' => 'plan_expired']);
    }
    // Feature outside the plan.
    $feature = saas_feature_for_path($rel);
    if ($feature && !in_array($feature, saas_tenant_features($t), true) && PHP_SAPI !== 'cli') {
        saas_stop(402, sprintf('%s is not in your %s plan. Upgrade from Settings > Plan & billing.', SAAS_FEATURES[$feature] ?? 'This feature', $t['plan_name'] ?? 'current'),
            ['code' => 'plan_feature', 'feature' => $feature]);
    }
    // Users / stores over the plan limit.
    $limits = ['master/user/create-user.php' => ['users', 'plan_max_users', 'is_active = 1', 'users'],
               'master/store/add-store.php' => ['stores', 'plan_max_stores', '1=1', 'stores']];
    if ($write && isset($limits[$rel])) {
        [$table, $key, $where, $label] = $limits[$rel];
        $max = (int) ($t[$key] ?? 0);
        if ($key === 'plan_max_stores' && (int) ($t['plan_per_store'] ?? 0)) {
            $max = max(1, (int) ($t['store_quota'] ?? 1));
        }
        if ($max > 0) {
            try {
                $pdo = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
                    env('DB_USER', 'root'), (string) env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $col = $table === 'users' ? "SHOW COLUMNS FROM users LIKE 'is_active'" : null;
                if ($col && !$pdo->query($col)->fetch()) {
                    $where = '1=1';
                }
                $n = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
            } catch (Throwable $e) {
                $n = 0;
            }
            if ($n >= $max) {
                saas_stop(402, "Your {$t['plan_name']} plan allows $max $label. Upgrade from Settings > Plan & billing to add more.", ['code' => 'plan_limit', 'limit' => $label]);
            }
        }
    }
}

saas_boot();
