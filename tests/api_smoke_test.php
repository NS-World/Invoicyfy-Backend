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

echo "Weight-based billing\n";
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Loose Vanilla Ice Cream', 'price' => 400, 'unit' => 'kg', 'gst_rate' => 18, 'price_includes_tax' => true, 'weight_presets' => '1000, 100,250,500,750', 'stock_qty' => 10]);
check('create per-kg product with gram presets', $c === 201, $r);
$loose = $r['product_id'];
[$c, $r] = call('GET', "/master/product/find-product.php?product_id=$loose");
check('presets normalised (sorted grams)', ($r['data']['weight_presets'] ?? '') === '100,250,500,750,1000', $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $loose, 'quantity' => 0.25], ['product_id' => $loose, 'quantity' => 0.75]]]);
check('250 g + 750 g at Rs 400/kg = Rs 400', $c === 201 && $r['data']['total_amount'] == 400.0 && $r['data']['items'][0]['line_total'] == 100.0, $r['data'] ?? $r);
[$c, $r] = call('GET', "/master/product/find-product.php?product_id=$loose");
check('stock reduced by 1 kg', $r['data']['stock_qty'] == 9.0, $r['data']);

echo "Invoice design & logo\n";
$png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
[$c, $r] = call('POST', '/master/store/update-invoice-settings.php', ['store_id' => $storeId, 'logo' => $png, 'invoice_settings' => [
    'a4_template' => 'modern', 'accent_color' => '#aa3366', 'show_hsn_summary' => false, 'title_tax' => 'GST INVOICE', 'unknown_key' => 1,
    'qr_codes' => [['label' => 'Review us on Google', 'url' => 'https://g.page/r/demo/review', 'show_on' => 'both'], ['label' => 'empty']],
]]);
$saved = json_decode($r['data']['invoice_settings'] ?? '{}', true);
check('save invoice design', $c === 200 && $saved['a4_template'] === 'modern' && count($saved['qr_codes']) === 1 && !isset($saved['unknown_key']) && $r['data']['logo'] === $png, $r);
[$c, $r] = call('POST', '/master/store/update-invoice-settings.php', ['store_id' => $storeId, 'invoice_settings' => ['accent_color' => 'red']]);
check('bad colour rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/store/update-invoice-settings.php', ['store_id' => $storeId, 'invoice_settings' => ['qr_codes' => [['label' => 'x', 'url' => 'javascript:alert(1)']]]]);
check('unsafe QR link rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/store/update-invoice-settings.php', ['store_id' => $storeId, 'logo' => 'data:image/png;base64,AAAA', 'invoice_settings' => []]);
check('invalid logo rejected', $c === 400, $r);
[$c, $r] = call('PUT', '/master/store/update-store.php', ['store_id' => $storeId, 'name' => "Scoopie'z Icecream", 'gstin' => '24ABCDE1234F1Z5']);
[$c, $r] = call('GET', "/master/store/get-store-by-id.php?store_id=$storeId");
check('business update keeps logo', $r['data']['logo'] === $png, $r['data']['logo'] ?? null);

