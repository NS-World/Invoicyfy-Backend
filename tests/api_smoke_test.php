<?php
/**
 * End-to-end API smoke test. Run against a THROWAWAY database:
 *
 *   DB_NAME=invoicyfy_test php database/migrate.php --admin-email=admin@test.com --admin-password=Admin@1234
 *   DB_NAME=invoicyfy_test php -S 127.0.0.1:8080 -t .
 *   php tests/api_smoke_test.php http://127.0.0.1:8080 admin@test.com Admin@1234
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
[$_, $base, $email, $password] = $argv + [null, 'http://127.0.0.1:8080', 'admin@test.com', 'Admin@1234'];
$base = rtrim($base, '/');
$token = null;
$failures = 0;
$checks = 0;

function call(string $method, string $path, ?array $body = null, bool $auth = true): array
{
    global $base, $token;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($auth && $token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        $json = ['_raw' => $raw];
    }
    return [$code, $json];
}

function check(string $label, bool $ok, $detail = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ✓ $label\n";
    } else {
        $failures++;
        echo "  ✗ $label\n";
        if ($detail !== null) {
            echo '      ' . substr(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600) . "\n";
        }
    }
}

echo "Auth\n";
[$c, $r] = call('POST', '/auth/signin.php', ['email' => $email, 'password' => 'wrong'], false);
check('wrong password rejected (401)', $c === 401, $r);
[$c, $r] = call('POST', '/master/customer/customers.php', null, false);
check('no token -> 401/405', in_array($c, [401, 405], true), $r);
[$c, $r] = call('POST', '/auth/signin.php', ['email' => $email, 'password' => $password], false);
check('login', $c === 200 && !empty($r['access_token']) && !empty($r['user']), $r);
$token = $r['access_token'];
$refresh = $r['refresh_token'];
[$c, $r] = call('POST', '/auth/refresh.php', ['refresh_token' => $refresh], false);
check('refresh token', $c === 200 && !empty($r['access_token']), $r);
[$c, $r] = call('GET', '/auth/me.php');
check('me', $c === 200 && $r['data']['role_name'] === 'Admin', $r);
$storeId = $r['data']['store_id'];

echo "Store settings\n";
[$c, $r] = call('PUT', '/master/store/update-store.php', [
    'store_id' => $storeId, 'name' => "Scoopie'z Icecream", 'tagline' => "soopie'z", 'address' => '12 Main Road', 'city' => 'Surat',
    'gstin' => '24ABCDE1234F1Z5', 'phone' => '9723423345', 'fssai_no' => '20724011000376', 'invoice_prefix' => 'SZ',
    'bank_name' => 'HDFC Bank', 'bank_account_no' => '50100012345678', 'bank_ifsc' => 'HDFC0001234', 'upi_id' => 'scoopiez@upi',
    'receipt_footer' => 'Thanks',
]);
check('update store with GSTIN', $c === 200, $r);
[$c, $r] = call('GET', "/master/store/get-store-by-id.php?store_id=$storeId");
check('state derived from GSTIN', ($r['data']['state_code'] ?? null) === '24' && $r['data']['state'] === 'Gujarat', $r);
[$c, $r] = call('PUT', '/master/store/update-store.php', ['store_id' => $storeId, 'name' => 'X', 'gstin' => 'BADGSTIN']);
check('invalid GSTIN rejected', $c === 400, $r);

echo "Customers\n";
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Walk In Ravi', 'phone' => '9723423345']);
check('add B2C customer', $c === 201, $r);
$b2c = $r['customer_id'];
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Acme Traders', 'customer_type' => 'B2B']);
check('B2B without GSTIN rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Acme Traders', 'customer_type' => 'B2B', 'gstin' => '27AAACA1234A1Z5', 'address' => 'Andheri', 'city' => 'Mumbai']);
check('add B2B customer (Maharashtra)', $c === 201 && $r['data']['state_code'] === '27', $r);
$b2b = $r['customer_id'];
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Dup', 'phone' => '9723423345']);
check('duplicate phone rejected', $c === 409, $r);

echo "Products\n";
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Roasted Dryfruit', 'price' => 230, 'gst_rate' => 5, 'price_includes_tax' => true, 'hsn_code' => '2105', 'unit' => 'pcs', 'cost_price' => 120, 'stock_qty' => 50, 'low_stock_threshold' => 5]);
check('create product with opening stock', $c === 201, $r);
$p1 = $r['product_id'];
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Ice Cream Tub 1L', 'price' => 400, 'gst_rate' => 18, 'hsn_code' => '210500', 'unit' => 'pcs', 'cost_price' => 250, 'stock_qty' => 10, 'low_stock_threshold' => 3]);
check('create product (tax exclusive)', $c === 201, $r);
$p2 = $r['product_id'];
[$c, $r] = call('GET', '/master/product/products.php?page=1&limit=10&search=');
check('list products', $c === 200 && $r['total'] === 2 && is_float($r['data'][0]['price']), $r);

echo "Orders\n";
[$c, $r] = call('POST', '/master/order/create-order.php', [
    'customer_id' => $b2c, 'store_id' => $storeId, 'order_type' => 'Delivery',
    'items' => [['product_id' => $p1, 'quantity' => 1, 'unit_price' => 230, 'item_note' => 'Dry Fruits: Pista - 1x50 = 50']],
]);
check('create order (inclusive GST)', $c === 201 && $r['data']['total_amount'] == 230.0, $r);
$o1 = $r['order_id'];
check('order number format', (bool) preg_match('#^ORD/\d\d-\d\d/0001$#', $r['order_number']), $r['order_number'] ?? null);
check('inclusive tax extracted', abs($r['data']['tax_amount'] - 10.95) < 0.01 && abs($r['data']['taxable_amount'] - 219.05) < 0.01, $r['data']);
[$c, $r] = call('PUT', '/master/order/update-order.php', [
    'order_id' => $o1, 'customer_id' => $b2c, 'order_type' => 'Delivery',
    'items' => [['product_id' => $p1, 'quantity' => 2, 'unit_price' => 230, 'item_note' => 'Dry Fruits: Pista']],
]);
check('update order replaces items', $c === 200 && $r['data']['total_amount'] == 460.0 && count($r['data']['items']) === 1, $r);
[$c, $r] = call('GET', "/master/order_items/get-order-items.php?order_id=$o1");
check('get order items', $c === 200 && $r['summary']['item_count'] === 1, $r);
[$c, $r] = call('PUT', '/master/order/update-order-status.php', ['order_id' => $o1, 'status' => 'approved']);
check('approve order', $c === 200 && $r['new_status'] === 'Approved', $r);
[$c, $r] = call('GET', '/master/order/orders.php?page=1&limit=10&search=&status=Approved');
check('list orders filtered', $c === 200 && $r['total'] === 1, $r);

echo "Retail bill from order (thermal receipt)\n";
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['order_id' => $o1, 'invoice_type' => 'retail', 'payment' => ['amount' => 460, 'payment_method' => 'UPI']]);
check('invoice from order with instant payment', $c === 201 && $r['data']['status'] === 'Paid', $r);
$inv1 = $r['invoice_id'];
check('retail bill number is plain sequence', ($r['invoice_number'] ?? null) === '1', $r['invoice_number'] ?? null);
[$c, $r] = call('GET', "/master/order/get-order-by-id.php?order_id=$o1");
check('order marked Invoiced', $r['order']['status'] === 'Invoiced' && $r['order']['invoice_id'] === $inv1, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['order_id' => $o1, 'invoice_type' => 'retail']);
check('cannot invoice twice', $c === 409, $r);
[$c, $r] = call('GET', "/master/product/find-product.php?product_id=$p1");
check('stock deducted (50 -> 48)', $r['data']['stock_qty'] == 48.0, $r['data']);

echo "B2B GST tax invoice (inter-state -> IGST)\n";
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', [
    'invoice_type' => 'tax', 'customer_id' => $b2b, 'store_id' => $storeId, 'due_date' => date('Y-m-d', strtotime('+15 days')),
    'items' => [
        ['product_id' => $p2, 'quantity' => 3, 'discount_amount' => 20],
        ['product_name' => 'Delivery charges', 'quantity' => 1, 'unit_price' => 100, 'gst_rate' => 18, 'hsn_code' => '996813'],
    ],
]);
check('create tax invoice', $c === 201, $r);
$inv2 = $r['invoice_id'];
$d = $r['data'] ?? [];
check('IGST applied for Maharashtra buyer', ($d['is_interstate'] ?? 0) === 1 && $d['igst_amount'] > 0 && $d['cgst_amount'] == 0.0, $d);
// (3*400 - 20) = 1180 + 100 = 1280 taxable, 18% = 230.40, total 1510.40 -> rounded 1510, round off -0.40
check('tax maths', $d['taxable_amount'] == 1280.0 && $d['tax_amount'] == 230.4 && $d['total_amount'] == 1510.0 && $d['round_off'] == -0.4, $d);
check('tax invoice number has FY + prefix', (bool) preg_match('#^SZ/\d\d-\d\d/0001$#', $d['invoice_number'] ?? ''), $d['invoice_number'] ?? null);
check('HSN summary present', count($d['hsn_summary'] ?? []) === 2, $d['hsn_summary'] ?? null);

echo "Intra-state tax invoice (CGST + SGST)\n";
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', [
    'invoice_type' => 'tax', 'customer_name' => 'Local Buyer', 'customer_gstin' => '24AAACL1234B1Z2', 'store_id' => $storeId,
    'items' => [['product_id' => $p2, 'quantity' => 1]],
]);
check('CGST/SGST split', $c === 201 && $r['data']['cgst_amount'] == 36.0 && $r['data']['sgst_amount'] == 36.0 && $r['data']['igst_amount'] == 0.0, $r['data'] ?? $r);
$inv3 = $r['invoice_id'];

echo "Payments\n";
[$c, $r] = call('POST', '/master/payment/create-payment.php', ['invoice_id' => $inv2, 'amount' => 5000, 'method' => 'Cash']);
check('over-payment rejected', $c === 422, $r);
[$c, $r] = call('POST', '/master/payment/create-payment.php', ['invoice_id' => $inv2, 'amount' => 1000, 'payment_method' => 'Bank Transfer', 'reference_no' => 'UTR123']);
check('partial payment', $c === 201 && $r['invoice']['status'] === 'Partial' && $r['invoice']['balance_due'] == 510.0, $r);
$pay = $r['payment_id'];
[$c, $r] = call('GET', '/master/payment/payments.php?page=1&limit=10');
check('list payments', $c === 200 && $r['total'] === 2, $r);
[$c, $r] = call('DELETE', '/master/payment/delete-payment.php', ['payment_id' => $pay]);
check('delete payment', $c === 200, $r);
[$c, $r] = call('GET', "/master/invoice/get-invoice-by-id.php?invoice_id=$inv2");
check('invoice back to Unpaid', $r['data']['status'] === 'Unpaid' && $r['data']['balance_due'] == 1510.0, $r['data']);
[$c, $r] = call('GET', '/master/invoice/invoices.php?page=1&limit=10&status=Due');
check('list due invoices', $c === 200 && $r['total'] === 2, $r);

echo "Cancel invoice\n";
[$c, $r] = call('POST', '/master/invoice/cancel-invoice.php', ['invoice_id' => $inv3, 'reason' => 'Wrong buyer']);
check('cancel invoice', $c === 200, $r);
[$c, $r] = call('GET', "/master/product/find-product.php?product_id=$p2");
check('stock restored after cancel (10-3-1+1 = 7)', $r['data']['stock_qty'] == 7.0, $r['data']);

echo "E-invoice details\n";
[$c, $r] = call('POST', '/master/invoice/update-einvoice.php', ['invoice_id' => $inv2, 'irn' => str_repeat('ab', 32), 'ack_no' => '112010036563310', 'ack_date' => date('Y-m-d H:i:s')]);
check('save IRN', $c === 200 && $r['data']['irn'] === str_repeat('ab', 32), $r);
[$c, $r] = call('POST', '/master/invoice/cancel-invoice.php', ['invoice_id' => $inv2, 'reason' => 'x']);
check('cannot cancel invoice with IRN', $c === 409, $r);

echo "Inventory\n";
[$c, $r] = call('POST', '/master/inventory/adjust-stock.php', ['product_id' => $p2, 'type' => 'purchase', 'quantity' => 20, 'unit_cost' => 240, 'record_expense' => true, 'vendor' => 'Amul']);
check('purchase stock + expense', $c === 200 && $r['stock_qty'] == 27.0 && $r['expense_id'], $r);
[$c, $r] = call('POST', '/master/inventory/adjust-stock.php', ['product_id' => $p2, 'type' => 'damage', 'quantity' => 2, 'notes' => 'Melted']);
check('damage reduces stock', $c === 200 && $r['stock_qty'] == 25.0, $r);
[$c, $r] = call('GET', '/master/inventory/stock.php?page=1&limit=10');
check('stock list', $c === 200 && $r['summary']['products'] === 2, $r);
[$c, $r] = call('GET', "/master/inventory/stock-movements.php?product_id=$p2");
check('stock movements logged', $c === 200 && $r['total'] >= 5, $r);

echo "Expenses\n";
[$c, $r] = call('POST', '/master/expense/add-expense.php', ['category' => 'Rent', 'amount' => 15000, 'payment_method' => 'Bank Transfer']);
check('add expense', $c === 201, $r);
$exp = $r['expense_id'];
[$c, $r] = call('PUT', '/master/expense/update-expense.php', ['expense_id' => $exp, 'category' => 'Rent', 'amount' => 12000, 'payment_method' => 'UPI']);
check('update expense', $c === 200, $r);
[$c, $r] = call('GET', '/master/expense/expenses.php?page=1&limit=10');
check('list expenses with summary', $c === 200 && $r['summary']['total_amount'] == 16800.0, $r['summary'] ?? $r);
[$c, $r] = call('POST', '/master/expense/add-expense.php', ['category' => 'Rent', 'amount' => -5]);
check('negative expense rejected', $c === 400, $r);

echo "Reports\n";
[$c, $r] = call('GET', '/reports/dashboard.php');
check('dashboard', $c === 200 && $r['kpis']['total_sales'] == 1970.0 && $r['kpis']['collected'] == 460.0 && count($r['monthly']) === 12, $r['kpis'] ?? $r);
[$c, $r] = call('GET', '/reports/gst-summary.php');
check('gst summary', $c === 200 && count($r['b2b']) === 1 && $r['totals']['cancelled_invoices'] === 1, $r['totals'] ?? $r);
[$c, $r] = call('GET', '/reports/profit-loss.php');
check('profit & loss', $c === 200 && isset($r['totals']['profit']), $r['totals'] ?? $r);
foreach (['day', 'month', 'product', 'customer'] as $g) {
    [$c, $r] = call('GET', "/reports/sales.php?group=$g");
    check("sales report by $g", $c === 200 && count($r['data']) >= 1, $r);
}
[$c, $r] = call('GET', '/master/settings/meta.php');
check('meta lookups', $c === 200 && count($r['data']['states']) > 30, $r);

echo "Users & permissions\n";
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'Biller', 'email' => 'biller@test.com', 'password' => 'Biller@123', 'role_id' => 3, 'store_id' => $storeId]);
check('create staff user', $c === 201, $r);
$adminToken = $token;
[$c, $r] = call('POST', '/auth/signin.php', ['email' => 'biller@test.com', 'password' => 'Biller@123'], false);
$token = $r['access_token'] ?? null;
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'X', 'email' => 'x@test.com', 'password' => 'Xxxxxxxx1', 'role_id' => 1, 'store_id' => $storeId]);
check('staff cannot create users (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/invoice/invoices.php?page=1&limit=5');
check('staff can list invoices', $c === 200, $r);
$token = $adminToken;
[$c, $r] = call('DELETE', '/master/customer/delete-customer.php', ['customer_id' => $b2b]);
check('cannot delete customer with invoices', $c === 409, $r);
[$c, $r] = call('PUT', '/master/product/delete-product.php', ['product_id' => $p1]);
check('used product deactivated not deleted', $c === 200 && str_contains($r['message'], 'Inactive'), $r);

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
