<?php
/**
 * Schema migration shared by the CLI (database/migrate.php) and the Admin "Update database" button
 * (master/settings/update-database.php), so the app can be upgraded on hosting without SSH.
 * Safe to run any number of times. It never drops tables or data.
 */

const DEFAULT_STAFF_PERMISSIONS = ['dashboard' => ['view'], 'orders' => ['view', 'add', 'edit'], 'invoices' => ['view', 'add'],
    'payments' => ['view', 'add'], 'customers' => ['view', 'add', 'edit'], 'products' => ['view'], 'inventory' => ['view']];

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


/** Changes whenever schema.php changes; stored after a successful migration so the check below is one query. */
function schema_fingerprint(): string
{
    // Report summary changes (lib/stats.php) also need an "Update database" to rebuild them.
    preg_match("/const STATS_VERSION = '([^']+)'/", (string) file_get_contents(__DIR__ . '/stats.php'), $m);
    return md5((string) file_get_contents(__DIR__ . '/../database/schema.php')) . '-' . ($m[1] ?? 'stats');
}

/** Number of schema changes waiting (missing tables / columns / indexes), without changing anything. */
function pending_schema_changes(PDO $pdo, string $dbName): array
{
    try {
        $s = $pdo->prepare("SELECT result FROM job_runs WHERE job = 'schema' AND store_id = 0 AND run_date = '2000-01-01'");
        $s->execute();
        if ($s->fetchColumn() === schema_fingerprint()) {
            return [];
        }
    } catch (PDOException $e) {
        // job_runs missing: an update is needed anyway.
    }
    $schema = require __DIR__ . '/../database/schema.php';
    $pending = [];
    $s = $pdo->prepare('SELECT DISTINCT CONCAT(TABLE_NAME, ".", INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?');
    $s->execute([$dbName]);
    $indexes = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
    foreach ($schema as $table => $def) {
        if (!table_exists($pdo, $dbName, $table)) {
            $pending[] = "table $table";
            continue;
        }
        $existing = existing_columns($pdo, $dbName, $table);
        foreach (array_keys($def['columns']) as $col) {
            if (!isset($existing[$col])) {
                $pending[] = "$table.$col";
            }
        }
        foreach (array_keys(($def['indexes'] ?? []) + ($def['fulltext'] ?? [])) as $name) {
            if (!isset($indexes["$table.$name"])) {
                $pending[] = "index $table.$name";
            }
        }
    }
    return $pending;
}

