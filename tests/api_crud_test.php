<?php
/**
 * CRUD test for the master screens: create with every optional field filled, read it back, update with the optional
 * fields left empty (they must stay empty — not come back with the old value or a default), update again, delete.
 * Run against a THROWAWAY database (rows are checked straight in the database too):
 *
 *   DB_NAME=invoicyfy_test php database/migrate.php --admin-email=admin@test.com --admin-password=Admin@1234
 *   DB_NAME=invoicyfy_test php -S 127.0.0.1:8081 -t .
 *   DB_NAME=invoicyfy_test php tests/api_crud_test.php http://127.0.0.1:8081 admin@test.com Admin@1234
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/bootstrap.php';
[$_, $base, $email, $password] = $argv + [null, 'http://127.0.0.1:8081', 'admin@test.com', 'Admin@1234'];
$base = rtrim($base, '/');
$token = null;
$failures = 0;
$checks = 0;
$slowest = [0, ''];

function call(string $method, string $path, ?array $body = null): array
{
    global $base, $token, $slowest;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $t = microtime(true);
    $raw = curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    if ($ms > $slowest[0]) {
        $slowest = [$ms, "$method $path"];
    }
    $json = json_decode((string) $raw, true);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), is_array($json) ? $json : ['_raw' => $raw]];
}

function check(string $label, bool $ok, $detail = null): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
    if (!$ok) {
        $failures++;
        if ($detail !== null) {
            echo '      ' . substr(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 700) . "\n";
        }
    }
}

/** Fields of $row that are not empty although they should be. */
function not_empty(?array $row, array $fields): array
{
    $bad = [];
    foreach ($fields as $f) {
        if (!$row || ($row[$f] !== null && $row[$f] !== '')) {
            $bad[$f] = $row[$f] ?? '(no row)';
        }
    }
    return $bad;
}

/** Fields of $row that differ from $want. */
function differs(?array $row, array $want): array
{
    $bad = [];
    foreach ($want as $k => $v) {
        $got = $row[$k] ?? null;
        $same = is_numeric($v) && is_numeric($got) ? (float) $got == (float) $v : (string) $got === (string) $v;
        if (!$same) {
            $bad[$k] = [$v, $got];
        }
    }
    return $bad;
}

/** Fill → read → clear → update → delete for one screen. */
function crud(string $name, array $o): void
{
    echo "$name\n";
    [$c, $r] = call('POST', $o['add'], $o['full']);
    check("$name: add", $c === 200 || $c === 201, $r);
    $id = (int) db_value("SELECT {$o['pk']} FROM {$o['table']} WHERE {$o['find']} ORDER BY {$o['pk']} DESC LIMIT 1");
    check("$name: saved in database", $id > 0, $r);
    if (!$id) {
        return;
    }
    $row = db_one("SELECT * FROM {$o['table']} WHERE {$o['pk']} = ?", [$id]);
    check("$name: all filled fields saved", !differs($row, $o['saved'] ?? array_intersect_key($o['full'], array_flip($o['optional']))), differs($row, $o['saved'] ?? array_intersect_key($o['full'], array_flip($o['optional']))));
    if (!empty($o['get'])) {
        [$c, $r] = call('GET', sprintf($o['get'], $id));
        check("$name: read by id", $c === 200, $r);
    }
    $cleared = $o['required'] + [$o['pk'] => $id] + array_fill_keys($o['optional'], '');
    [$c, $r] = call($o['method'] ?? 'PUT', $o['update'], $cleared);
    check("$name: update with optional fields empty", $c === 200, $r);
    $row = db_one("SELECT * FROM {$o['table']} WHERE {$o['pk']} = ?", [$id]);
    check("$name: emptied fields stay empty", !not_empty($row, $o['optional']), not_empty($row, $o['optional']));
    if (!empty($o['change'])) {
        [$c, $r] = call($o['method'] ?? 'PUT', $o['update'], $o['change'] + [$o['pk'] => $id]);
        $row = db_one("SELECT * FROM {$o['table']} WHERE {$o['pk']} = ?", [$id]);
        $want = array_intersect_key($o['change'], array_flip($o['check_change'] ?? array_keys($o['change'])));
        check("$name: edit saved", $c === 200 && !differs($row, $want), [$c, $r['message'] ?? $r, differs($row, $want)]);
    }
    if (!empty($o['delete'])) {
        [$c, $r] = call($o['delete_method'] ?? 'POST', $o['delete'], [$o['pk'] => $id]);
        $row = db_one("SELECT * FROM {$o['table']} WHERE {$o['pk']} = ?", [$id]);
        $gone = !$row || !empty($row['deleted_at']) || (isset($row['is_active']) && !(int) $row['is_active'] && !empty($o['soft']))
            || (isset($row['status']) && in_array($row['status'], ['Inactive', 'Deleted'], true));
        check("$name: delete", $c === 200 && $gone, [$c, $r]);
    }
}

