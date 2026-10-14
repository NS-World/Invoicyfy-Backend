<?php
/**
 * Common bootstrap for every API endpoint.
 *
 *   require_once __DIR__ . '/../../lib/bootstrap.php';
 *   $authUser = require_auth();
 *
 * Gives you: $pdo, CORS + JSON headers, a JSON error handler and the helpers below.
 */
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header_remove('X-Powered-By');
header('Referrer-Policy: no-referrer');
// API answers hold business data: browsers and proxies must not keep copies.
header('Cache-Control: no-store, max-age=0');

/** Time spent in the database during this request (reported in the Server-Timing header). */
$GLOBALS['__db_ms'] = 0.0;
$GLOBALS['__db_n'] = 0;

class ApiException extends RuntimeException
{
    public array $extra;

    public function __construct(string $message, int $code = 400, array $extra = [])
    {
        parent::__construct($message, $code);
        $this->extra = $extra;
    }
}

set_exception_handler(function (Throwable $e) {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof ApiException) {
        json_out(array_merge(["status" => "error", "message" => $e->getMessage()], $e->extra), $e->getCode() ?: 400);
    }
    error_log('[Invoicyfy] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $message = env('APP_DEBUG', false) ? $e->getMessage() : 'Something went wrong. Please try again.';
    json_out(["status" => "error", "message" => $message], 500);
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/gst.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/v4.php';

/* ----------------------------------------------------------------------------
 * Request / response helpers
 * ------------------------------------------------------------------------- */

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!headers_sent()) {
        // Where the time went (shown in the browser's Network tab > Timing): total PHP time and database time.
        $total = (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000;
        header(sprintf('Server-Timing: app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, $GLOBALS['__db_ms'] ?? 0, $GLOBALS['__db_n'] ?? 0));
        // Big lists / reports are compressed (5-10x smaller), unless the web server already does it.
        $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
        if (strlen($body) > 1400 && stripos($accept, 'gzip') !== false && function_exists('gzencode') && !ini_get('zlib.output_compression')) {
            $zipped = gzencode($body, 5);
            if ($zipped !== false) {
                $body = $zipped;
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding', false);
            }
        }
        header('Content-Length: ' . strlen($body));
    }
    echo $body;
    exit;
}

function fail(string $message, int $code = 400, array $extra = []): void
{
    throw new ApiException($message, $code, $extra);
}

function allow_methods(string ...$methods): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        fail('Method not allowed', 405);
    }
}

function input(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : $_POST;
    }
    return $data;
}

/** Read a string field (trimmed). */
function str_field(array $src, string $key, ?string $default = null): ?string
{
    if (!array_key_exists($key, $src) || $src[$key] === null) {
        return $default;
    }
    $v = trim((string) $src[$key]);
    return $v === '' ? $default : $v;
}

function num_field(array $src, string $key, ?float $default = null): ?float
{
    if (!array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') {
        return $default;
    }
    if (!is_numeric($src[$key])) {
        fail("$key must be a number");
    }
    return (float) $src[$key];
}

function int_field(array $src, string $key, ?int $default = null): ?int
{
    if (!array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') {
        return $default;
    }
    if (!is_numeric($src[$key])) {
        fail("$key must be a number");
    }
    return (int) $src[$key];
}

function bool_field(array $src, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') {
        return $default;
    }
    return filter_var($src[$key], FILTER_VALIDATE_BOOLEAN);
}

function require_fields(array $src, array $fields): void
{
    foreach ($fields as $f) {
        if (!isset($src[$f]) || (is_string($src[$f]) && trim($src[$f]) === '')) {
            fail(str_replace('_', ' ', $f) . ' is required');
        }
    }
}

function date_field(array $src, string $key, ?string $default = null): ?string
{
    $v = str_field($src, $key);
    if ($v === null) {
        return $default;
    }
    $d = DateTime::createFromFormat('Y-m-d', substr($v, 0, 10));
    if (!$d) {
        fail("$key must be a date (YYYY-MM-DD)");
    }
    return $d->format('Y-m-d');
}

/** Standard page/limit/search handling. limit is capped to keep queries cheap. */
function pagination(): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = (int) ($_GET['limit'] ?? 10);
    $limit = $limit < 1 ? 10 : min($limit, 500);
    return [$page, $limit, ($page - 1) * $limit, trim((string) ($_GET['search'] ?? ''))];
}