echo "Role permissions\n";
[$c, $r] = call('GET', '/master/role/roles.php?page=1&limit=10');
check('roles list with permission modules', $c === 200 && count($r['modules']) >= 10 && $r['data'][0]['is_system'] === true, $r);
[$c, $r] = call('POST', '/master/role/add-role.php', ['role_name' => 'Counter Biller', 'permissions' => ['invoices' => ['add'], 'expenses' => ['view'], 'bogus' => ['view']]]);
check('create custom role', $c === 201, $r);
$customRole = $r['role_id'];
[$c, $r] = call('GET', "/master/role/get-role-data-by-id.php?role_id=$customRole");
check('view implied + unknown modules dropped', $r['data']['permissions'] === ['invoices' => ['view', 'add'], 'expenses' => ['view']], $r['data']);
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'Counter One', 'email' => 'counter@test.com', 'password' => 'Counter@123', 'role_id' => $customRole, 'store_id' => $storeId]);
$adminToken = $token;
[$c, $r] = call('POST', '/auth/signin.php', ['email' => 'counter@test.com', 'password' => 'Counter@123'], false);
check('login returns permissions', ($r['user']['permissions']['invoices'] ?? null) === ['view', 'add'], $r['user'] ?? $r);
$token = $r['access_token'];
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'items' => [['product_id' => $loose, 'quantity' => 0.5]]]);
check('custom role can bill', $c === 201, $r);
$counterInv = $r['invoice_id'] ?? 0;
[$c, $r] = call('POST', '/master/invoice/cancel-invoice.php', ['invoice_id' => $counterInv, 'reason' => 'x']);
check('custom role cannot cancel (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/expense/expenses.php');
check('custom role can view expenses', $c === 200, $r);
[$c, $r] = call('POST', '/master/expense/add-expense.php', ['category' => 'Rent', 'amount' => 10]);
check('custom role cannot add expense (403)', $c === 403, $r);
[$c, $r] = call('GET', '/reports/profit-loss.php');
check('custom role cannot see reports (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/order/orders.php');
check('custom role cannot see orders (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/product/products.php?search=Loose');
check('billing lookups still open', $c === 200, $r);
[$c, $r] = call('POST', '/master/store/update-invoice-settings.php', ['invoice_settings' => []]);
check('custom role cannot change invoice design (403)', $c === 403, $r);
$token = $adminToken;
[$c, $r] = call('PUT', '/master/role/update-role.php', ['role_id' => $customRole, 'role_name' => 'Counter Biller', 'permissions' => ['invoices' => ['view', 'add'], 'users' => ['view', 'edit']]]);
check('admin updates role permissions', $c === 200, $r);
[$c, $r] = call('POST', '/auth/signin.php', ['email' => 'counter@test.com', 'password' => 'Counter@123'], false);
$token = $r['access_token'];
[$c, $r] = call('PUT', '/master/role/update-role.php', ['role_id' => $customRole, 'role_name' => 'Counter Biller', 'permissions' => ['reports' => ['view']]]);
check('cannot edit own role permissions (403)', $c === 403, $r);
[$c, $r] = call('PUT', '/master/user/update-user.php', ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@test.com', 'role_id' => 1, 'store_id' => $storeId]);
check('non-admin cannot edit an Admin (403)', $c === 403, $r);
$token = $adminToken;

echo "\nAnnual report\n";
[$c, $r] = call('GET', '/reports/annual.php');
check('annual report for current FY', $c === 200 && count($r['monthly'] ?? []) === 12 && isset($r['totals']['sales'], $r['previous'], $r['top_products']), $r);
check('annual months start in April', substr($r['monthly'][0]['month'] ?? '', 5) === '04', $r['monthly'][0] ?? null);
[$c, $r] = call('GET', '/reports/annual.php?fy=1999');
check('invalid FY rejected', $c === 400, $r);

echo "\nOffers & messages\n";
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'No Offers Please', 'phone' => '9811122233', 'marketing_opt_in' => false]);
check('customer can opt out of offers', $c === 201, $r);
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Offer Fan', 'phone' => '98111 22244']);
$fanId = $r['customer_id'] ?? 0;
[$c, $r] = call('GET', '/master/campaign/audience.php?activity=all');
check('audience excludes opted-out customers', $c === 200 && $r['data']['opted_out'] >= 1 && !in_array('919811122233', array_column($r['data']['sample'], 'phone'), true), $r);
[$c, $r] = call('POST', '/master/campaign/create-campaign.php', ['name' => 'API test', 'message' => 'Hi {name}', 'channel' => 'api', 'audience' => ['activity' => 'all']]);
check('API campaign refused until WhatsApp is set up', $c === 400, $r);
[$c, $r] = call('POST', '/master/campaign/update-whatsapp-settings.php', ['wa_business_number' => '9825012345', 'wa_phone_number_id' => '1234567890', 'wa_access_token' => 'EAAG-test-token-1234567890', 'wa_template_name' => 'shop_offer']);
check('save WhatsApp settings', $c === 200 && $r['data']['api_ready'] === true && $r['data']['wa_business_number'] === '919825012345', $r);
check('access token never returned', !str_contains(json_encode($r), 'EAAG-test'), $r);
[$c, $r] = call('POST', '/master/campaign/update-whatsapp-settings.php', ['wa_template_name' => 'Bad Name!']);
check('invalid template name rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/campaign/create-campaign.php', ['name' => 'Weekend offer', 'message' => 'Hi {name}, 20% off at {shop}!', 'channel' => 'manual', 'audience' => ['activity' => 'all']]);
check('create manual campaign', $c === 201 && $r['data']['total'] >= 1, $r);
$campaignId = $r['data']['campaign_id'] ?? 0;
[$c, $r] = call('GET', "/master/campaign/get-campaign.php?campaign_id=$campaignId&limit=100");
$fan = array_values(array_filter($r['recipients']['data'] ?? [], fn($x) => $x['customer_id'] === $fanId))[0] ?? null;
check('recipient gets personalised text + normalised phone', $fan && $fan['phone'] === '919811122244' && str_starts_with($fan['text'], 'Hi Offer Fan, 20% off at'), $fan);
[$c, $r] = call('POST', '/master/campaign/mark-sent.php', ['recipient_id' => $fan['recipient_id'] ?? 0]);
check('mark recipient as sent', $c === 200 && $r['data']['sent'] === 1, $r);
[$c, $r] = call('GET', '/master/campaign/campaigns.php');
check('campaign history', $c === 200 && $r['total'] >= 1, $r);