/** Returns ['changes' => int, 'log' => string[]]. */
function run_migrations(PDO $pdo, string $dbName, array $opts = []): array
{
    $schema = require __DIR__ . '/../database/schema.php';
    $log = [];
    $out = function (string $msg) use (&$log) {
        $log[] = $msg;
    };

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
            foreach ($def['fulltext'] ?? [] as $name => $cols) {
                $parts[] = "FULLTEXT KEY `$name` $cols";
            }
            foreach ($def['constraints'] ?? [] as $c) {
                $parts[] = $c;
            }
            $pdo->exec("CREATE TABLE `$table` (\n  " . implode(",\n  ", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $out("+ created table $table");
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
                $out("+ $table.$col added");
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
                        $out("~ $table.$col changed ($haveType/{$existing[$col]['IS_NULLABLE']} -> $wantType/$wantNullable)");
                        $changes++;
                    } catch (PDOException $e) {
                        $out("! could not change $table.$col: " . $e->getMessage());
                    }
                }
            }
            $previous = $col;
        }
        foreach ($def['indexes'] ?? [] as $name => $cols) {
            if (!index_exists($pdo, $dbName, $table, $name)) {
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
                $out("+ index $table.$name");
                $changes++;
            }
        }
        foreach ($def['fulltext'] ?? [] as $name => $cols) {
            if (!index_exists($pdo, $dbName, $table, $name)) {
                // Word search index (needs MySQL 5.6+ / MariaDB 10.0.5+ with InnoDB). Search still works without it.
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD FULLTEXT INDEX `$name` $cols");
                    $out("+ word-search index $table.$name");
                    $changes++;
                } catch (PDOException $e) {
                    $out("! could not add word-search index $table.$name: " . $e->getMessage());
                }
            }
        }
        foreach ($def['drop_indexes'] ?? [] as $name) {
            if (index_exists($pdo, $dbName, $table, $name)) {
                $pdo->exec("ALTER TABLE `$table` DROP INDEX `$name`");
                $out("- dropped legacy index $table.$name");
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
    $fixedBalances = $pdo->exec("UPDATE invoices SET balance_due = GREATEST(total_amount - amount_paid, 0) WHERE status <> 'Cancelled' AND balance_due = 0 AND amount_paid < total_amount");

    $pdo->exec("UPDATE invoices SET fy = IF(MONTH(invoice_date) >= 4,
        CONCAT(LPAD(MOD(YEAR(invoice_date),100),2,'0'),'-',LPAD(MOD(YEAR(invoice_date)+1,100),2,'0')),
        CONCAT(LPAD(MOD(YEAR(invoice_date)-1,100),2,'0'),'-',LPAD(MOD(YEAR(invoice_date),100),2,'0'))) WHERE fy = ''");

    foreach ($uniquesPending as $table => $uniques) {
        foreach ($uniques as $name => $cols) {
            if (!index_exists($pdo, $dbName, $table, $name)) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$name` $cols");
                    $out("+ unique key $table.$name");
                    $changes++;
                } catch (PDOException $e) {
                    $out("! could not add unique key $table.$name (duplicate data?): " . $e->getMessage());
                }
            }
        }
    }

    // Old databases used a "User" role; give it the Staff defaults instead of no access.
    $pdo->exec("UPDATE roles SET permissions = '" . json_encode(DEFAULT_STAFF_PERMISSIONS) . "' WHERE role_name = 'User' AND permissions IS NULL");

    // Categories used to be free text on products: create a category for each one, with its most common unit.
    if (!(int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn()) {
        $rows = $pdo->query("SELECT category, unit, COUNT(*) AS n FROM products WHERE category IS NOT NULL AND category <> ''
                             GROUP BY category, unit ORDER BY category, n DESC")->fetchAll();
        $seen = [];
        $ins = $pdo->prepare("INSERT IGNORE INTO categories (name, default_unit) VALUES (?, ?)");
        foreach ($rows as $r) {
            $key = mb_strtolower(trim($r['category']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ins->execute([mb_substr(trim($r['category']), 0, 100), $r['unit'] ?: 'pcs']);
        }
        if ($seen) {
            $out('+ created ' . count($seen) . ' categories from existing products');
            $changes++;
        }
    }

    // V3: give existing roles the new screens (Day close, Coupons & Loyalty, Activity log) once.
    if (table_exists($pdo, $dbName, 'job_runs')) {
        require_once __DIR__ . '/permissions.php';
        $claim = $pdo->prepare("INSERT IGNORE INTO job_runs (job, store_id, run_date, ran_at, result) VALUES ('v3_role_permissions', 0, '2000-01-01', NOW(), 'done')");
        $claim->execute();
        if ($claim->rowCount()) {
            $upd = $pdo->prepare('UPDATE roles SET permissions = ? WHERE role_id = ?');
            foreach ($pdo->query("SELECT role_id, role_name, permissions FROM roles WHERE permissions IS NOT NULL AND role_name <> 'Admin'")->fetchAll() as $r) {
                $perms = json_decode((string) $r['permissions'], true);
                if (!is_array($perms)) {
                    continue;
                }
                $add = V3_ROLE_DEFAULTS[$r['role_name']] ?? (!empty($perms['invoices']) ? ['cash' => ['view', 'add', 'edit'], 'loyalty' => ['view']] : []);
                $changed = false;
                foreach ($add as $module => $actions) {
                    if (!isset($perms[$module])) {
                        $perms[$module] = $actions;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $upd->execute([json_encode($perms), $r['role_id']]);
                    $out("+ role {$r['role_name']}: access to the new V3 screens");
                }
            }
        }
    }

    // V4: give existing roles the new Suppliers & Purchases screen once.
    if (table_exists($pdo, $dbName, 'job_runs')) {
        require_once __DIR__ . '/permissions.php';
        $claim = $pdo->prepare("INSERT IGNORE INTO job_runs (job, store_id, run_date, ran_at, result) VALUES ('v4_role_permissions', 0, '2000-01-01', NOW(), 'done')");
        $claim->execute();
        if ($claim->rowCount()) {
            $upd = $pdo->prepare('UPDATE roles SET permissions = ? WHERE role_id = ?');
            foreach ($pdo->query("SELECT role_id, role_name, permissions FROM roles WHERE permissions IS NOT NULL AND role_name <> 'Admin'")->fetchAll() as $r) {
                $perms = json_decode((string) $r['permissions'], true);
                if (!is_array($perms) || isset($perms['purchases']) || empty(V4_ROLE_DEFAULTS[$r['role_name']])) {
                    continue;
                }
                $upd->execute([json_encode($perms + V4_ROLE_DEFAULTS[$r['role_name']]), $r['role_id']]);
                $out("+ role {$r['role_name']}: access to Suppliers & Purchases");
            }
        }
    }

    /* ---------------- Seed defaults ---------------- */
    if (!(int) $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn()) {
        $pdo->exec("INSERT INTO roles (role_name) VALUES ('Admin'), ('Manager'), ('Staff')");
        $out('+ seeded roles Admin, Manager, Staff');
    }
    if (!(int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn()) {
        $pdo->exec("INSERT INTO stores (name, city, state, state_code, address, phone, invoice_prefix, receipt_footer)
                    VALUES ('My Business', 'Ahmedabad', 'Gujarat', '24', 'Shop address', '9999999999', 'INV', 'Thanks')");
        $out('+ seeded default store (edit it from Settings)');
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
        $out("+ created admin user: $email");
        if ($generated) {
            $out("  generated password: $password   (change it after first login)");
        }
    }


    // Report summary tables: (re)built once per version, then kept up to date by every bill and payment.
    if (table_exists($pdo, $dbName, 'stat_sales_daily')) {
        require_once __DIR__ . '/stats.php';
        if (!stats_ready() || $fixedBalances) {
            $t = microtime(true);
            stats_rebuild();
            $out(sprintf('+ built report summaries (%.1fs)', microtime(true) - $t));
            $changes++;
        }
    }

    // Remember this schema version so the "Update database" check is a single quick query.
    $pdo->prepare("REPLACE INTO job_runs (job, store_id, run_date, ran_at, result) VALUES ('schema', 0, '2000-01-01', NOW(), ?)")->execute([schema_fingerprint()]);

    $out($changes ? "Migration finished ($changes change(s))." : 'Database already up to date.');
    return ['changes' => $changes, 'log' => $log];
}