[$c, $r] = call('POST', '/auth/signin.php', ['email' => $email, 'password' => $password]);
$token = $r['access_token'] ?? null;
check('admin login', $c === 200 && $token, $r);
$storeId = (int) db_value('SELECT store_id FROM stores ORDER BY store_id LIMIT 1');
$u = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

// ---- Business details (the screen where "Thanks" could not be removed) ----
echo "Business details\n";
$storeOpt = ['legal_name', 'tagline', 'address', 'city', 'pincode', 'phone', 'email', 'pan', 'fssai_no', 'bank_name', 'bank_account_name',
    'bank_account_no', 'bank_ifsc', 'bank_branch', 'upi_id', 'invoice_terms', 'receipt_footer', 'bill_prefix'];
$before = db_one('SELECT * FROM stores WHERE store_id = ?', [$storeId]);
$full = ['store_id' => $storeId, 'name' => 'CRUD Shop', 'legal_name' => 'CRUD Shop LLP', 'tagline' => 'Cool', 'address' => 'MG Road', 'city' => 'Surat',
    'pincode' => '395001', 'phone' => '9876543210', 'email' => 'shop@example.com', 'pan' => 'ABCDE1234F', 'fssai_no' => '12345678901234',
    'bank_name' => 'HDFC', 'bank_account_name' => 'CRUD Shop', 'bank_account_no' => '50100123', 'bank_ifsc' => 'HDFC0001234', 'bank_branch' => 'Adajan',
    'upi_id' => 'shop@upi', 'invoice_terms' => 'Goods once sold', 'receipt_footer' => 'Thanks', 'invoice_prefix' => 'INV', 'bill_prefix' => 'B'];
[$c, $r] = call('PUT', '/master/store/update-store.php', $full);
$row = db_one('SELECT * FROM stores WHERE store_id = ?', [$storeId]);
check('business details saved (footer "Thanks")', $c === 200 && $row['receipt_footer'] === 'Thanks' && $row['upi_id'] === 'shop@upi', [$c, $r]);
[$c, $r] = call('PUT', '/master/store/update-store.php', ['store_id' => $storeId, 'name' => 'CRUD Shop', 'invoice_prefix' => 'INV'] + array_fill_keys($storeOpt, ''));
$row = db_one('SELECT * FROM stores WHERE store_id = ?', [$storeId]);
check('receipt footer and other fields can be removed', $c === 200 && !not_empty($row, $storeOpt), not_empty($row, $storeOpt));
[$c, $r] = call('GET', "/master/store/get-store-by-id.php?store_id=$storeId");
check('read back: footer is empty (no "Thanks")', $c === 200 && (($r['data']['receipt_footer'] ?? null) === null || $r['data']['receipt_footer'] === ''), $r['data'] ?? $r);
[$c] = call('PUT', '/master/store/update-store.php', ['store_id' => $storeId, 'name' => 'CRUD Shop', 'receipt_footer' => 'Visit again']);
check('new footer saved', db_value('SELECT receipt_footer FROM stores WHERE store_id = ?', [$storeId]) === 'Visit again');
call('PUT', '/master/store/update-store.php', array_intersect_key($before, array_flip(array_merge($storeOpt, ['name', 'invoice_prefix', 'gstin', 'state_code']))) + ['store_id' => $storeId]);

crud('Customers', [
    'add' => '/master/customer/add-customer.php', 'update' => '/master/customer/update-customer.php', 'delete' => '/master/customer/delete-customer.php',
    'get' => '/master/customer/get_customer_by_id.php?customer_id=%d', 'table' => 'customers', 'pk' => 'customer_id', 'find' => "name = 'Crud Cust $u'",
    'full' => ['name' => "Crud Cust $u", 'phone' => '9811100' . rand(100, 999), 'email' => "c$u@example.com", 'company_name' => 'Co', 'address' => 'Addr',
        'shipping_address' => 'Ship', 'city' => 'Surat', 'pincode' => '395001', 'notes' => 'VIP', 'birthday' => '1990-05-01', 'anniversary' => '2015-02-02'],
    'optional' => ['phone', 'email', 'company_name', 'address', 'shipping_address', 'city', 'pincode', 'notes', 'birthday', 'anniversary'],
    'required' => ['name' => "Crud Cust $u"],
    'change' => ['name' => "Crud Cust $u", 'city' => 'Ahmedabad'],
]);