echo "\nBackup & restore\n";
[$c, $r] = call('GET', '/master/backup/tables.php');
check('backup table list', $c === 200 && array_column($r['data']['tables'], 'table')[0] === 'roles', $r);
[$c, $r] = call('GET', '/master/backup/export.php?table=customers&limit=500');
check('export customers', $c === 200 && $r['total'] >= 2 && in_array('marketing_opt_in', $r['columns'], true), $r);
$rows = array_map(fn($x, $i) => $x + ['__row' => $i + 2], $r['data'], array_keys($r['data']));
[$c, $r] = call('GET', '/master/backup/export.php?table=stores');
check('store export hides WhatsApp token', !in_array('wa_access_token', $r['columns'] ?? [], true), $r['columns'] ?? $r);
[$c, $r] = call('POST', '/master/backup/import.php', ['table' => 'customers', 'rows' => $rows]);
check('re-importing the same data adds nothing', $c === 200 && $r['counts']['inserted'] === 0 && $r['counts']['duplicate'] === count($rows), $r);
check('duplicates reported per row', count($r['issues']) === count($rows) && $r['issues'][0]['row'] === 2, $r['issues'][0] ?? null);
[$c, $r] = call('POST', '/master/backup/import.php', ['table' => 'customers', 'rows' => [
    ['__row' => 2, 'name' => 'Restored Customer', 'phone' => '9822200011', 'customer_type' => 'B2C'],
    ['__row' => 3, 'name' => 'Same Phone', 'phone' => '9822200011'],
    ['__row' => 4, 'customer_id' => 'abc', 'name' => 'Bad id'],
    ['__row' => 5, 'phone' => '9822200099'],
]]);
check('new row added, duplicate + invalid rows reported', $r['counts'] === ['inserted' => 1, 'merged' => 0, 'duplicate' => 1, 'error' => 2], $r);
check('error reason names the column', str_contains($r['issues'][1]['message'] ?? '', 'customer_id') && str_contains($r['issues'][2]['message'] ?? '', 'name'), $r['issues']);
[$c, $r] = call('POST', '/master/backup/import.php', ['table' => 'payments', 'rows' => [['__row' => 2, 'invoice_id' => 999999, 'amount' => 10, 'payment_date' => '2026-04-01']]]);
check('missing linked record explained', ($r['counts']['error'] ?? 0) === 1 && str_contains($r['issues'][0]['message'], 'invoice_id'), $r);
[$c, $r] = call('POST', '/master/backup/import.php', ['table' => 'nope', 'rows' => []]);
check('unknown table rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/backup/add-log.php', ['action' => 'export', 'file_name' => 'test.xlsx', 'summary' => ['customers' => 3]]);
check('backup logged', $c === 200, $r);
[$c, $r] = call('POST', '/auth/signin.php', ['email' => 'counter@test.com', 'password' => 'Counter@123'], false);
$token = $r['access_token'];
[$c, $r] = call('GET', '/master/backup/export.php?table=users');
check('non-admin cannot download backup (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/campaign/campaigns.php');
check('non-marketing role cannot see offers (403)', $c === 403, $r);
$token = $adminToken;

echo "\nCategories\n";
[$c, $r] = call('POST', '/master/category/add-category.php', ['name' => 'Loose Shakes', 'default_unit' => 'ltr', 'gst_rate' => 5, 'weight_presets' => '250,500,1000']);
check('add category with default unit', $c === 201 && $r['data']['default_unit'] === 'ltr', $r);
$catId = $r['category_id'] ?? 0;
[$c, $r] = call('POST', '/master/category/add-category.php', ['name' => 'Loose Shakes']);
check('duplicate category refused', $c === 409, $r);
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Mango Shake Loose', 'price' => 200, 'unit' => 'ltr', 'category' => 'Loose Shakes']);
check('product in category', $c === 201, $r);
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Cone Pack', 'price' => 20, 'unit' => 'pack', 'category' => 'Cones New']);
[$c, $r] = call('GET', '/master/category/categories.php?search=Cones');
check('unknown category on a product is added to the master', ($r['data'][0]['name'] ?? '') === 'Cones New' && $r['data'][0]['default_unit'] === 'pack', $r);
[$c, $r] = call('POST', '/master/category/update-category.php', ['category_id' => $catId, 'name' => 'Loose Milkshakes', 'default_unit' => 'ltr']);
[$c, $r] = call('GET', '/master/product/products.php?search=Mango');
check('renaming a category renames it on products', ($r['data'][0]['category'] ?? '') === 'Loose Milkshakes', $r['data'][0] ?? $r);
[$c, $r] = call('POST', '/master/category/delete-category.php', ['category_id' => $catId]);
check('category with products cannot be deleted', $c === 409, $r);
[$c, $r] = call('GET', '/master/product/products.php?sort=popular&limit=5');
check('popular products list', $c === 200 && array_key_exists('sold_lines', $r['data'][0] ?? []), $r);

