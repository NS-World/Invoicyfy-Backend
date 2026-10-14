<?php
/**
 * Backup & restore rules. Tables are listed in restore order (parents before children) so linked
 * records exist before the rows that point to them. A row is a duplicate when its id already exists
 * or when one of its natural keys (phone, GSTIN, invoice number...) is already used.
 */

const BACKUP_TABLES = [
    'roles' => ['label' => 'Roles', 'pk' => 'role_id', 'unique' => [['role_name']], 'admin' => true],
    'stores' => ['label' => 'Stores / Business', 'pk' => 'store_id', 'unique' => [['name']], 'exclude' => ['wa_access_token'], 'admin' => true],
    'users' => ['label' => 'Users', 'pk' => 'user_id', 'unique' => [['email']], 'admin' => true],
    'categories' => ['label' => 'Categories', 'pk' => 'category_id', 'unique' => [['name']]],
    'customers' => ['label' => 'Customers', 'pk' => 'customer_id', 'unique' => [['phone'], ['gstin']]],
    'products' => ['label' => 'Products', 'pk' => 'product_id', 'unique' => [['sku'], ['store_id', 'name']]],
    'orders' => ['label' => 'Orders', 'pk' => 'order_id', 'unique' => [['store_id', 'order_number']]],
    'order_items' => ['label' => 'Order items', 'pk' => 'order_item_id', 'match' => ['order_id', 'product_name', 'quantity', 'unit_price']],
    'invoices' => ['label' => 'Invoices', 'pk' => 'invoice_id', 'unique' => [['store_id', 'fy', 'invoice_number']]],
    'invoice_items' => ['label' => 'Invoice items', 'pk' => 'invoice_item_id', 'match' => ['invoice_id', 'product_name', 'quantity', 'unit_price']],
    'payments' => ['label' => 'Payments', 'pk' => 'payment_id', 'match' => ['invoice_id', 'amount', 'payment_date', 'payment_method', 'reference_no']],
    'expenses' => ['label' => 'Expenses', 'pk' => 'expense_id', 'match' => ['store_id', 'expense_date', 'category', 'amount', 'description']],
    'stock_movements' => ['label' => 'Stock history', 'pk' => 'movement_id', 'match' => ['product_id', 'movement_type', 'quantity', 'created_at']],
    'document_sequences' => ['label' => 'Bill number series', 'pk' => null, 'unique' => [['store_id', 'doc_type', 'fy']], 'merge' => true],
    'campaigns' => ['label' => 'Offer campaigns', 'pk' => 'campaign_id'],
    'campaign_recipients' => ['label' => 'Campaign recipients', 'pk' => 'recipient_id'],
    'coupons' => ['label' => 'Coupons', 'pk' => 'coupon_id', 'unique' => [['store_id', 'code']]],
    'coupon_redemptions' => ['label' => 'Coupon uses', 'pk' => 'redemption_id'],
    'loyalty_ledger' => ['label' => 'Loyalty points history', 'pk' => 'entry_id'],
    'cash_sessions' => ['label' => 'Day close (cash register)', 'pk' => 'session_id', 'exclude' => ['open_lock']],
    'cash_movements' => ['label' => 'Cash pay in / out', 'pk' => 'movement_id'],
    // V4
    'suppliers' => ['label' => 'Suppliers', 'pk' => 'supplier_id', 'unique' => [['store_id', 'name']]],
    'purchases' => ['label' => 'Purchase bills', 'pk' => 'purchase_id', 'match' => ['supplier_id', 'bill_no', 'purchase_date', 'total_amount']],
    'purchase_items' => ['label' => 'Purchase items', 'pk' => 'purchase_item_id', 'match' => ['purchase_id', 'product_name', 'quantity', 'unit_cost']],
    'supplier_payments' => ['label' => 'Supplier payments', 'pk' => 'payment_id', 'match' => ['supplier_id', 'amount', 'payment_date', 'payment_method']],
    'recipe_items' => ['label' => 'Recipes', 'pk' => 'recipe_item_id', 'unique' => [['product_id', 'ingredient_id']]],
    'stock_batches' => ['label' => 'Stock batches (expiry)', 'pk' => 'batch_id'],
    'offers' => ['label' => 'Combo / happy-hour offers', 'pk' => 'offer_id'],
];

/** Tables that exist in this database (V4 tables appear after "Update database"). */
function backup_existing_tables(): array
{
    static $have = null;
    if ($have === null) {
        $have = array_flip(array_map(fn($r) => (string) reset($r), db_all('SHOW TABLES')));
    }
    return array_values(array_filter(array_keys(BACKUP_TABLES), fn($t) => isset($have[$t])));
}