/** Date range from ?from=&to= (defaults: current month). */
function date_range(?string $defaultFrom = null, ?string $defaultTo = null): array
{
    $from = date_field($_GET, 'from', $defaultFrom ?? date('Y-m-01'));
    $to = date_field($_GET, 'to', $defaultTo ?? date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

/** Execute a prepared statement with positional params, binding ints properly (needed for LIMIT). */
function db_query(string $sql, array $params = []): PDOStatement
{
    global $pdo;
    $t = microtime(true);
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($params as $p) {
        $type = is_int($p) ? PDO::PARAM_INT : (is_null($p) ? PDO::PARAM_NULL : (is_bool($p) ? PDO::PARAM_BOOL : PDO::PARAM_STR));
        $stmt->bindValue($i++, $p, $type);
    }
    $stmt->execute();
    $GLOBALS['__db_ms'] = ($GLOBALS['__db_ms'] ?? 0) + (microtime(true) - $t) * 1000;
    $GLOBALS['__db_n'] = ($GLOBALS['__db_n'] ?? 0) + 1;
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = [])
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function money($v): float
{
    return round((float) $v, 2);
}

/** Cast numeric DB columns so the frontend gets numbers, not strings. */
function cast_numbers(array $row, array $floats = [], array $ints = []): array
{
    foreach ($floats as $f) {
        if (array_key_exists($f, $row) && $row[$f] !== null) {
            $row[$f] = (float) $row[$f];
        }
    }
    foreach ($ints as $f) {
        if (array_key_exists($f, $row) && $row[$f] !== null) {
            $row[$f] = (int) $row[$f];
        }
    }
    return $row;
}

/** Indian financial year label for a date, e.g. 2026-10-11 => "26-27". */
function financial_year(string $date): string
{
    $ts = strtotime($date);
    $y = (int) date('Y', $ts);
    $start = ((int) date('n', $ts) >= 4) ? $y : $y - 1;
    return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
}

/**
 * Next number in a per-store, per-financial-year series. Must be called inside a transaction;
 * the row lock makes it safe under concurrent billing.
 */
function next_sequence(int $storeId, string $docType, string $fy): int
{
    db_query(
        "INSERT INTO document_sequences (store_id, doc_type, fy, last_number) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE last_number = last_number",
        [$storeId, $docType, $fy]
    );
    $current = (int) db_value(
        "SELECT last_number FROM document_sequences WHERE store_id = ? AND doc_type = ? AND fy = ? FOR UPDATE",
        [$storeId, $docType, $fy]
    );
    $next = $current + 1;
    db_query(
        "UPDATE document_sequences SET last_number = ? WHERE store_id = ? AND doc_type = ? AND fy = ?",
        [$next, $storeId, $docType, $fy]
    );
    return $next;
}

function get_store(int $storeId): array
{
    $store = db_one("SELECT * FROM stores WHERE store_id = ?", [$storeId]);
    if (!$store) {
        fail('Store not found', 404);
    }
    return $store;
}

/**
 * Adjust stock for a product and record the movement. $qty is signed (+in / -out).
 * Products with track_inventory = 0 are ignored.
 */
function move_stock(int $productId, float $qty, string $type, ?string $refType, ?int $refId, ?string $notes, ?int $userId, ?int $batchId = null): void
{
    if ($qty == 0.0) {
        return;
    }
    $product = db_one("SELECT product_id, store_id, track_inventory FROM products WHERE product_id = ? FOR UPDATE", [$productId]);
    if (!$product || !(int) $product['track_inventory']) {
        return;
    }
    db_query("UPDATE products SET stock_qty = stock_qty + ? WHERE product_id = ?", [$qty, $productId]);
    $balance = (float) db_value("SELECT stock_qty FROM products WHERE product_id = ?", [$productId]);
    db_query(
        "INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, balance_after, reference_type, reference_id, notes, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$productId, $product['store_id'], $type, $qty, $balance, $refType, $refId, $notes, $userId]
    );
    // V4 batches: stock out uses the batch expiring first; stock back (cancelled bill / return) refills them.
    if ($qty < 0) {
        batches_take($productId, -$qty, $batchId);
    } elseif (in_array($type, ['cancel', 'return'], true)) {
        batches_return($productId, $qty);
    }
}
