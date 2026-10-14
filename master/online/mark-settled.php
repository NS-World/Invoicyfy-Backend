<?php
/**
 * The platform paid out: POST { invoice_ids: [..], settled_at: "YYYY-MM-DD", amount_received?: total for these orders }
 * Without amount_received each order is settled at its expected payout (total - commission). With it, the amount is
 * shared over the orders in proportion, and the real commission becomes total - received.
 * { invoice_ids, undo: true } marks them unsettled again.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($d['invoice_ids'] ?? [])))));
if (!$ids || count($ids) > 500) {
    fail('Select 1 to 500 orders');
}
$marks = implode(',', array_fill(0, count($ids), '?'));
$pdo->beginTransaction();
$rows = db_all("SELECT invoice_id, store_id, total_amount, channel_commission, sales_channel, status FROM invoices WHERE invoice_id IN ($marks) FOR UPDATE", $ids);
if (count($rows) !== count($ids)) {
    fail('Some orders were not found', 404);
}
foreach ($rows as $r) {
    assert_store_access($user, $r['store_id']);
    if (!$r['sales_channel'] || $r['status'] === 'Cancelled') {
        fail('Only online platform orders that are not cancelled can be settled', 422);
    }
}
if (bool_field($d, 'undo')) {
    db_query("UPDATE invoices SET settled_amount = NULL, settled_at = NULL WHERE invoice_id IN ($marks)", $ids);
    $pdo->commit();
    log_activity('online.unsettle', 'invoice', null, count($ids) . ' online order(s) marked not settled', ['invoice_ids' => $ids], $rows[0]['store_id']);
    json_out(['status' => 'success', 'message' => count($ids) . ' order(s) marked as not settled']);
}
$date = date_field($d, 'settled_at', date('Y-m-d'));
$received = num_field($d, 'amount_received');
$sumTotal = array_sum(array_map(fn($r) => (float) $r['total_amount'], $rows));
if ($received !== null && ($received < 0 || $received - $sumTotal > 0.009)) {
    fail('Amount received must be between 0 and the order total (' . rupees($sumTotal) . ')');
}
$given = 0.0;
foreach ($rows as $n => $r) {
    if ($received === null) {
        $amount = round((float) $r['total_amount'] - (float) $r['channel_commission'], 2);
        db_query('UPDATE invoices SET settled_amount = ?, settled_at = ? WHERE invoice_id = ?', [$amount, $date, $r['invoice_id']]);
        continue;
    }
    $amount = $n === count($rows) - 1 ? round($received - $given, 2) : ($sumTotal > 0 ? round($received * (float) $r['total_amount'] / $sumTotal, 2) : 0);
    $given += $amount;
    db_query('UPDATE invoices SET settled_amount = ?, settled_at = ?, channel_commission = ? WHERE invoice_id = ?',
        [$amount, $date, round((float) $r['total_amount'] - $amount, 2), $r['invoice_id']]);
}
$pdo->commit();
log_activity('online.settle', 'invoice', null, count($ids) . ' online order(s) settled' . ($received !== null ? ', received ' . rupees($received) : ''), ['invoice_ids' => $ids], $rows[0]['store_id']);
json_out(['status' => 'success', 'message' => count($ids) . ' order(s) marked as settled']);