/** Column definitions for a table from the schema (name => SQL definition). */
function backup_columns(string $table): array
{
    static $schema = null;
    $schema ??= require __DIR__ . '/../database/schema.php';
    $cols = $schema[$table]['columns'] ?? [];
    foreach (BACKUP_TABLES[$table]['exclude'] ?? [] as $x) {
        unset($cols[$x]);
    }
    return $cols;
}

function backup_column_type(string $def): string
{
    $d = strtoupper($def);
    if (preg_match('/^(TINYINT|SMALLINT|INT|BIGINT)/', $d)) return 'int';
    if (preg_match('/^(DECIMAL|FLOAT|DOUBLE)/', $d)) return 'number';
    if (str_starts_with($d, 'DATETIME') || str_starts_with($d, 'TIMESTAMP')) return 'datetime';
    if (str_starts_with($d, 'DATE')) return 'date';
    return 'text';
}

/** Validate and normalise one imported row. Returns [cleanRow, errorMessage|null]. */
function backup_clean_row(string $table, array $raw): array
{
    $cols = backup_columns($table);
    $row = [];
    foreach ($cols as $col => $def) {
        if (!array_key_exists($col, $raw)) {
            continue;
        }
        $v = $raw[$col];
        if (is_string($v)) {
            $v = trim($v);
        }
        if ($v === '' || $v === null) {
            if (stripos($def, 'NOT NULL') === false) {
                $row[$col] = null;
            } elseif (stripos($def, "DEFAULT ''") !== false) {
                $row[$col] = ''; // NOT NULL DEFAULT '' text columns keep an empty value
            }
            continue; // otherwise the column default applies
        }
        switch (backup_column_type($def)) {
            case 'int':
                if (is_bool($v)) {
                    $v = $v ? 1 : 0;
                } elseif (in_array(strtolower((string) $v), ['true', 'yes'], true)) {
                    $v = 1;
                } elseif (in_array(strtolower((string) $v), ['false', 'no'], true)) {
                    $v = 0;
                }
                if (!is_numeric($v) || (float) $v != (int) $v) {
                    return [null, "$col must be a whole number (got \"$v\")"];
                }
                $v = (int) $v;
                break;
            case 'number':
                if (!is_numeric($v)) {
                    return [null, "$col must be a number (got \"$v\")"];
                }
                $v = (float) $v;
                break;
            case 'date':
                $ts = strtotime((string) $v);
                if ($ts === false) {
                    return [null, "$col must be a date like 2026-04-01 (got \"$v\")"];
                }
                $v = date('Y-m-d', $ts);
                break;
            case 'datetime':
                $ts = strtotime((string) $v);
                if ($ts === false) {
                    return [null, "$col must be a date/time (got \"$v\")"];
                }
                $v = date('Y-m-d H:i:s', $ts);
                break;
            default:
                $v = (string) $v;
                if (preg_match('/VARCHAR\((\d+)\)|CHAR\((\d+)\)/i', $def, $m)) {
                    $max = (int) ($m[1] ?: $m[2]);
                    if (mb_strlen($v) > $max) {
                        return [null, "$col is longer than $max characters"];
                    }
                }
        }
        $row[$col] = $v;
    }
    // Required columns (NOT NULL without a default, not auto-increment).
    foreach ($cols as $col => $def) {
        $required = stripos($def, 'NOT NULL') !== false && stripos($def, 'DEFAULT') === false && stripos($def, 'AUTO_INCREMENT') === false;
        if ($required && $col !== 'password' && $col !== BACKUP_TABLES[$table]['pk'] && ($row[$col] ?? null) === null) {
            return [null, "$col is required"];
        }
    }
    if (!$row) {
        return [null, 'Row is empty'];
    }
    return [$row, null];
}

