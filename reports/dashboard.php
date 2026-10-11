<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

[$from, $to] = date_range();
[$si, $sp] = store_filter($user, 'i');
[$sp_, $spp] = store_filter($user, 'p');
[$se, $sep] = store_filter($user, 'e');
[$so, $sop] = store_filter($user, 'o');
[$spr, $sprp] = store_filter($user, 'pr');

$sales = cast_numbers(db_one(
    "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(i.total_amount),0) AS total_sales, COALESCE(SUM(i.taxable_amount),0) AS net_sales,
            COALESCE(SUM(i.tax_amount),0) AS tax_collected, COALESCE(SUM(i.balance_due),0) AS period_due
     FROM invoices i WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si",
    array_merge([$from, $to], $sp)
), ['total_sales', 'net_sales', 'tax_collected', 'period_due'], ['invoice_count']);

$collected = (float) db_value(
    "SELECT COALESCE(SUM(p.amount),0) FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
     WHERE (i.status IS NULL OR i.status <> 'Cancelled') AND p.payment_date BETWEEN ? AND ? $sp_",
    array_merge([$from, $to], $spp)
);
$expenses = cast_numbers(db_one(
    "SELECT COALESCE(SUM(e.amount),0) AS total, COALESCE(SUM(e.tax_amount),0) AS tax FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se",
    array_merge([$from, $to], $sep)
), ['total', 'tax']);
$cogs = (float) db_value(
    "SELECT COALESCE(SUM(ii.quantity * ii.cost_price),0) FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si",
    array_merge([$from, $to], $sp)
);
$outstanding = cast_numbers(db_one(
    "SELECT COALESCE(SUM(i.balance_due),0) AS total,
            COALESCE(SUM(CASE WHEN i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN i.balance_due END),0) AS overdue,
            SUM(i.balance_due > 0) AS invoices
     FROM invoices i WHERE i.status IN ('Unpaid','Partial') $si",
    $sp
), ['total', 'overdue'], ['invoices']);

$counts = [
    'customers' => (int) db_value("SELECT COUNT(*) FROM customers"),
    'products' => (int) db_value("SELECT COUNT(*) FROM products pr WHERE pr.status = 'Active' $spr", $sprp),
    'open_orders' => (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.status IN ('Pending','Approved') $so", $sop),
    'low_stock' => (int) db_value("SELECT COUNT(*) FROM products pr WHERE pr.track_inventory = 1 AND pr.status = 'Active' AND pr.stock_qty <= pr.low_stock_threshold $spr", $sprp),
];

// Last 12 months ending with the "to" month.
$months = [];
$end = new DateTime(substr($to, 0, 7) . '-01');
for ($k = 11; $k >= 0; $k--) {
    $m = (clone $end)->modify("-$k month")->format('Y-m');
    $months[$m] = ['month' => $m, 'sales' => 0.0, 'collected' => 0.0, 'expenses' => 0.0];
}
$start12 = array_key_first($months) . '-01';
$end12 = (clone $end)->modify('last day of this month')->format('Y-m-d');
foreach (db_all("SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS m, SUM(i.total_amount) AS v FROM invoices i WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si GROUP BY m", array_merge([$start12, $end12], $sp)) as $r) {
    $months[$r['m']]['sales'] = (float) $r['v'];
}
foreach (db_all("SELECT DATE_FORMAT(p.payment_date,'%Y-%m') AS m, SUM(p.amount) AS v FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id WHERE (i.status IS NULL OR i.status <> 'Cancelled') AND p.payment_date BETWEEN ? AND ? $sp_ GROUP BY m", array_merge([$start12, $end12], $spp)) as $r) {
    $months[$r['m']]['collected'] = (float) $r['v'];
}
foreach (db_all("SELECT DATE_FORMAT(e.expense_date,'%Y-%m') AS m, SUM(e.amount) AS v FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY m", array_merge([$start12, $end12], $sep)) as $r) {
    $months[$r['m']]['expenses'] = (float) $r['v'];
}

$daily = [];
if ((strtotime($to) - strtotime($from)) / 86400 <= 62) {
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        $daily[date('Y-m-d', $d)] = ['date' => date('Y-m-d', $d), 'sales' => 0.0, 'expenses' => 0.0];
    }
    foreach (db_all("SELECT i.invoice_date AS d, SUM(i.total_amount) AS v FROM invoices i WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si GROUP BY d", array_merge([$from, $to], $sp)) as $r) {
        $daily[$r['d']]['sales'] = (float) $r['v'];
    }
    foreach (db_all("SELECT e.expense_date AS d, SUM(e.amount) AS v FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $se GROUP BY d", array_merge([$from, $to], $sep)) as $r) {
        $daily[$r['d']]['expenses'] = (float) $r['v'];
    }
}

