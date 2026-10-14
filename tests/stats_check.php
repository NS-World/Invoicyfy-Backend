<?php
/**
 * Report summaries must always equal a full rebuild from the bills. Run after any test that creates,
 * pays or cancels bills:
 *
 *   DB_NAME=invoicyfy_test php tests/stats_check.php
 *
 * It brings the summaries up to date the normal way (stats_sync: only changed days), takes a copy,
 * rebuilds everything from scratch and compares the two. Exit code 1 when they differ.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';

$tables = [
    'stat_sales_daily' => 'store_id, day, invoice_type, order_type, status, bills, subtotal, discount, taxable, cgst, sgst, igst, tax, total, paid, balance, cogs',
    'stat_items_daily' => 'store_id, day, product_id, product_name, gst_rate, qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills',
    'stat_items_monthly' => 'store_id, month, product_id, product_name, gst_rate, qty, taxable, cgst, sgst, igst, tax, line_total, cost, bills',
    'stat_customers_monthly' => 'store_id, month, customer_id, customer_name, bills, taxable, tax, total, paid, balance',
    'stat_gst_daily' => 'store_id, day, b2b, place_of_supply, hsn_code, unit, gst_rate, qty, line_total, taxable, cgst, sgst, igst, tax',
    'stat_payments_daily' => 'store_id, day, method, live_amount, live_count, done_amount, done_count, all_amount, all_count',
    'stat_staff_daily' => 'store_id, day, user_id, bills, sales, discount, coupon, loyalty, offer, approvals',
    'stat_staff_pay_daily' => 'store_id, day, user_id, method, amount, payments',
];
$snapshot = function () use ($tables): array {
    $out = [];
    foreach ($tables as $t => $cols) {
        $rows = array_map(fn($r) => implode('|', $r), db_all("SELECT $cols FROM $t"));
        sort($rows);
        $out[$t] = $rows;
    }
    return $out;
};

if (!stats_sync()) {
    fwrite(STDERR, "Summaries are not available (run php database/migrate.php first)\n");
    exit(1);
}
$incremental = $snapshot();
$dirty = (int) db_value('SELECT COUNT(*) FROM stat_dirty');
stats_rebuild();
$full = $snapshot();

$failed = 0;
foreach ($tables as $t => $cols) {
    $missing = array_diff($full[$t], $incremental[$t]);
    $extra = array_diff($incremental[$t], $full[$t]);
    if ($missing || $extra) {
        $failed++;
        printf("✗ %s: %d row(s) missing, %d row(s) extra\n", $t, count($missing), count($extra));
        foreach (array_slice($missing, 0, 3) as $r) {
            echo "    missing: $r\n";
        }
        foreach (array_slice($extra, 0, 3) as $r) {
            echo "    extra:   $r\n";
        }
    } else {
        printf("✓ %s (%d rows)\n", $t, count($full[$t]));
    }
}
if ($dirty) {
    $failed++;
    echo "✗ $dirty day(s) still marked as changed after sync\n";
}
echo $failed ? "FAILED\n" : "Summaries match a full rebuild.\n";
exit($failed ? 1 : 0);
