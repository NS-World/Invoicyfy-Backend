<?php
/**
 * Report summary tables: day-wise totals kept next to the bills so reports and list totals read a few
 * thousand summary rows instead of millions of bills.
 *
 *   stat_sales_daily     bills per store / day / bill type / order type / status (amounts, paid, due, cost)
 *   stat_items_daily     items sold per store / day / product / GST rate (live bills only)
 *   stat_items_monthly   same per month (long ranges such as a financial year)
 *   stat_payments_daily  money received per store / day / payment method
 *   stat_staff_daily     live bills per store / day / cashier (staff report)
 *   stat_staff_pay_daily money collected per store / day / cashier / payment method
 *   stat_dirty           days changed since the last refresh
 *
 * Every write that changes a bill or payment calls stats_touch() for the day it belongs to (one tiny row).
 * Readers call stats_sync() first, which recomputes only those days (normally just today) from the real
 * tables, so the summaries are always exact. stats_rebuild() recomputes everything (Update database / CLI).
 */

const STATS_VERSION = 'stats_v4';

// The CLI migration (database/migrate.php) runs without lib/bootstrap.php: give it the same tiny DB helpers.
if (!function_exists('db_query')) {
    function db_query(string $sql, array $params = []): PDOStatement
    {
        global $pdo;
        $stmt = $pdo->prepare($sql);
        foreach (array_values($params) as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : (is_null($p) ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $stmt->execute();
        return $stmt;
    }
    function db_one(string $sql, array $params = []): ?array
    {
        $row = db_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
    function db_all(string $sql, array $params = []): array
    {
        return db_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
    function db_value(string $sql, array $params = [])
    {
        $v = db_query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }
}

/**
 * Days before this date were purged (Data & Archive): their summaries are history and are never recomputed
 * (the bills behind them are gone). Null when nothing was purged.
 */
function stats_frozen_before(): ?string
{
    // Read every time (one tiny query): a purge in this same run must be seen by a rebuild after it.
    try {
        $raw = db_value("SELECT setting_value FROM app_settings WHERE setting_key = 'data_retention'");
        $v = $raw ? (json_decode((string) $raw, true)['purged_before'] ?? null) : null;
    } catch (Throwable $e) {
        $v = null;
    }
    return $v ?: null;
}

/** Marks a store/day as changed. Never fails the caller (e.g. before "Update database" was pressed). */
function stats_touch($storeId, $day): void
{
    if ($day === null || $day === '') {
        return;
    }
    try {
        db_query('INSERT IGNORE INTO stat_dirty (store_id, day) VALUES (?, ?)', [(int) $storeId, substr((string) $day, 0, 10)]);
    } catch (Throwable $e) {
        // Summary tables not created yet: they are rebuilt from scratch when they are.
    }
}

/** Marks the bill's day and the days of all its payments. */
function stats_touch_invoice(int $invoiceId): void
{
    try {
        foreach (db_all(
            'SELECT store_id, invoice_date AS day FROM invoices WHERE invoice_id = ?
             UNION SELECT COALESCE(store_id, 0), payment_date FROM payments WHERE invoice_id = ?',
            [$invoiceId, $invoiceId]
        ) as $r) {
            stats_touch($r['store_id'], $r['day']);
        }
    } catch (Throwable $e) {
    }
}

function stats_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db_value('SELECT 1 FROM job_runs WHERE job = ? AND store_id = 0', [STATS_VERSION]);
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Brings the summaries up to date. Returns false when they cannot be used (tables missing / not built and
 * too much data to build within this request) — callers then read the real tables.
 */
function stats_sync(): bool
{
    static $synced = null;
    if ($synced !== null) {
        return $synced;
    }
    try {
        if (!stats_ready()) {
            if (!db_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stat_dirty_month'")) {
                return $synced = false; // "Update database" not pressed yet: it creates the tables and builds them
            }
            // Small databases are built on the spot; big ones are built by "Update database" / cron.
            if ((int) db_value('SELECT COUNT(*) FROM (SELECT 1 FROM invoices LIMIT 50001) t') > 50000) {
                return $synced = false;
            }
            stats_rebuild();
            return $synced = true;
        }
        $deadline = microtime(true) + 20;
        foreach (db_all('SELECT store_id, day FROM stat_dirty ORDER BY day DESC LIMIT 400') as $d) {
            stats_refresh_day((int) $d['store_id'], $d['day']);
            if (microtime(true) > $deadline) {
                break;
            }
        }
        // Month rows are only read for months that are over; the running month is read from the day rows.
        foreach (db_all('SELECT store_id, month FROM stat_dirty_month WHERE month < ? ORDER BY month DESC LIMIT 24', [date('Y-m-01')]) as $d) {
            stats_refresh_month((int) $d['store_id'], $d['month']);
            if (microtime(true) > $deadline) {
                break;
            }
        }
        return $synced = true;
    } catch (Throwable $e) {
        error_log('[Invoicyfy] stats_sync: ' . $e->getMessage());
        return $synced = false;
    }
}

/** SQL that fills the summary tables for bills/payments matching $where (on alias i / p). */
function stats_fill(string $invWhere, array $invParams, string $payWhere, array $payParams): void
{
    db_query(
        "INSERT INTO stat_sales_daily (store_id, day, invoice_type, order_type, status, bills, subtotal, discount, taxable, cgst, sgst, igst, tax, total, paid, balance, cogs)
         SELECT i.store_id, i.invoice_date, i.invoice_type, COALESCE(i.order_type, ''), i.status, COUNT(*),
                SUM(i.subtotal), SUM(i.discount_amount), SUM(i.taxable_amount), SUM(i.cgst_amount), SUM(i.sgst_amount), SUM(i.igst_amount),
                SUM(i.tax_amount), SUM(i.total_amount), SUM(i.amount_paid), SUM(i.balance_due), COALESCE(SUM(c.cogs), 0)
         FROM invoices i
         LEFT JOIN (SELECT ii.invoice_id, SUM(ii.quantity * ii.cost_price) AS cogs FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
                    WHERE $invWhere GROUP BY ii.invoice_id) c ON c.invoice_id = i.invoice_id
         WHERE $invWhere
         GROUP BY i.store_id, i.invoice_date, i.invoice_type, COALESCE(i.order_type, ''), i.status",
        array_merge($invParams, $invParams)
    );
    db_query(
        "INSERT INTO stat_items_daily (store_id, day, product_id, product_name, unit, gst_rate, qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills)
         SELECT i.store_id, i.invoice_date, COALESCE(ii.product_id, 0), COALESCE(ii.product_name, 'Item'), MAX(ii.unit), ii.gst_rate,
                SUM(ii.quantity), SUM(ii.taxable_value), SUM(ii.cgst_amount), SUM(ii.sgst_amount), SUM(ii.igst_amount), SUM(ii.tax_amount),
                SUM(ii.line_total), SUM(ii.quantity * ii.cost_price), COUNT(DISTINCT ii.invoice_id)
         FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
         WHERE $invWhere AND i.status <> 'Cancelled'
         GROUP BY i.store_id, i.invoice_date, COALESCE(ii.product_id, 0), COALESCE(ii.product_name, 'Item'), ii.gst_rate",
        $invParams
    );
    db_query(
        "INSERT INTO stat_gst_daily (store_id, day, b2b, place_of_supply, hsn_code, unit, gst_rate, description, qty, line_total, taxable, cgst, sgst, igst, tax)
         SELECT i.store_id, i.invoice_date, (i.customer_gstin IS NOT NULL AND i.customer_gstin <> ''), COALESCE(i.place_of_supply, '-'),
                COALESCE(ii.hsn_code, '-'), COALESCE(ii.unit, 'OTH'), ii.gst_rate, MAX(ii.product_name),
                SUM(ii.quantity), SUM(ii.line_total), SUM(ii.taxable_value), SUM(ii.cgst_amount), SUM(ii.sgst_amount), SUM(ii.igst_amount), SUM(ii.tax_amount)
         FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
         WHERE $invWhere AND i.status <> 'Cancelled'
         GROUP BY i.store_id, i.invoice_date, (i.customer_gstin IS NOT NULL AND i.customer_gstin <> ''), COALESCE(i.place_of_supply, '-'),
                  COALESCE(ii.hsn_code, '-'), COALESCE(ii.unit, 'OTH'), ii.gst_rate",
        $invParams
    );
    db_query(
        "INSERT INTO stat_payments_daily (store_id, day, method, live_amount, live_count, done_amount, done_count, all_amount, all_count)
         SELECT COALESCE(p.store_id, 0), p.payment_date, p.payment_method,
                SUM(CASE WHEN i.status IS NULL OR i.status <> 'Cancelled' THEN p.amount ELSE 0 END),
                SUM(CASE WHEN i.status IS NULL OR i.status <> 'Cancelled' THEN 1 ELSE 0 END),
                SUM(CASE WHEN p.status = 'Completed' THEN p.amount ELSE 0 END),
                SUM(p.status = 'Completed'), SUM(p.amount), COUNT(*)
         FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
         WHERE $payWhere
         GROUP BY COALESCE(p.store_id, 0), p.payment_date, p.payment_method",
        $payParams
    );
    db_query(
        "INSERT INTO stat_staff_daily (store_id, day, user_id, bills, sales, discount, coupon, loyalty, offer, approvals)
         SELECT i.store_id, i.invoice_date, COALESCE(i.user_id, 0), COUNT(*), SUM(i.total_amount), SUM(i.discount_amount),
                COALESCE(SUM(i.coupon_discount), 0), COALESCE(SUM(i.loyalty_discount), 0), COALESCE(SUM(i.offer_discount), 0), SUM(i.approved_by IS NOT NULL)
         FROM invoices i WHERE $invWhere AND i.status <> 'Cancelled'
         GROUP BY i.store_id, i.invoice_date, COALESCE(i.user_id, 0)",
        $invParams
    );
    db_query(
        "INSERT INTO stat_staff_pay_daily (store_id, day, user_id, method, amount, payments)
         SELECT COALESCE(p.store_id, 0), p.payment_date, COALESCE(p.user_id, 0), p.payment_method, SUM(p.amount), COUNT(*)
         FROM payments p WHERE $payWhere
         GROUP BY COALESCE(p.store_id, 0), p.payment_date, COALESCE(p.user_id, 0), p.payment_method",
        $payParams
    );
}

/** Month rows: items from the day rows, customers straight from the month's bills. */
function stats_fill_month(?int $storeId, string $monthStart): void
{
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $store = $storeId === null ? '' : ' AND store_id = ' . (int) $storeId;
    db_query("DELETE FROM stat_customers_monthly WHERE month = ?$store", [$monthStart]);
    db_query(
        "INSERT INTO stat_customers_monthly (store_id, month, customer_id, customer_name, customer_phone, customer_gstin, bills, taxable, tax, total, paid, balance)
         SELECT store_id, ?, COALESCE(customer_id, 0), customer_name, MAX(customer_phone), MAX(customer_gstin), COUNT(*), SUM(taxable_amount), SUM(tax_amount),
                SUM(total_amount), SUM(amount_paid), SUM(balance_due)
         FROM invoices WHERE invoice_date BETWEEN ? AND ? AND status <> 'Cancelled'$store
         GROUP BY store_id, COALESCE(customer_id, 0), customer_name",
        [$monthStart, $monthStart, $monthEnd]
    );
    db_query("DELETE FROM stat_items_monthly WHERE month = ?$store", [$monthStart]);
    db_query(
        "INSERT INTO stat_items_monthly (store_id, month, product_id, product_name, unit, gst_rate, qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills)
         SELECT store_id, ?, product_id, product_name, MAX(unit), gst_rate, SUM(qty), SUM(taxable), SUM(cgst), SUM(sgst), SUM(igst), SUM(tax),
                SUM(line_total), SUM(cost), SUM(bills)
         FROM stat_items_daily WHERE day BETWEEN ? AND ?$store
         GROUP BY store_id, product_id, product_name, gst_rate",
        [$monthStart, $monthStart, $monthEnd]
    );
}

/** Recomputes one store/day (claims the dirty row first, so two requests never do the same work). */
function stats_refresh_day(int $storeId, string $day): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $claimed = db_query('DELETE FROM stat_dirty WHERE store_id = ? AND day = ?', [$storeId, $day])->rowCount();
        $frozen = stats_frozen_before();
        if ($claimed && !($frozen && $day < $frozen)) {
            db_query('DELETE FROM stat_sales_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_items_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_payments_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_gst_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_staff_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_staff_pay_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            stats_fill('i.store_id = ? AND i.invoice_date = ?', [$storeId, $day], 'COALESCE(p.store_id, 0) = ? AND p.payment_date = ?', [$storeId, $day]);
            // Month rows are rebuilt later, only for months that are over (see stats_sync): a sale today never
            // pays for re-adding the whole month.
            db_query('INSERT IGNORE INTO stat_dirty_month (store_id, month) VALUES (?, ?)', [$storeId, substr($day, 0, 7) . '-01']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Rebuilds the month rows of one store/month (claims the dirty row first). */
function stats_refresh_month(int $storeId, string $month): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $frozen = stats_frozen_before();
        if (db_query('DELETE FROM stat_dirty_month WHERE store_id = ? AND month = ?', [$storeId, $month])->rowCount() && !($frozen && $month < $frozen)) {
            stats_fill_month($storeId, $month);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Last whole month that may be read from the month rows: never the running month (it is still changing). */
function stats_last_month_row(string $lastFull): string
{
    $prev = date('Y-m-01', strtotime(date('Y-m-01') . ' -1 month'));
    return $lastFull > $prev ? $prev : $lastFull;
}

/** Recomputes everything, a year at a time (fine for millions of bills; run by "Update database"). */
function stats_rebuild(): void
{
    @set_time_limit(0);
    // Purged years keep their summaries (history); everything from the first live day is rebuilt from the bills.
    $frozen = stats_frozen_before();
    foreach (['stat_sales_daily' => 'day', 'stat_items_daily' => 'day', 'stat_items_monthly' => 'month', 'stat_customers_monthly' => 'month', 'stat_gst_daily' => 'day',
              'stat_payments_daily' => 'day', 'stat_staff_daily' => 'day', 'stat_staff_pay_daily' => 'day', 'stat_dirty' => 'day', 'stat_dirty_month' => 'month'] as $t => $col) {
        db_query($frozen ? "DELETE FROM $t WHERE $col >= ?" : "DELETE FROM $t", $frozen ? [$frozen] : []);
    }
    $range = db_one('SELECT MIN(d) AS lo, MAX(d) AS hi FROM (SELECT MIN(invoice_date) AS d FROM invoices UNION ALL SELECT MAX(invoice_date) FROM invoices
                     UNION ALL SELECT MIN(payment_date) FROM payments UNION ALL SELECT MAX(payment_date) FROM payments) t');
    if ($range && $range['lo']) {
        $lo = $frozen && $range['lo'] < $frozen ? $frozen : $range['lo'];
        for ($y = (int) substr($lo, 0, 4); $y <= (int) substr($range['hi'], 0, 4); $y++) {
            $from = max("$y-01-01", $frozen ?: "$y-01-01");
            stats_fill('i.invoice_date BETWEEN ? AND ?', [$from, "$y-12-31"], 'p.payment_date BETWEEN ? AND ?', [$from, "$y-12-31"]);
            for ($m = 1; $m <= 12; $m++) {
                $month = sprintf('%d-%02d-01', $y, $m);
                if (!$frozen || $month >= $frozen) {
                    stats_fill_month(null, $month);
                }
            }
        }
    }
    db_query('REPLACE INTO job_runs (job, store_id, run_date, ran_at, result) VALUES (?, 0, CURDATE(), NOW(), ?)', [STATS_VERSION, 'built']);
}

/* ------------------------------------------------------------------ readers */

/** "store_id = ?" for the summary tables, or none (all stores). */
function stats_store(?int $scope, string $alias = 's'): array
{
    return $scope === null ? ['', []] : [" AND $alias.store_id = ?", [$scope]];
}

/**
 * Item rows for a date range, using month rows for whole months and day rows for the partial months at
 * either end. Returns [sqlUnion, params] selecting the stat_items columns; wrap it in "FROM (...) x".
 */
function stats_items_source(string $from, string $to, ?int $scope): array
{
    [$parts, $params] = stats_items_parts($from, $to, $scope);
    return [implode(' UNION ALL ', $parts), array_merge(...$params)];
}

/** The pieces of stats_items_source(): a list of SELECTs and a list of their params (one entry per SELECT). */
function stats_items_parts(string $from, string $to, ?int $scope): array
{
    $cols = 'store_id, product_id, product_name, unit, gst_rate, qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills';
    [$st, $sp] = stats_store($scope, 'd');
    $firstFull = substr($from, 8, 2) === '01' ? $from : date('Y-m-01', strtotime(substr($from, 0, 7) . '-01 +1 month'));
    $lastFull = stats_last_month_row($to === date('Y-m-t', strtotime($to)) ? substr($to, 0, 7) . '-01' : date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -1 month')));
    if ($firstFull > $lastFull) {
        return [["SELECT $cols FROM stat_items_daily d WHERE d.day BETWEEN ? AND ?$st"], [array_merge([$from, $to], $sp)]];
    }
    $parts = ["SELECT $cols FROM stat_items_monthly d WHERE d.month BETWEEN ? AND ?$st"];
    $params = [array_merge([$firstFull, $lastFull], $sp)];
    if ($from < $firstFull) {
        $parts[] = "SELECT $cols FROM stat_items_daily d WHERE d.day BETWEEN ? AND ?$st";
        $params[] = array_merge([$from, date('Y-m-d', strtotime("$firstFull -1 day"))], $sp);
    }
    $afterLast = date('Y-m-d', strtotime("$lastFull +1 month"));
    if ($to >= $afterLast) {
        $parts[] = "SELECT $cols FROM stat_items_daily d WHERE d.day BETWEEN ? AND ?$st";
        $params[] = array_merge([$afterLast, $to], $sp);
    }
    return [$parts, $params];
}

/**
 * Items sold in a date range, one row per product / name / GST rate: product_id, product_name, gst_rate, unit,
 * qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills. Each piece of the range (whole months, partial
 * months) is summed on its own and the pieces are added up here — no big temporary table, one read of the data.
 */
function item_groups(string $from, string $to, ?int $scope): array
{
    if (stats_sync()) {
        [$parts, $params] = stats_items_parts($from, $to, $scope);
    } else {
        [$src, $p] = items_source($from, $to, $scope);
        $parts = ['SELECT * FROM ' . $src];
        $params = [$p];
    }
    $keys = ['qty', 'taxable', 'cgst', 'sgst', 'igst', 'tax', 'line_total', 'cost', 'bills'];
    $sums = implode(', ', array_map(fn($k) => "SUM(x.$k) AS $k", $keys));
    $out = [];
    foreach ($parts as $i => $part) {
        $src = str_starts_with($part, 'SELECT * FROM ') ? substr($part, 14) : "($part) x";
        foreach (db_all("SELECT x.product_id, x.product_name, x.gst_rate, MAX(x.unit) AS unit, $sums FROM $src
                         GROUP BY x.product_id, x.product_name, x.gst_rate", $params[$i]) as $r) {
            $k = $r['product_id'] . "\0" . $r['product_name'] . "\0" . $r['gst_rate'];
            if (!isset($out[$k])) {
                $out[$k] = ['product_id' => (int) $r['product_id'], 'product_name' => $r['product_name'], 'gst_rate' => (float) $r['gst_rate'],
                    'unit' => $r['unit']] + array_fill_keys($keys, 0.0);
            } elseif ((string) $r['unit'] > (string) $out[$k]['unit']) {
                $out[$k]['unit'] = $r['unit'];
            }
            foreach ($keys as $c) {
                $out[$k][$c] += (float) $r[$c];
            }
        }
    }
    return array_values($out);
}

/** Adds up item_groups() rows by the given fields (e.g. ['product_id', 'product_name']); keeps the first unit seen. */
function item_rollup(array $groups, array $by): array
{
    $out = [];
    foreach ($groups as $g) {
        $k = implode("\0", array_map(fn($f) => (string) $g[$f], $by));
        if (!isset($out[$k])) {
            $out[$k] = $g;
            continue;
        }
        foreach (['qty', 'taxable', 'cgst', 'sgst', 'igst', 'tax', 'line_total', 'cost', 'bills'] as $c) {
            $out[$k][$c] += $g[$c];
        }
        if ((string) $g['unit'] > (string) $out[$k]['unit']) {
            $out[$k]['unit'] = $g['unit'];
        }
    }
    return array_values($out);
}

/*
 * Sources for reports. With the summaries ready these are the small summary tables; otherwise (big database
 * where "Update database" has not built them yet) the same columns are read live from the bills, so every
 * report works either way — just slower in the second case.
 */

/** FROM source with columns store_id, day, invoice_type, order_type, status, bills, subtotal ... cogs. */
function sales_source(): string
{
    if (stats_sync()) {
        return 'stat_sales_daily';
    }
    return "(SELECT i.store_id, i.invoice_date AS day, i.invoice_type, COALESCE(i.order_type, '') AS order_type, i.status, 1 AS bills,
                    i.subtotal, i.discount_amount AS discount, i.taxable_amount AS taxable, i.cgst_amount AS cgst, i.sgst_amount AS sgst,
                    i.igst_amount AS igst, i.tax_amount AS tax, i.total_amount AS total, i.amount_paid AS paid, i.balance_due AS balance,
                    (SELECT COALESCE(SUM(ii.quantity * ii.cost_price), 0) FROM invoice_items ii WHERE ii.invoice_id = i.invoice_id) AS cogs
             FROM invoices i)";
}

/** FROM source with columns store_id, day, method, live_amount, live_count, done_amount, done_count. */
function payments_source(): string
{
    if (stats_sync()) {
        return 'stat_payments_daily';
    }
    return "(SELECT COALESCE(p.store_id, 0) AS store_id, p.payment_date AS day, p.payment_method AS method,
                    CASE WHEN i.status IS NULL OR i.status <> 'Cancelled' THEN p.amount ELSE 0 END AS live_amount,
                    CASE WHEN i.status IS NULL OR i.status <> 'Cancelled' THEN 1 ELSE 0 END AS live_count,
                    CASE WHEN p.status = 'Completed' THEN p.amount ELSE 0 END AS done_amount,
                    CASE WHEN p.status = 'Completed' THEN 1 ELSE 0 END AS done_count, p.amount AS all_amount, 1 AS all_count
             FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id)";
}

/** [FROM source "(...) x", params] of item rows (live bills only) for a date range and store scope. */
function items_source(string $from, string $to, ?int $scope): array
{
    if (stats_sync()) {
        [$sql, $params] = stats_items_source($from, $to, $scope);
        return ["($sql) x", $params];
    }
    $store = $scope === null ? '' : ' AND i.store_id = ?';
    return ["(SELECT i.store_id, COALESCE(ii.product_id, 0) AS product_id, COALESCE(ii.product_name, 'Item') AS product_name, ii.unit, ii.gst_rate,
                     ii.quantity AS qty, ii.taxable_value AS taxable, ii.cgst_amount AS cgst, ii.sgst_amount AS sgst, ii.igst_amount AS igst,
                     ii.tax_amount AS tax, ii.line_total, ii.quantity * ii.cost_price AS cost, 1 AS bills
              FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
              WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ?$store) x",
        array_merge([$from, $to], $scope === null ? [] : [$scope])];
}

/**
 * Customer totals for a date range: month rows for whole months plus the bills of the partial months at
 * either end. Returns ["(...) x", params] with columns store_id, customer_id, customer_name, customer_phone,
 * customer_gstin, bills, taxable, tax, total, paid, balance.
 */
function customers_source(string $from, string $to, ?int $scope): array
{
    [$parts, $params] = customers_parts($from, $to, $scope);
    return ['(' . implode(' UNION ALL ', $parts) . ') x', array_merge(...$params)];
}

/** The pieces of customers_source(): a list of SELECTs and a list of their params (one entry per SELECT). */
function customers_parts(string $from, string $to, ?int $scope): array
{
    $live = "SELECT store_id, COALESCE(customer_id, 0) AS customer_id, customer_name, customer_phone, customer_gstin, 1 AS bills, taxable_amount AS taxable,
                    tax_amount AS tax, total_amount AS total, amount_paid AS paid, balance_due AS balance
             FROM invoices d WHERE status <> 'Cancelled' AND invoice_date BETWEEN ? AND ?";
    $st = $scope === null ? '' : ' AND d.store_id = ?';
    $sp = $scope === null ? [] : [$scope];
    if (!stats_sync()) {
        return [[$live . $st], [array_merge([$from, $to], $sp)]];
    }
    $firstFull = substr($from, 8, 2) === '01' ? $from : date('Y-m-01', strtotime(substr($from, 0, 7) . '-01 +1 month'));
    $lastFull = stats_last_month_row($to === date('Y-m-t', strtotime($to)) ? substr($to, 0, 7) . '-01' : date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -1 month')));
    if ($firstFull > $lastFull) {
        return [[$live . $st], [array_merge([$from, $to], $sp)]];
    }
    $parts = ["SELECT store_id, customer_id, customer_name, customer_phone, customer_gstin, bills, taxable, tax, total, paid, balance
               FROM stat_customers_monthly d WHERE d.month BETWEEN ? AND ?$st"];
    $params = [array_merge([$firstFull, $lastFull], $sp)];
    if ($from < $firstFull) {
        $parts[] = $live . $st;
        $params[] = array_merge([$from, date('Y-m-d', strtotime("$firstFull -1 day"))], $sp);
    }
    $afterLast = date('Y-m-d', strtotime("$lastFull +1 month"));
    if ($to >= $afterLast) {
        $parts[] = $live . $st;
        $params[] = array_merge([$afterLast, $to], $sp);
    }
    return [$parts, $params];
}

/**
 * Biggest customers of a date range: saved customers by id, walk-in bills by the name typed on them
 * ($walkIns = false leaves those out). Each piece of the range is summed on its own with only numbers in the
 * GROUP BY (small and quick even for a year of bills) and the pieces are added up here.
 * Returns [rows sorted by total desc (at most $top), totals of all customers].
 * Row: label, gstin, phone, invoices, taxable_value, tax_amount, total_amount, amount_paid, balance_due.
 */
function customer_totals(string $from, string $to, ?int $scope, int $top, bool $walkIns = true): array
{
    [$parts, $params] = customers_parts($from, $to, $scope);
    $sums = 'SUM(x.bills) AS invoices, SUM(x.taxable) AS taxable_value, SUM(x.tax) AS tax_amount, SUM(x.total) AS total_amount,
             SUM(x.paid) AS amount_paid, SUM(x.balance) AS balance_due';
    $keys = ['invoices', 'taxable_value', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due'];
    $all = [];
    $add = function (string $key, array $r) use (&$all, $keys) {
        $all[$key] ??= array_fill_keys($keys, 0);
        foreach ($keys as $k) {
            $all[$key][$k] += (float) $r[$k];
        }
    };
    foreach ($parts as $i => $part) {
        foreach (db_all("SELECT x.customer_id AS k, $sums FROM ($part) x WHERE x.customer_id <> 0 GROUP BY x.customer_id", $params[$i]) as $r) {
            $add('c' . $r['k'], $r);
        }
        if ($walkIns) {
            foreach (db_all("SELECT COALESCE(x.customer_name, '') AS k, $sums FROM ($part) x WHERE x.customer_id = 0 GROUP BY x.customer_name", $params[$i]) as $r) {
                $add('w' . $r['k'], $r);
            }
        }
    }
    $totals = array_fill_keys($keys, 0);
    foreach ($all as $r) {
        foreach ($keys as $k) {
            $totals[$k] += $r[$k];
        }
    }
    uasort($all, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);
    $all = array_slice($all, 0, $top, true);
    $ids = [];
    foreach (array_keys($all) as $key) {
        if ($key[0] === 'c') {
            $ids[] = (int) substr($key, 1);
        }
    }
    $people = [];
    foreach ($ids ? db_all('SELECT customer_id, name, phone, gstin FROM customers WHERE customer_id IN (' . implode(',', $ids) . ')') : [] as $c) {
        $people[(int) $c['customer_id']] = $c;
    }
    $rows = [];
    foreach ($all as $key => $r) {
        if ($key[0] === 'c') {
            $c = $people[(int) substr($key, 1)] ?? null;
            $head = ['label' => $c['name'] ?? ('Customer #' . substr($key, 1)), 'gstin' => $c['gstin'] ?? null, 'phone' => $c['phone'] ?? null];
        } else {
            $head = ['label' => substr($key, 1) !== '' ? substr($key, 1) : 'Walk-in', 'gstin' => null, 'phone' => null];
        }
        $rows[] = $head + array_map(fn($v) => round($v, 2), $r);
    }
    return [$rows, array_map(fn($v) => round($v, 2), $totals)];
}

/** FROM source for the GST summary (stat_gst_daily, or the same columns from the bills). */
function gst_source(): string
{
    if (stats_sync()) {
        return 'stat_gst_daily';
    }
    return "(SELECT i.store_id, i.invoice_date AS day, (i.customer_gstin IS NOT NULL AND i.customer_gstin <> '') AS b2b, COALESCE(i.place_of_supply, '-') AS place_of_supply,
                    COALESCE(ii.hsn_code, '-') AS hsn_code, COALESCE(ii.unit, 'OTH') AS unit, ii.gst_rate, ii.product_name AS description, ii.quantity AS qty,
                    ii.line_total, ii.taxable_value AS taxable, ii.cgst_amount AS cgst, ii.sgst_amount AS sgst, ii.igst_amount AS igst, ii.tax_amount AS tax
             FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id WHERE i.status <> 'Cancelled')";
}
