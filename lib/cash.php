<?php
/**
 * Day close / cash register. A session runs from "Open day" (opening cash in the drawer) to "Close day"
 * (cash counted). Payments are tagged with the session that was open when they were received, so the
 * expected cash is: opening cash + cash received + pay-ins - pay-outs.
 */

const CASH_DENOMINATIONS = [2000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

function cash_session_row(array $s): array
{
    $s = cast_numbers($s, ['opening_cash', 'expected_cash', 'counted_cash', 'difference'], ['session_id', 'store_id', 'opened_by', 'closed_by']);
    unset($s['open_lock']);
    $s['denominations'] = $s['denominations'] ? json_decode($s['denominations'], true) : null;
    unset($s['summary']);
    return $s;
}

/** Live (or final) figures for a session: payments by mode, bills, discounts, pay in/out and expected cash. */
function cash_session_report(array $s): array
{
    $id = (int) $s['session_id'];
    $storeId = (int) $s['store_id'];
    $from = $s['opened_at'];
    $to = $s['closed_at'] ?: date('Y-m-d H:i:s');

    $byMethod = [];
    foreach (db_all('SELECT payment_method, COUNT(*) AS n, SUM(amount) AS amount FROM payments WHERE cash_session_id = ? GROUP BY payment_method ORDER BY amount DESC', [$id]) as $r) {
        $byMethod[] = ['method' => $r['payment_method'], 'count' => (int) $r['n'], 'amount' => money($r['amount'])];
    }
    $cashIn = money(array_sum(array_map(fn($m) => $m['method'] === 'Cash' ? $m['amount'] : 0, $byMethod)));
    $received = money(array_sum(array_column($byMethod, 'amount')));

    $moves = array_map(fn($m) => cast_numbers($m, ['amount'], ['movement_id', 'user_id']), db_all(
        'SELECT m.movement_id, m.type, m.amount, m.reason, m.created_at, m.user_id, u.name AS user_name
         FROM cash_movements m LEFT JOIN users u ON u.user_id = m.user_id WHERE m.session_id = ? ORDER BY m.movement_id', [$id]
    ));
    $payIn = money(array_sum(array_map(fn($m) => $m['type'] === 'in' ? $m['amount'] : 0, $moves)));
    $payOut = money(array_sum(array_map(fn($m) => $m['type'] === 'out' ? $m['amount'] : 0, $moves)));

    $bills = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(discount_amount),0) AS discount,
                COALESCE(SUM(tax_amount),0) AS tax, COALESCE(SUM(balance_due),0) AS credit
         FROM invoices WHERE store_id = ? AND status <> 'Cancelled' AND created_at >= ? AND created_at <= ?",
        [$storeId, $from, $to]
    );
    $cancelled = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total FROM invoices
         WHERE store_id = ? AND status = 'Cancelled' AND cancelled_at >= ? AND cancelled_at <= ?",
        [$storeId, $from, $to]
    );
    try {
        $extra = db_one(
            "SELECT COALESCE(SUM(coupon_discount),0) AS coupon, COALESCE(SUM(loyalty_discount),0) AS loyalty
             FROM invoices WHERE store_id = ? AND status <> 'Cancelled' AND created_at >= ? AND created_at <= ?",
            [$storeId, $from, $to]
        );
    } catch (PDOException $e) {
        $extra = ['coupon' => 0, 'loyalty' => 0];
    }
    $byType = array_map(fn($r) => ['order_type' => $r['order_type'] ?: 'Counter', 'count' => (int) $r['n'], 'total' => money($r['total'])], db_all(
        "SELECT order_type, COUNT(*) AS n, SUM(total_amount) AS total FROM invoices
         WHERE store_id = ? AND status <> 'Cancelled' AND created_at >= ? AND created_at <= ? GROUP BY order_type ORDER BY total DESC",
        [$storeId, $from, $to]
    ));
    $topItems = array_map(fn($r) => ['product_name' => $r['product_name'], 'unit' => $r['unit'], 'quantity' => round((float) $r['qty'], 3), 'amount' => money($r['amount'])], db_all(
        "SELECT ii.product_name, ii.unit, SUM(ii.quantity) AS qty, SUM(ii.line_total) AS amount
         FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
         WHERE i.store_id = ? AND i.status <> 'Cancelled' AND i.created_at >= ? AND i.created_at <= ?
         GROUP BY ii.product_name, ii.unit ORDER BY amount DESC LIMIT 10",
        [$storeId, $from, $to]
    ));
    $expected = money((float) $s['opening_cash'] + $cashIn + $payIn - $payOut);

    return [
        'from' => $from,
        'to' => $to,
        'opening_cash' => money($s['opening_cash']),
        'cash_sales' => $cashIn,
        'pay_in' => $payIn,
        'pay_out' => $payOut,
        'expected_cash' => $expected,
        'received_total' => $received,
        'payments_by_method' => $byMethod,
        'movements' => $moves,
        'bills' => [
            'count' => (int) $bills['n'],
            'total' => money($bills['total']),
            'discount' => money($bills['discount']),
            'coupon_discount' => money($extra['coupon']),
            'loyalty_discount' => money($extra['loyalty']),
            'tax' => money($bills['tax']),
            'credit_given' => money($bills['credit']),
        ],
        'cancelled' => ['count' => (int) $cancelled['n'], 'total' => money($cancelled['total'])],
        'by_order_type' => $byType,
        'top_items' => $topItems,
    ];
}

function load_cash_session(array $user, int $id, bool $lock = false): array
{
    $s = db_one(
        'SELECT cs.*, uo.name AS opened_by_name, uc.name AS closed_by_name, st.name AS store_name
         FROM cash_sessions cs LEFT JOIN users uo ON uo.user_id = cs.opened_by LEFT JOIN users uc ON uc.user_id = cs.closed_by
         LEFT JOIN stores st ON st.store_id = cs.store_id WHERE cs.session_id = ?' . ($lock ? ' FOR UPDATE' : ''),
        [$id]
    );
    if (!$s) {
        fail('Day / cash session not found', 404);
    }
    assert_store_access($user, $s['store_id']);
    return $s;
}

/** Session + report (saved snapshot for closed days, live figures for the open one). */
function cash_session_full(array $s): array
{
    $report = $s['status'] === 'Closed' && $s['summary'] ? json_decode($s['summary'], true) : cash_session_report($s);
    return cash_session_row($s) + ['report' => $report];
}

function clean_denominations($raw): ?array
{
    if (!is_array($raw) || !$raw) {
        return null;
    }
    $out = [];
    foreach (CASH_DENOMINATIONS as $note) {
        $n = $raw[$note] ?? $raw[(string) $note] ?? 0;
        if ($n === '' || $n === null) {
            $n = 0;
        }
        if (!is_numeric($n) || (int) $n < 0 || (int) $n != $n || (int) $n > 100000) {
            fail("Count for ₹$note must be a whole number");
        }
        if ((int) $n > 0) {
            $out[(string) $note] = (int) $n;
        }
    }
    return $out ?: null;
}
