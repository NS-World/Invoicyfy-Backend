<?php
/**
 * Database migration + seed. CLI only:
 *
 *   php database/migrate.php                 # create / upgrade schema, seed defaults if empty
 *   php database/migrate.php --admin-email=you@shop.com --admin-password=Secret123
 *
 * Safe to run any number of times. It never drops tables or data.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/env.php';
const DEFAULT_STAFF_PERMISSIONS = ['dashboard' => ['view'], 'orders' => ['view', 'add', 'edit'], 'invoices' => ['view', 'add'],
    'payments' => ['view', 'add'], 'customers' => ['view', 'add', 'edit'], 'products' => ['view'], 'inventory' => ['view']];

$opts = getopt('', ['admin-email::', 'admin-password::', 'admin-name::']);
$schema = require __DIR__ . '/schema.php';

$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$dbName = env('DB_NAME', 'invoice_system');

$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", env('DB_USER', 'root'), (string) env('DB_PASS', ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbName`");

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function table_exists(PDO $pdo, string $db, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $s->execute([$db, $table]);
    return (bool) $s->fetchColumn();
}

function existing_columns(PDO $pdo, string $db, string $table): array
{
    $s = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $s->execute([$db, $table]);
    $cols = [];
    foreach ($s->fetchAll() as $c) {
        $cols[$c['COLUMN_NAME']] = $c;
    }
    return $cols;
}

function index_exists(PDO $pdo, string $db, string $table, string $index): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$db, $table, $index]);
    return (bool) $s->fetchColumn();
}

function normalize_type(string $t): string
{
    $t = strtolower(trim($t));
    $t = preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $t);
    return preg_replace('/\s+unsigned$/', '', $t);
}

$changes = 0;
foreach ($schema as $table => $def) {
    if (!table_exists($pdo, $dbName, $table)) {
        $parts = [];
        foreach ($def['columns'] as $col => $colDef) {
            $parts[] = "`$col` $colDef";
        }
        if (!empty($def['primary'])) {
            $parts[] = 'PRIMARY KEY ' . $def['primary'];
        }
        foreach ($def['indexes'] ?? [] as $name => $cols) {
            $parts[] = "INDEX `$name` $cols";
        }
        foreach ($def['uniques'] ?? [] as $name => $cols) {
            $parts[] = "UNIQUE KEY `$name` $cols";
        }
        foreach ($def['constraints'] ?? [] as $c) {
            $parts[] = $c;
        }
        $pdo->exec("CREATE TABLE `$table` (\n  " . implode(",\n  ", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        out("+ created table $table");
        $changes++;
        continue;
    }

    $existing = existing_columns($pdo, $dbName, $table);
    $previous = null;
    foreach ($def['columns'] as $col => $colDef) {
        $plainDef = trim(preg_replace('/\b(PRIMARY KEY|UNIQUE|AUTO_INCREMENT)\b/i', '', $colDef));
        if (!isset($existing[$col])) {
            $after = $previous ? " AFTER `$previous`" : '';
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $colDef$after");
            out("+ $table.$col added");
            $changes++;
        } elseif ($existing[$col]['COLUMN_KEY'] !== 'PRI') {
            // Bring legacy column types / nullability in line (e.g. INT qty -> DECIMAL, NOT NULL -> NULL).
            preg_match('/^([a-z]+(\([^)]*\))?)/i', $colDef, $m);
            $wantType = normalize_type($m[1]);
            $wantNullable = stripos($colDef, 'NOT NULL') === false ? 'YES' : 'NO';
            $haveType = normalize_type($existing[$col]['COLUMN_TYPE']);
            if ($haveType !== $wantType || $existing[$col]['IS_NULLABLE'] !== $wantNullable) {
                try {
                    if ($wantNullable === 'NO') {
                        $default = preg_match("/DEFAULT\s+('([^']*)'|[0-9.]+)/i", $colDef, $dm) ? ($dm[2] ?? $dm[1]) : null;
                        if ($default !== null) {
                            $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` IS NULL")->execute([$default]);
                        }
                    }
                    $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$col` $plainDef");
                    out("~ $table.$col changed ($haveType/{$existing[$col]['IS_NULLABLE']} -> $wantType/$wantNullable)");
                    $changes++;
                } catch (PDOException $e) {
                    out("! could not change $table.$col: " . $e->getMessage());
                }
            }
        }
        $previous = $col;
    }
    foreach ($def['indexes'] ?? [] as $name => $cols) {
        if (!index_exists($pdo, $dbName, $table, $name)) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
            out("+ index $table.$name");
            $changes++;
        }
    }
    foreach ($def['drop_indexes'] ?? [] as $name) {
        if (index_exists($pdo, $dbName, $table, $name)) {
            $pdo->exec("ALTER TABLE `$table` DROP INDEX `$name`");
            out("- dropped legacy index $table.$name");
            $changes++;
        }
    }
    if (!empty($def['uniques'])) {
        $uniquesPending[$table] = $def['uniques'];
    }
}

/* Unique keys are added after backfills so legacy rows already have the columns filled in. */
$uniquesPending = $uniquesPending ?? [];