echo "\nInvoice layout\n";
[$c, $r] = call('POST', '/master/store/update-invoice-settings.php', ['invoice_settings' => [
    'receipt_font_size' => 99, 'receipt_col_qty' => 8, 'receipt_separator' => 'dashed', 'receipt_show_price_col' => false,
    'custom_blocks' => [['text' => "🎉 Thank you! <b>Come again</b>", 'position' => 'after_totals', 'align' => 'center', 'size' => 'large', 'bold' => true], ['text' => '   '], ['text' => 'x', 'position' => 'evil']],
]]);
$saved = json_decode($r['data']['invoice_settings'] ?? '{}', true);
check('layout numbers clamped to safe range', ($saved['receipt_font_size'] ?? 0) == 20 && ($saved['receipt_col_qty'] ?? 0) == 8 && ($saved['receipt_separator'] ?? '') === 'dashed', $saved);
check('custom text blocks kept with emoji, empty ones dropped, bad position fixed', count($saved['custom_blocks'] ?? []) === 2 && str_starts_with($saved['custom_blocks'][0]['text'], '🎉') && $saved['custom_blocks'][1]['position'] === 'bottom', $saved['custom_blocks'] ?? null);

echo "\nMulti-channel offers\n";
[$c, $r] = call('POST', '/master/campaign/update-channel-settings.php', ['email' => ['smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => 'offers@example.com', 'smtp_pass' => 'mail-secret-1', 'from_email' => 'offers@example.com', 'from_name' => 'Test Shop'], 'sms' => ['provider' => 'custom', 'custom_url' => 'https://sms.example.com/send?to={phone}&msg={message}', 'api_key' => 'sms-secret-2']]);
check('save email + SMS settings', $c === 200 && $r['data']['email']['ready'] && $r['data']['sms']['ready'], $r);
check('passwords / keys never returned', !str_contains(json_encode($r), 'secret'), $r);
[$c, $r] = call('POST', '/master/campaign/update-channel-settings.php', ['email' => ['smtp_host' => 'bad host!', 'from_email' => 'x@y.com']]);
check('bad SMTP host rejected', $c === 400, $r);
call('POST', '/master/customer/add-customer.php', ['name' => 'Mail Only', 'email' => 'mailonly@example.com']);
[$c, $r] = call('GET', '/master/campaign/audience.php?channels=whatsapp,email&activity=all');
check('audience counts per channel', $c === 200 && $r['data']['counts']['email'] >= 1 && $r['data']['counts']['whatsapp'] >= 1, $r);
[$c, $r] = call('POST', '/master/campaign/create-campaign.php', ['name' => 'Multi', 'message' => 'Hi {name}', 'subject' => 'Offer for {name}', 'channels' => ['whatsapp', 'email'], 'whatsapp_mode' => 'manual', 'audience' => ['activity' => 'all']]);
check('create WhatsApp + Email campaign', $c === 201 && $r['data']['channels'] === 'whatsapp,email', $r);
$multiId = $r['data']['campaign_id'] ?? 0;
[$c, $r] = call('GET', "/master/campaign/get-campaign.php?campaign_id=$multiId&channel=email");
check('recipients split by channel', $c === 200 && ($r['recipients']['data'][0]['channel'] ?? '') === 'email' && count($r['data']['by_channel']) === 2, $r);
[$c, $r] = call('POST', '/master/campaign/create-campaign.php', ['name' => 'x', 'message' => 'y', 'channels' => ['fax']]);
check('unknown channel refused', $c === 400, $r);
$png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
[$c, $r] = call('POST', '/master/campaign/add-image.php', ['image' => $png]);
check('offer picture upload', $c === 200 && str_ends_with($r['url'] ?? '', '.jpg'), $r);
[$c, $r] = call('POST', '/master/campaign/add-image.php', ['image' => 'data:image/png;base64,' . base64_encode('<?php echo 1; ?>')]);
check('non-image upload refused', $c === 400, $r);

echo "\nDatabase update (no SSH)\n";
[$c, $r] = call('GET', '/master/settings/system-status.php');
check('admin sees database status', $c === 200 && $r['data']['needs_update'] === false, $r);
[$c, $r] = call('POST', '/master/settings/update-database.php');
check('admin can run database update', $c === 200 && isset($r['data']['log']), $r);
[$c, $r] = call('POST', '/auth/signin.php', ['email' => 'counter@test.com', 'password' => 'Counter@123'], false);
$token = $r['access_token'];
[$c, $r] = call('POST', '/master/settings/update-database.php');
check('non-admin cannot update database (403)', $c === 403, $r);
$token = $adminToken;

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