crud('Products', [
    'add' => '/master/product/create-product.php', 'update' => '/master/product/update-product.php', 'delete' => '/master/product/delete-product.php',
    'table' => 'products', 'pk' => 'product_id', 'find' => "name = 'Crud Prod $u'",
    'full' => ['name' => "Crud Prod $u", 'price' => 120, 'sku' => "SKU$u", 'category' => 'Crud Cat', 'description' => 'Tasty', 'hsn_code' => '2105', 'unit' => 'pcs',
        'weight_presets' => ''],
    'saved' => ['sku' => "SKU$u", 'category' => 'Crud Cat', 'description' => 'Tasty', 'hsn_code' => '2105'],
    'optional' => ['sku', 'category', 'description', 'hsn_code'],
    'required' => ['name' => "Crud Prod $u", 'price' => 120, 'unit' => 'pcs'],
    'change' => ['name' => "Crud Prod $u", 'price' => 150, 'unit' => 'pcs'], 'check_change' => ['price'],
]);

crud('Categories', [
    'add' => '/master/category/add-category.php', 'update' => '/master/category/update-category.php', 'delete' => '/master/category/delete-category.php',
    'method' => 'POST', 'table' => 'categories', 'pk' => 'category_id', 'find' => "name = 'Crud Category $u'",
    'full' => ['name' => "Crud Category $u", 'description' => 'Desc', 'hsn_code' => '2105', 'default_unit' => 'kg', 'weight_presets' => '250,500'],
    'saved' => ['description' => 'Desc', 'hsn_code' => '2105', 'weight_presets' => '250,500'],
    'optional' => ['description', 'hsn_code', 'weight_presets'],
    'required' => ['name' => "Crud Category $u", 'default_unit' => 'kg'],
    'change' => ['name' => "Crud Category $u B", 'default_unit' => 'pcs'],
]);

crud('Suppliers', [
    'add' => '/master/supplier/add-supplier.php', 'update' => '/master/supplier/update-supplier.php', 'delete' => '/master/supplier/delete-supplier.php',
    'method' => 'POST', 'table' => 'suppliers', 'pk' => 'supplier_id', 'find' => "name = 'Crud Supplier $u'", 'soft' => true,
    'get' => '/master/supplier/get-supplier.php?supplier_id=%d',
    'full' => ['name' => "Crud Supplier $u", 'store_id' => $storeId, 'contact_person' => 'Ramesh', 'phone' => '9822200111', 'email' => "s$u@example.com",
        'gstin' => '24AAACA1234A1Z5', 'address' => 'GIDC', 'notes' => 'Milk'],
    'optional' => ['contact_person', 'phone', 'email', 'gstin', 'address', 'notes'],
    'required' => ['name' => "Crud Supplier $u", 'store_id' => $storeId],
    'change' => ['name' => "Crud Supplier $u", 'store_id' => $storeId, 'phone' => '9822200999'],
]);

crud('Expenses', [
    'add' => '/master/expense/add-expense.php', 'update' => '/master/expense/update-expense.php', 'delete' => '/master/expense/delete-expense.php',
    'get' => '/master/expense/get-expense-by-id.php?expense_id=%d', 'table' => 'expenses', 'pk' => 'expense_id', 'find' => "description = 'Crud exp $u'",
    'full' => ['category' => 'Rent', 'amount' => 500, 'description' => "Crud exp $u", 'vendor' => 'Landlord', 'reference_no' => 'R1', 'payment_method' => 'UPI'],
    'saved' => ['vendor' => 'Landlord', 'reference_no' => 'R1'],
    'optional' => ['vendor', 'reference_no', 'description'],
    'required' => ['category' => 'Rent', 'amount' => 500, 'payment_method' => 'UPI'],
    'change' => ['category' => 'Rent', 'amount' => 650, 'payment_method' => 'Cash'], 'check_change' => ['amount', 'payment_method'],
]);

