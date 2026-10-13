<?php
/**
 * Report summary tables: day-wise totals kept next to the bills so reports and list totals read a few
 * thousand summary rows instead of millions of bills.
 *
 *   stat_sales_daily     bills per store / day / bill type / order type / status (amounts, paid, due, cost)
 *   stat_items_daily     items sold per store / day / product / GST rate (live bills only)
 *   stat_items_monthly   same per month (long ranges such as a financial year)
 *   stat_payments_daily  money received per store / day / payment method
 *   stat_dirty           days changed since the last refresh
 *
 * Every write that changes a bill or payment calls stats_touch() for the day it belongs to (one tiny row).
 * Readers call stats_sync() first, which recomputes only those days (normally just today) from the real
 * tables, so the summaries are always exact. stats_rebuild() recomputes everything (Update database / CLI).
 */

const STATS_VERSION = 'stats_v3';

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
        if ($claimed) {
            db_query('DELETE FROM stat_sales_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_items_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_payments_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            db_query('DELETE FROM stat_gst_daily WHERE store_id = ? AND day = ?', [$storeId, $day]);
            stats_fill('i.store_id = ? AND i.invoice_date = ?', [$storeId, $day], 'COALESCE(p.store_id, 0) = ? AND p.payment_date = ?', [$storeId, $day]);
            stats_fill_month($storeId, substr($day, 0, 7) . '-01');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Recomputes everything, a year at a time (fine for millions of bills; run by "Update database"). */
function stats_rebuild(): void
{
    @set_time_limit(0);
    foreach (['stat_sales_daily', 'stat_items_daily', 'stat_items_monthly', 'stat_customers_monthly', 'stat_gst_daily', 'stat_payments_daily', 'stat_dirty'] as $t) {
        db_query("DELETE FROM $t");
    }
    $range = db_one('SELECT MIN(d) AS lo, MAX(d) AS hi FROM (SELECT MIN(invoice_date) AS d FROM invoices UNION ALL SELECT MAX(invoice_date) FROM invoices
                     UNION ALL SELECT MIN(payment_date) FROM payments UNION ALL SELECT MAX(payment_date) FROM payments) t');
    if ($range && $range['lo']) {
        for ($y = (int) substr($range['lo'], 0, 4); $y <= (int) substr($range['hi'], 0, 4); $y++) {
            stats_fill('i.invoice_date BETWEEN ? AND ?', ["$y-01-01", "$y-12-31"], 'p.payment_date BETWEEN ? AND ?', ["$y-01-01", "$y-12-31"]);
            for ($m = 1; $m <= 12; $m++) {
                stats_fill_month(null, sprintf('%d-%02d-01', $y, $m));
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
    $cols = 'store_id, product_id, product_name, unit, gst_rate, qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills';
    [$st, $sp] = stats_store($scope, 'd');
    $firstFull = substr($from, 8, 2) === '01' ? $from : date('Y-m-01', strtotime(substr($from, 0, 7) . '-01 +1 month'));
    $lastFull = $to === date('Y-m-t', strtotime($to)) ? substr($to, 0, 7) . '-01' : date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -1 month'));
    if ($firstFull > $lastFull) {
        return ["SELECT $cols FROM stat_items_daily d WHERE d.day BETWEEN ? AND ?$st", array_merge([$from, $to], $sp)];
    }
    $parts = ["SELECT $cols FROM stat_items_monthly d WHERE d.month BETWEEN ? AND ?$st"];
    $params = array_merge([$firstFull, $lastFull], $sp);
    if ($from < $firstFull) {
        $parts[] = "SELECT $cols FROM stat_items_daily d WHERE d.day BETWEEN ? AND ?$st";
        array_push($params, $from, date('Y-m-d', strtotime("$firstFull -1 day")), ...$sp);
    }
    $afterLast = date('Y-m-d', strtotime("$lastFull +1 month"));
    if ($to >= $afterLast) {
        $parts[] = "SELECT $cols FROM stat_items_daily d WHERE d.day BETWEEN ? AND ?$st";
        array_push($params, $afterLast, $to, ...$sp);
    }
    return [implode(' UNION ALL ', $parts), $params];
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
    $live = "SELECT store_id, COALESCE(customer_id, 0) AS customer_id, customer_name, customer_phone, customer_gstin, 1 AS bills, taxable_amount AS taxable,
                    tax_amount AS tax, total_amount AS total, amount_paid AS paid, balance_due AS balance
             FROM invoices d WHERE status <> 'Cancelled' AND invoice_date BETWEEN ? AND ?";
    $st = $scope === null ? '' : ' AND d.store_id = ?';
    $sp = $scope === null ? [] : [$scope];
    if (!stats_sync()) {
        return ["($live$st) x", array_merge([$from, $to], $sp)];
    }
    $firstFull = substr($from, 8, 2) === '01' ? $from : date('Y-m-01', strtotime(substr($from, 0, 7) . '-01 +1 month'));
    $lastFull = $to === date('Y-m-t', strtotime($to)) ? substr($to, 0, 7) . '-01' : date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -1 month'));
    if ($firstFull > $lastFull) {
        return ["($live$st) x", array_merge([$from, $to], $sp)];
    }
    $parts = ["SELECT store_id, customer_id, customer_name, customer_phone, customer_gstin, bills, taxable, tax, total, paid, balance
               FROM stat_customers_monthly d WHERE d.month BETWEEN ? AND ?$st"];
    $params = array_merge([$firstFull, $lastFull], $sp);
    if ($from < $firstFull) {
        $parts[] = $live . $st;
        array_push($params, $from, date('Y-m-d', strtotime("$firstFull -1 day")), ...$sp);
    }
    $afterLast = date('Y-m-d', strtotime("$lastFull +1 month"));
    if ($to >= $afterLast) {
        $parts[] = $live . $st;
        array_push($params, $afterLast, $to, ...$sp);
    }
    return ['(' . implode(' UNION ALL ', $parts) . ') x', $params];
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
