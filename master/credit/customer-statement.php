<?php
/**
 * Account statement of a customer: GET ?customer_id=&from=&to=&store_id=
 * Opening balance, every bill (debit) and payment (credit) with a running balance, and the bills still open.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
allow_methods('GET');
$user = require_auth();

$id = int_field($_GET, 'customer_id') ?? 0;
$c = db_one('SELECT customer_id, name, company_name, phone, email, address, city, gstin FROM customers WHERE customer_id = ?', [$id]);
if (!$c) {
    fail('Customer not found', 404);
}
[$from, $to] = date_range(date('Y-m-d', strtotime('-90 days')), date('Y-m-d'));
$storeId = scope_store($user, $_GET['store_id'] ?? null);
$sw = $storeId !== null ? ' AND store_id = ?' : '';
$sp = $storeId !== null ? [$storeId] : [];

$billedBefore = (float) db_value("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE customer_id = ? AND status <> 'Cancelled' AND invoice_date < ?$sw", array_merge([$id, $from], $sp));
$paidBefore = (float) db_value("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
    WHERE i.customer_id = ? AND i.status <> 'Cancelled' AND p.payment_date < ?" . ($storeId !== null ? ' AND i.store_id = ?' : ''), array_merge([$id, $from], $sp));
$opening = money($billedBefore - $paidBefore);

$entries = [];
foreach (db_all("SELECT invoice_id, invoice_number, invoice_date, total_amount, status FROM invoices WHERE customer_id = ? AND status <> 'Cancelled' AND invoice_date BETWEEN ? AND ?$sw", array_merge([$id, $from, $to], $sp)) as $i) {
    $entries[] = ['date' => $i['invoice_date'], 'type' => 'bill', 'ref' => $i['invoice_number'], 'invoice_id' => (int) $i['invoice_id'], 'debit' => money($i['total_amount']), 'credit' => 0.0, 'sort' => '1' . str_pad($i['invoice_id'], 10, '0', STR_PAD_LEFT)];
}
foreach (db_all("SELECT p.payment_id, p.payment_date, p.amount, p.payment_method, p.reference_no, i.invoice_number, i.invoice_id
    FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
    WHERE i.customer_id = ? AND i.status <> 'Cancelled' AND p.payment_date BETWEEN ? AND ?" . ($storeId !== null ? ' AND i.store_id = ?' : ''), array_merge([$id, $from, $to], $sp)) as $p) {
    $entries[] = ['date' => $p['payment_date'], 'type' => 'payment', 'ref' => trim("{$p['payment_method']} {$p['reference_no']}") . " (bill {$p['invoice_number']})", 'invoice_id' => (int) $p['invoice_id'],
        'debit' => 0.0, 'credit' => money($p['amount']), 'sort' => '2' . str_pad($p['payment_id'], 10, '0', STR_PAD_LEFT)];
}
usort($entries, fn($a, $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);
$bal = $opening;
foreach ($entries as &$e) {
    $bal = money($bal + $e['debit'] - $e['credit']);
    $e['balance'] = $bal;
    unset($e['sort']);
}
unset($e);
$open = array_map(fn($r) => cast_numbers($r, ['total_amount', 'amount_paid', 'balance_due'], ['invoice_id']), db_all(
    "SELECT invoice_id, invoice_number, invoice_date, total_amount, amount_paid, balance_due, DATEDIFF(CURDATE(), invoice_date) AS days
     FROM invoices WHERE customer_id = ? AND status <> 'Cancelled' AND balance_due > 0$sw ORDER BY invoice_date, invoice_id",
    array_merge([$id], $sp)
));
json_out(['status' => 'success', 'data' => [
    'customer' => cast_numbers($c, [], ['customer_id']),
    'from' => $from, 'to' => $to,
    'opening_balance' => $opening,
    'closing_balance' => $bal,
    'total_due' => money(array_sum(array_column($open, 'balance_due'))),
    'entries' => $entries,
    'open_bills' => $open,
]]);