crud('Coupons', [
    'add' => '/master/coupon/add-coupon.php', 'update' => '/master/coupon/update-coupon.php', 'delete' => '/master/coupon/delete-coupon.php',
    'method' => 'POST', 'get' => '/master/coupon/get-coupon.php?coupon_id=%d', 'table' => 'coupons', 'pk' => 'coupon_id', 'find' => "code = 'CRUD$u'", 'soft' => true,
    'full' => ['code' => "CRUD$u", 'discount_type' => 'percent', 'value' => 10, 'description' => 'Ten off', 'max_discount' => 50, 'valid_from' => date('Y-m-d'),
        'valid_to' => date('Y-m-d', strtotime('+30 days')), 'usage_limit' => 100, 'per_customer_limit' => 1, 'store_id' => $storeId],
    'saved' => ['description' => 'Ten off', 'max_discount' => 50, 'usage_limit' => 100, 'per_customer_limit' => 1],
    'optional' => ['description', 'max_discount', 'valid_from', 'valid_to', 'usage_limit', 'per_customer_limit'],
    'required' => ['code' => "CRUD$u", 'discount_type' => 'percent', 'value' => 10, 'store_id' => $storeId],
    'change' => ['code' => "CRUD$u", 'discount_type' => 'flat', 'value' => 25, 'store_id' => $storeId],
]);

crud('Offers (happy hour)', [
    'add' => '/master/offer/add-offer.php', 'update' => '/master/offer/update-offer.php', 'delete' => '/master/offer/delete-offer.php',
    'method' => 'POST', 'table' => 'offers', 'pk' => 'offer_id', 'find' => "name = 'Crud Offer $u'", 'soft' => true,
    'full' => ['name' => "Crud Offer $u", 'offer_type' => 'happy_hour', 'discount_percent' => 10, 'start_time' => '15:00', 'end_time' => '17:00',
        'valid_from' => date('Y-m-d'), 'valid_to' => date('Y-m-d', strtotime('+7 days')), 'store_id' => $storeId],
    'saved' => ['valid_from' => date('Y-m-d'), 'valid_to' => date('Y-m-d', strtotime('+7 days'))],
    'optional' => ['start_time', 'end_time', 'valid_from', 'valid_to'],
    'required' => ['name' => "Crud Offer $u", 'offer_type' => 'happy_hour', 'discount_percent' => 10, 'store_id' => $storeId],
    'change' => ['name' => "Crud Offer $u", 'offer_type' => 'happy_hour', 'discount_percent' => 15, 'store_id' => $storeId],
]);

$roleId = (int) db_value("SELECT role_id FROM roles WHERE role_name <> 'Admin' ORDER BY role_id LIMIT 1");
crud('Users', [
    'add' => '/master/user/create-user.php', 'update' => '/master/user/update-user.php', 'delete' => '/master/user/delete-user.php',
    'get' => '/master/user/get_user_by_id.php?user_id=%d', 'table' => 'users', 'pk' => 'user_id', 'find' => "email = 'crud$u@example.com'", 'soft' => true,
    'full' => ['name' => "Crud User $u", 'email' => "crud$u@example.com", 'password' => 'Crud@12345', 'phone' => '9833300111', 'city' => 'Surat',
        'role_id' => $roleId, 'store_id' => $storeId],
    'saved' => ['phone' => '9833300111', 'city' => 'Surat'],
    'optional' => ['phone', 'city'],
    'required' => ['name' => "Crud User $u", 'email' => "crud$u@example.com", 'role_id' => $roleId, 'store_id' => $storeId],
    'change' => ['name' => "Crud User $u B", 'email' => "crud$u@example.com", 'role_id' => $roleId, 'store_id' => $storeId], 'check_change' => ['name'],
]);

echo "Roles\n";
[$c, $r] = call('POST', '/master/role/add-role.php', ['role_name' => "Crud Role $u", 'permissions' => ['invoices' => ['view', 'add']]]);
$rid = (int) ($r['role_id'] ?? 0);
check('Roles: add', ($c === 200 || $c === 201) && $rid > 0, [$c, $r]);
[$c, $r] = call('PUT', '/master/role/update-role.php', ['role_id' => $rid, 'role_name' => "Crud Role $u B", 'permissions' => ['reports' => ['view']]]);
[, $rr] = call('GET', "/master/role/get-role-data-by-id.php?role_id=$rid");
check('Roles: edit name and permissions', $c === 200 && str_contains(json_encode($rr), "Crud Role $u B"), [$r, $rr]);
[$c, $r] = call('GET', "/master/role/get-role-data-by-id.php?role_id=$rid");
check('Roles: read shows the new permissions', $c === 200 && str_contains(json_encode($r), 'reports'), $r);
[$c, $r] = call('POST', '/master/role/delete-role.php', ['role_id' => $rid]);
check('Roles: delete', $c === 200 && !db_value('SELECT 1 FROM roles WHERE role_id = ?', [$rid]), $r);

printf("\nSlowest call: %.0f ms (%s)\n", $slowest[0], $slowest[1]);
echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