/* ---------------- Data backfills for databases created by the old scripts ---------------- */
$pdo->exec("UPDATE products SET status = 'Active' WHERE LOWER(status) = 'active' AND status <> 'Active'");
$pdo->exec("UPDATE products SET status = 'Inactive' WHERE LOWER(status) = 'inactive' AND status <> 'Inactive'");
$pdo->exec("UPDATE orders SET status = CONCAT(UPPER(LEFT(status,1)), LOWER(SUBSTRING(status,2))) WHERE status REGEXP '^[a-z]'");
$pdo->exec("UPDATE order_items SET product_name = (SELECT name FROM products p WHERE p.product_id = order_items.product_id) WHERE product_name IS NULL");
$pdo->exec("UPDATE order_items SET taxable_value = ROUND(quantity * unit_price, 2), line_total = ROUND(quantity * unit_price, 2) WHERE line_total = 0 AND unit_price > 0");
$pdo->exec("UPDATE orders SET subtotal = total_amount, taxable_amount = total_amount WHERE subtotal = 0 AND total_amount > 0");
$pdo->exec("UPDATE orders SET order_date = DATE(created_at) WHERE order_date IS NULL");
$pdo->exec("UPDATE orders SET order_number = CONCAT('ORD-', order_id) WHERE order_number IS NULL OR order_number = ''");
$pdo->exec("UPDATE invoices SET balance_due = GREATEST(total_amount - amount_paid, 0) WHERE status <> 'Cancelled' AND balance_due = 0 AND amount_paid < total_amount");

$pdo->exec("UPDATE invoices SET fy = IF(MONTH(invoice_date) >= 4,
    CONCAT(LPAD(MOD(YEAR(invoice_date),100),2,'0'),'-',LPAD(MOD(YEAR(invoice_date)+1,100),2,'0')),
    CONCAT(LPAD(MOD(YEAR(invoice_date)-1,100),2,'0'),'-',LPAD(MOD(YEAR(invoice_date),100),2,'0'))) WHERE fy = ''");

foreach ($uniquesPending as $table => $uniques) {
    foreach ($uniques as $name => $cols) {
        if (!index_exists($pdo, $dbName, $table, $name)) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$name` $cols");
                out("+ unique key $table.$name");
                $changes++;
            } catch (PDOException $e) {
                out("! could not add unique key $table.$name (duplicate data?): " . $e->getMessage());
            }
        }
    }
}

// Old databases used a "User" role; give it the Staff defaults instead of no access.
$pdo->exec("UPDATE roles SET permissions = '" . json_encode(DEFAULT_STAFF_PERMISSIONS) . "' WHERE role_name = 'User' AND permissions IS NULL");

/* ---------------- Seed defaults ---------------- */
if (!(int) $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn()) {
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('Admin'), ('Manager'), ('Staff')");
    out('+ seeded roles Admin, Manager, Staff');
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn()) {
    $pdo->exec("INSERT INTO stores (name, city, state, state_code, address, phone, invoice_prefix, receipt_footer)
                VALUES ('My Business', 'Ahmedabad', 'Gujarat', '24', 'Shop address', '9999999999', 'INV', 'Thanks')");
    out('+ seeded default store (edit it from Settings)');
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()) {
    $email = $opts['admin-email'] ?? env('ADMIN_EMAIL', 'admin@invoicyfy.com');
    $password = $opts['admin-password'] ?? env('ADMIN_PASSWORD', null);
    $generated = false;
    if (!$password) {
        $password = substr(bin2hex(random_bytes(6)), 0, 12);
        $generated = true;
    }
    $roleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Admin' LIMIT 1")->fetchColumn();
    $storeId = (int) $pdo->query("SELECT MIN(store_id) FROM stores")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, password, role_id, store_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$opts['admin-name'] ?? 'Admin', $email, password_hash($password, PASSWORD_BCRYPT), $roleId, $storeId]);
    out("+ created admin user: $email");
    if ($generated) {
        out("  generated password: $password   (change it after first login)");
    }
}

out($changes ? "Migration finished ($changes change(s))." : 'Database already up to date.');
