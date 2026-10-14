<?php
/** GET ?supplier_id= — supplier with balance and a statement (bills and payments, newest first). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

$s = load_supplier($user, (int) ($_GET['supplier_id'] ?? 0));
$id = (int) $s['supplier_id'];
$bills = db_all(
    "SELECT purchase_id AS id, 'bill' AS type, purchase_date AS date, bill_no AS reference, total_amount AS amount, balance_due, status
     FROM purchases WHERE supplier_id = ? ORDER BY purchase_date DESC, purchase_id DESC LIMIT 300",
    [$id]
);
$payments = db_all(
    "SELECT sp.payment_id AS id, 'payment' AS type, sp.payment_date AS date, COALESCE(sp.reference_no, p.bill_no) AS reference, sp.amount,
            sp.payment_method, sp.notes, p.bill_no
     FROM supplier_payments sp LEFT JOIN purchases p ON p.purchase_id = sp.purchase_id
     WHERE sp.supplier_id = ? ORDER BY sp.payment_date DESC, sp.payment_id DESC LIMIT 300",
    [$id]
);
$entries = array_merge($bills, $payments);
usort($entries, fn($a, $b) => strcmp($b['date'], $a['date']) ?: ($a['type'] === 'bill' ? 1 : -1));
$entries = array_map(fn($e) => cast_numbers($e, ['amount', 'balance_due'], ['id']), $entries);
json_out(['status' => 'success', 'data' => supplier_row($s) + supplier_balance($id) + ['entries' => $entries]]);