/** Returns a reason string when the row already exists, else null. */
function backup_find_duplicate(string $table, array $row): ?string
{
    $cfg = BACKUP_TABLES[$table];
    $pk = $cfg['pk'];
    if ($pk && isset($row[$pk]) && db_value("SELECT 1 FROM `$table` WHERE `$pk` = ?", [$row[$pk]])) {
        return "Already exists ($pk {$row[$pk]})";
    }
    foreach ($cfg['unique'] ?? [] as $cols) {
        $vals = [];
        foreach ($cols as $c) {
            if (!isset($row[$c]) || $row[$c] === '') {
                continue 2; // empty keys never clash (e.g. a customer without phone)
            }
            $vals[] = $row[$c];
        }
        $where = implode(' AND ', array_map(fn($c) => "`$c` = ?", $cols));
        if (db_value("SELECT 1 FROM `$table` WHERE $where LIMIT 1", $vals)) {
            return 'Already exists (same ' . implode(' + ', $cols) . ': ' . implode(' / ', $vals) . ')';
        }
    }
    // Rows typed into the sheet without an id: compare their content.
    if ($pk && !isset($row[$pk]) && !empty($cfg['match'])) {
        $conds = [];
        $vals = [];
        foreach ($cfg['match'] as $c) {
            if (!array_key_exists($c, $row) || $row[$c] === null) {
                $conds[] = "`$c` IS NULL";
            } else {
                $conds[] = "`$c` = ?";
                $vals[] = $row[$c];
            }
        }
        if (db_value("SELECT 1 FROM `$table` WHERE " . implode(' AND ', $conds) . " LIMIT 1", $vals)) {
            return 'Same record already exists';
        }
    }
    return null;
}

/**
 * A fresh install creates a placeholder store ("My Business") and roles without custom permissions.
 * When restoring onto such an install, fill those placeholders from the backup instead of skipping them.
 */
function backup_refresh_default(string $table, array $row): ?string
{
    if ($table === 'stores' && isset($row['store_id'])) {
        $existing = db_one('SELECT name FROM stores WHERE store_id = ?', [$row['store_id']]);
        $used = (int) db_value('SELECT COUNT(*) FROM invoices WHERE store_id = ?', [$row['store_id']]);
        if ($existing && $existing['name'] === 'My Business' && !$used && $row['name'] !== 'My Business') {
            $data = $row;
            unset($data['store_id']);
            update_row('stores', 'store_id', (int) $row['store_id'], $data);
            return 'Default store replaced with your backed-up business details';
        }
    }
    if ($table === 'roles' && isset($row['role_id'], $row['permissions'])) {
        $existing = db_one('SELECT role_name, permissions FROM roles WHERE role_id = ?', [$row['role_id']]);
        if ($existing && $existing['role_name'] === $row['role_name'] && $existing['permissions'] === null) {
            db_query('UPDATE roles SET permissions = ? WHERE role_id = ?', [$row['permissions'], $row['role_id']]);
            return 'Role permissions restored';
        }
    }
    return null;
}

/** Insert one row. Returns [status, message]; status is inserted | merged | duplicate | error. */
function backup_import_row(string $table, array $raw): array
{
    [$row, $err] = backup_clean_row($table, $raw);
    if ($err) {
        return ['error', $err];
    }
    $cfg = BACKUP_TABLES[$table];
    $note = null;

    if (!empty($cfg['merge'])) {
        // Bill number series: keep the higher counter so new bills never reuse an imported number.
        db_query(
            "INSERT INTO document_sequences (store_id, doc_type, fy, last_number) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))",
            [$row['store_id'], $row['doc_type'], $row['fy'], (int) ($row['last_number'] ?? 0)]
        );
        return ['merged', 'Bill number series updated'];
    }

    if ($refreshed = backup_refresh_default($table, $row)) {
        return ['merged', $refreshed];
    }
    $dup = backup_find_duplicate($table, $row);
    if ($dup) {
        return ['duplicate', $dup];
    }
    if ($table === 'users' && !preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', (string) ($row['password'] ?? ''))) {
        $row['password'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $note = 'Imported without password — set a new password from Users';
    }
    try {
        insert_row($table, $row);
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        if ($code == 1062) {
            return ['duplicate', 'Already exists (duplicate value)'];
        }
        if ($code == 1452) {
            preg_match('/FOREIGN KEY \(`([^`]+)`\)/', $e->getMessage(), $m);
            $col = $m[1] ?? 'a linked id';
            return ['error', "Linked record not found for $col = " . ($row[$col] ?? '?') . ' — import that sheet first'];
        }
        if ($code == 1048 || $code == 1364) {
            return ['error', 'A required value is missing'];
        }
        error_log('[Invoicyfy import] ' . $e->getMessage());
        return ['error', 'Could not save this row'];
    }
    // Report summaries: the imported bill / payment / item's day is recomputed on the next report.
    if ($table === 'invoices') {
        stats_touch($row['store_id'] ?? 0, $row['invoice_date'] ?? null);
    } elseif ($table === 'payments') {
        stats_touch($row['store_id'] ?? 0, $row['payment_date'] ?? null);
    } elseif ($table === 'invoice_items' && !empty($row['invoice_id'])) {
        stats_touch_invoice((int) $row['invoice_id']);
    }
    return ['inserted', $note];
}