$statusBreakdown = f2(db_all(
    "SELECT i.status, COUNT(*) AS count, SUM(i.total_amount) AS amount FROM invoices i WHERE i.invoice_date BETWEEN ? AND ? $si GROUP BY i.status",
    array_merge([$from, $to], $sp)
), ['amount'], ['count']);
$topProducts = f2(db_all(
    "SELECT COALESCE(ii.product_name, 'Item') AS name, SUM(ii.quantity) AS quantity, SUM(ii.line_total) AS revenue
     FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si
     GROUP BY ii.product_id, ii.product_name ORDER BY revenue DESC LIMIT 5",
    array_merge([$from, $to], $sp)
), ['quantity', 'revenue']);
$byMethod = f2(db_all(
    "SELECT p.payment_method, SUM(p.amount) AS amount FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
     WHERE (i.status IS NULL OR i.status <> 'Cancelled') AND p.payment_date BETWEEN ? AND ? $sp_ GROUP BY p.payment_method ORDER BY amount DESC",
    array_merge([$from, $to], $spp)
), ['amount']);
$recent = array_map('doc_cast', db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_type, i.customer_name, i.invoice_date, i.total_amount, i.balance_due, i.status
     FROM invoices i WHERE 1=1 $si ORDER BY i.invoice_id DESC LIMIT 6",
    $sp
));
$topCustomers = f2(db_all(
    "SELECT COALESCE(i.customer_name,'Walk-in') AS name, COUNT(*) AS invoices, SUM(i.total_amount) AS amount
     FROM invoices i WHERE i.status <> 'Cancelled' AND i.invoice_date BETWEEN ? AND ? $si
     GROUP BY i.customer_id, i.customer_name ORDER BY amount DESC LIMIT 5",
    array_merge([$from, $to], $sp)
), ['amount'], ['invoices']);

$netExpenses = round($expenses['total'] - $expenses['tax'], 2);
json_out([
    "status" => "success",
    "range" => ["from" => $from, "to" => $to],
    "kpis" => [
        "total_sales" => $sales['total_sales'],
        "net_sales" => $sales['net_sales'],
        "tax_collected" => $sales['tax_collected'],
        "invoice_count" => $sales['invoice_count'],
        "average_bill" => $sales['invoice_count'] ? round($sales['total_sales'] / $sales['invoice_count'], 2) : 0,
        "collected" => round($collected, 2),
        "expenses" => $expenses['total'],
        "cost_of_goods" => round($cogs, 2),
        "gross_profit" => round($sales['net_sales'] - $cogs, 2),
        "net_profit" => round($sales['net_sales'] - $netExpenses, 2),
        "outstanding" => $outstanding['total'],
        "overdue" => $outstanding['overdue'],
        "due_invoices" => $outstanding['invoices'] ?? 0,
    ],
    "counts" => $counts,
    "monthly" => array_values($months),
    "daily" => array_values($daily),
    "status_breakdown" => $statusBreakdown,
    "top_products" => $topProducts,
    "top_customers" => $topCustomers,
    "payment_methods" => $byMethod,
    "recent_invoices" => $recent,
]);
