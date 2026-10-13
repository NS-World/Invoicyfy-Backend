<?php
/**
 * V3 API test (day close, split payment, tables, held bills, coupons, loyalty, discount approval PIN,
 * offline idempotency, credit / udhaar, activity log, automatic messages, permissions).
 * Run against a FRESH throwaway database (same setup as api_smoke_test.php):
 *
 *   DB_NAME=invoicyfy_v3 php database/migrate.php --admin-email=admin@test.com --admin-password=Admin@1234
 *   DB_NAME=invoicyfy_v3 php -S 127.0.0.1:8084 -t .
 *   php tests/api_v3_test.php http://127.0.0.1:8084 admin@test.com Admin@1234
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
[$_, $base, $email, $password] = $argv + [null, 'http://127.0.0.1:8084', 'admin@test.com', 'Admin@1234'];
$base = rtrim($base, '/');
$token = null;
$failures = 0;
$checks = 0;

function call(string $method, string $path, ?array $body = null, ?string $as = null): array
{
    global $base, $token;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    $t = $as ?? $token;
    if ($t) {
        $headers[] = "Authorization: Bearer $t";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode((string) $raw, true);
    return [$code, is_array($json) ? $json : ['_raw' => $raw]];
}

function check(string $label, bool $ok, $detail = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ✓ $label\n";
        return;
    }
    $failures++;
    echo "  ✗ $label\n";
    if ($detail !== null) {
        echo '      ' . substr(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 700) . "\n";
    }
}

function login(string $email, string $password): ?string
{
    [, $r] = call('POST', '/auth/signin.php', ['email' => $email, 'password' => $password], '');
    return $r['access_token'] ?? null;
}

$near = fn($a, $b) => abs((float) $a - (float) $b) < 0.011;

echo "Setup\n";
$token = login($email, $password);
check('admin login', (bool) $token);
[, $me] = call('GET', '/auth/me.php');
$storeId = $me['data']['store_id'];
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Kesar Scoop', 'price' => 100, 'gst_rate' => 18, 'price_includes_tax' => true, 'unit' => 'pcs', 'stock_qty' => 100]);
$pIncl = $r['product_id'] ?? null;
[$c, $r] = call('POST', '/master/product/create-product.php', ['name' => 'Party Tub', 'price' => 500, 'gst_rate' => 18, 'price_includes_tax' => false, 'unit' => 'pcs', 'stock_qty' => 20]);
$pExcl = $r['product_id'] ?? null;
check('products created', $pIncl && $pExcl, $r);
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Riya Shah', 'phone' => '9812345670', 'email' => 'riya@example.com', 'birthday' => date('Y-m-d', strtotime('-25 years')), 'anniversary' => '2015-01-01']);
$cust = $r['customer_id'] ?? null;
check('customer with birthday', $c === 201 && $cust, $r);
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Future', 'birthday' => date('Y-m-d', strtotime('+3 days'))]);
check('birthday in the future rejected', $c === 400, $r);
$item = fn($pid, $qty = 1, $extra = []) => ['product_id' => $pid, 'quantity' => $qty] + $extra;
$bill = fn($extra = []) => ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $pIncl, 'quantity' => 2]]] + $extra;

echo "Counter settings & day open\n";
[$c, $r] = call('GET', "/master/pos/pos-settings.php?store_id=$storeId");
check('pos settings defaults', $c === 200 && $r['data']['require_day_open'] === false && $r['data']['table_count'] === 0, $r);
[$c, $r] = call('POST', '/master/pos/update-pos-settings.php', ['store_id' => $storeId, 'table_count' => 500]);
check('table count over 200 rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/pos/update-pos-settings.php', ['store_id' => $storeId, 'table_count' => 12, 'require_day_open' => true]);
check('save pos settings', $c === 200 && $r['data']['table_count'] === 12, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill());
check('billing blocked until day is opened (409 day_not_open)', $c === 409 && ($r['code'] ?? '') === 'day_not_open', $r);

echo "Day close / cash register\n";
[$c, $r] = call('GET', "/master/cash/current.php?store_id=$storeId");
check('no open day yet', $c === 200 && $r['data']['open'] === null, $r);
[$c, $r] = call('POST', '/master/cash/create-session.php', ['store_id' => $storeId, 'opening_cash' => -5]);
check('negative opening cash rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/cash/create-session.php', ['store_id' => $storeId, 'opening_cash' => 500]);
$session = $r['data']['session_id'] ?? null;
check('open day with ₹500', $c === 201 && $session, $r);
[$c, $r] = call('POST', '/master/cash/create-session.php', ['store_id' => $storeId, 'opening_cash' => 100]);
check('second open day rejected (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/cash/add-movement.php', ['session_id' => $session, 'type' => 'in', 'amount' => 100]);
check('pay in needs a reason', $c === 400, $r);
[$c, $r] = call('POST', '/master/cash/add-movement.php', ['session_id' => $session, 'type' => 'in', 'amount' => 100, 'reason' => 'Change added']);
check('pay in ₹100', $c === 201, $r);
[$c, $r] = call('POST', '/master/cash/add-movement.php', ['session_id' => $session, 'type' => 'out', 'amount' => 5000, 'reason' => 'Too much']);
check('pay out more than drawer rejected', $c === 422, $r);
[$c, $r] = call('POST', '/master/cash/add-movement.php', ['session_id' => $session, 'type' => 'out', 'amount' => 50, 'reason' => 'Milk']);
check('pay out ₹50', $c === 201, $r);

echo "Split payment, table number\n";
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['payments' => [['amount' => 150, 'payment_method' => 'Cash'], ['amount' => 100, 'payment_method' => 'UPI']]]));
check('payments more than total rejected', $c === 422, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['table_no' => '7', 'order_type' => 'Dine-in',
    'payments' => [['amount' => 120, 'payment_method' => 'Cash'], ['amount' => 80, 'payment_method' => 'UPI', 'reference_no' => 'UTR1']]]));
$inv1 = $r['data'] ?? [];
check('split bill cash 120 + UPI 80', $c === 201 && $inv1['status'] === 'Paid' && count($inv1['payments']) === 2, $r);
check('table number saved', ($inv1['table_no'] ?? null) === '7', $inv1['table_no'] ?? null);
[$c, $r] = call('GET', "/master/cash/current.php?store_id=$storeId");
$rep = $r['data']['open']['report'] ?? [];
check('cash sales counted in the day', $near($rep['cash_sales'] ?? 0, 120), $rep);
check('expected cash = 500 + 120 + 100 - 50', $near($rep['expected_cash'] ?? 0, 670), $rep);
check('UPI listed by mode', in_array('UPI', array_column($rep['payments_by_method'] ?? [], 'method'), true), $rep);

echo "Offline sync idempotency\n";
$uuid = 'off-' . bin2hex(random_bytes(6));
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['client_uuid' => $uuid, 'offline_created_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'payment' => ['amount' => 200, 'payment_method' => 'Cash']]));
$offId = $r['invoice_id'] ?? null;
check('offline bill saved with original date', $c === 201 && ($r['data']['invoice_date'] ?? '') === date('Y-m-d', strtotime('-2 days')) && !empty($r['data']['offline_at']), $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['client_uuid' => $uuid, 'offline_created_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'payment' => ['amount' => 200, 'payment_method' => 'Cash']]));
check('same bill sent again is not duplicated', $c === 200 && !empty($r['duplicate']) && $r['invoice_id'] === $offId, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['client_uuid' => 'bad uuid!']));
check('invalid client_uuid rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['client_uuid' => 'x' . bin2hex(random_bytes(5)), 'offline_created_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]));
check('offline date in the future rejected', $c === 400, $r);

echo "Held bills\n";
[$c, $r] = call('POST', '/master/held/add-held.php', ['store_id' => $storeId, 'payload' => ['items' => []]]);
check('empty bill cannot be held', $c === 400, $r);
[$c, $r] = call('POST', '/master/held/add-held.php', ['store_id' => $storeId, 'label' => 'Blue shirt', 'table_no' => '3', 'total' => 200, 'payload' => ['items' => [['product_id' => $pIncl, 'quantity' => 2]]]]);
$held = $r['held_id'] ?? null;
check('hold a bill', $c === 201 && $held, $r);
[$c, $r] = call('GET', "/master/held/held-bills.php?store_id=$storeId");
check('held bills list', $c === 200 && $r['total'] === 1 && $r['data'][0]['label'] === 'Blue shirt', $r);
[$c, $r] = call('GET', "/master/held/held-bills.php?store_id=$storeId&held_id=$held");
check('resume held bill returns cart', $c === 200 && $r['data']['payload']['items'][0]['quantity'] === 2, $r);
[$c, $r] = call('POST', '/master/held/add-held.php', ['store_id' => $storeId, 'held_id' => $held, 'label' => 'Blue shirt', 'total' => 300, 'payload' => ['items' => [['product_id' => $pIncl, 'quantity' => 3]]]]);
check('update held bill', $c === 201 && $r['held_id'] === $held, $r);
[$c, $r] = call('POST', '/master/held/delete-held.php', ['store_id' => $storeId, 'held_id' => $held, 'resumed' => true]);
check('remove held bill after resume', $c === 200, $r);
[$c, $r] = call('POST', '/master/held/delete-held.php', ['store_id' => $storeId, 'held_id' => $held]);
check('removing twice -> 404', $c === 404, $r);

echo "Coupons\n";
[$c, $r] = call('POST', '/master/coupon/add-coupon.php', ['store_id' => $storeId, 'code' => 'x', 'value' => 10]);
check('short code rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/coupon/add-coupon.php', ['store_id' => $storeId, 'code' => 'save 10', 'discount_type' => 'percent', 'value' => 10, 'max_discount' => 50, 'min_bill' => 150, 'per_customer_limit' => 1]);
$coupon = $r['coupon_id'] ?? null;
check('create SAVE10 (10%, max ₹50, min ₹150, once per customer)', $c === 201 && ($r['data']['code'] ?? '') === 'SAVE10', $r);
[$c, $r] = call('POST', '/master/coupon/add-coupon.php', ['store_id' => $storeId, 'code' => 'SAVE10', 'value' => 5]);
check('duplicate code rejected (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/coupon/add-coupon.php', ['store_id' => $storeId, 'code' => 'OLD5', 'value' => 5, 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31']);
check('create expired coupon', $c === 201 && $r['data']['state'] === 'Expired', $r);
[$c, $r] = call('POST', '/master/coupon/add-coupon.php', ['store_id' => $storeId, 'code' => 'FLAT50', 'discount_type' => 'flat', 'value' => 50]);
check('create FLAT50', $c === 201, $r);
[$c, $r] = call('GET', "/master/coupon/check-coupon.php?store_id=$storeId&code=save10&amount=100&customer_id=$cust");
check('below minimum bill -> 422', $c === 422 && str_contains($r['message'], '150'), $r);
[$c, $r] = call('GET', "/master/coupon/check-coupon.php?store_id=$storeId&code=OLD5&amount=500");
check('expired coupon -> 422', $c === 422 && str_contains($r['message'], 'expired'), $r);
[$c, $r] = call('GET', "/master/coupon/check-coupon.php?store_id=$storeId&code=SAVE10&amount=300");
check('per-customer coupon needs a customer', $c === 422, $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', $bill(['customer_id' => $cust, 'coupon_code' => 'SAVE10']));
check('quote with SAVE10 on ₹200 = ₹20 off', $c === 200 && $near($r['data']['coupon']['discount'], 20) && $near($r['data']['totals']['total_amount'], 180), $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['customer_id' => $cust, 'coupon_code' => 'SAVE10', 'payment' => ['amount' => 180, 'payment_method' => 'Cash']]));
$couponInv = $r['data'] ?? [];
check('bill with coupon: total 180, GST on discounted value', $c === 201 && $near($couponInv['total_amount'], 180) && $near($couponInv['coupon_discount'], 20)
    && $near($couponInv['taxable_amount'] + $couponInv['tax_amount'], 180), $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['customer_id' => $cust, 'coupon_code' => 'SAVE10']));
check('same customer cannot use SAVE10 twice', $c === 422, $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', ['store_id' => $storeId, 'items' => [$item($pExcl)], 'coupon_code' => 'FLAT50']);
check('flat ₹50 on GST-exclusive item lowers bill by ₹50 (590 -> 540)', $c === 200 && $near($r['data']['totals']['total_amount'], 540), $r['data']['totals'] ?? $r);
[$c, $r] = call('GET', "/master/coupon/get-coupon.php?coupon_id=$coupon");
check('coupon shows its use', $c === 200 && $r['data']['used_count'] === 1 && count($r['data']['redemptions']) === 1, $r);
[$c, $r] = call('POST', '/master/invoice/cancel-invoice.php', ['invoice_id' => $couponInv['invoice_id'], 'reason' => 'Test']);
[, $r2] = call('GET', "/master/coupon/get-coupon.php?coupon_id=$coupon");
check('cancelling the bill gives the coupon back', $c === 200 && $r2['data']['used_count'] === 0 && $r2['data']['redemptions'][0]['status'] === 'Reversed', $r2);
[$c, $r] = call('POST', '/master/coupon/delete-coupon.php', ['coupon_id' => $coupon]);
check('used coupon is switched off, not deleted', $c === 200 && str_contains($r['message'], 'switched off'), $r);
[$c, $r] = call('GET', "/master/coupon/coupons.php?store_id=$storeId&page=1&limit=20");
check('coupon list with states', $c === 200 && $r['total'] === 3, $r);

echo "Loyalty points\n";
[$c, $r] = call('POST', '/master/loyalty/update-loyalty-settings.php', ['store_id' => $storeId, 'enabled' => true, 'point_value' => 0]);
check('point value 0 rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/loyalty/update-loyalty-settings.php', ['store_id' => $storeId, 'enabled' => true, 'earn_per_100' => 10, 'point_value' => 1, 'min_redeem_points' => 10, 'max_redeem_percent' => 50]);
check('turn on loyalty (10 pts / ₹100, 1 pt = ₹1)', $c === 200 && $r['data']['enabled'] === true, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'customer_id' => $cust, 'items' => [$item($pIncl, 5)]]);
$earnInv = $r['data'] ?? [];
check('₹500 bill earns 50 points', $c === 201 && $near($earnInv['loyalty_points_earned'], 50), $r);
[$c, $r] = call('GET', "/master/loyalty/customer-points.php?customer_id=$cust&store_id=$storeId");
check('customer has 50 points', $c === 200 && $near($r['data']['points'], 50) && $r['data']['history'][0]['type'] === 'earn', $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', $bill(['customer_id' => $cust, 'redeem_points' => 500]));
check('redeeming more than balance rejected', $c === 422, $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', $bill(['customer_id' => $cust, 'redeem_points' => 5]));
check('below minimum redeem rejected', $c === 422, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $bill(['customer_id' => $cust, 'redeem_points' => 40]));
$redeemInv = $r['data'] ?? [];
check('use 40 points = ₹40 off a ₹200 bill', $c === 201 && $near($redeemInv['loyalty_discount'], 40) && $near($redeemInv['total_amount'], 160), $r);
[$c, $r] = call('GET', "/master/loyalty/customer-points.php?customer_id=$cust&store_id=$storeId");
check('balance 50 - 40 + 16 earned = 26', $near($r['data']['points'], 26), $r['data'] ?? $r);
call('POST', '/master/invoice/cancel-invoice.php', ['invoice_id' => $redeemInv['invoice_id'], 'reason' => 'Test']);
[$c, $r] = call('GET', "/master/loyalty/customer-points.php?customer_id=$cust&store_id=$storeId");
check('cancel gives back 40 and removes 16 -> 50', $near($r['data']['points'], 50), $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/loyalty/adjust-points.php', ['customer_id' => $cust, 'points' => 10]);
check('manual adjustment needs a reason', $c === 400, $r);
[$c, $r] = call('POST', '/master/loyalty/adjust-points.php', ['customer_id' => $cust, 'points' => -1000, 'note' => 'Correction']);
check('points never go below zero', $c === 200 && $near($r['points'], 0), $r);
[$c, $r] = call('GET', "/master/loyalty/loyalty-settings.php?store_id=$storeId");
check('loyalty stats', $c === 200 && $r['data']['settings']['earn_per_100'] == 10, $r);

echo "Discount limit & manager PIN\n";
[, $roles] = call('GET', '/master/role/roles.php?page=1&limit=10');
$staffRole = null;
$mgrRole = null;
foreach ($roles['data'] as $ro) {
    if ($ro['role_name'] === 'Staff') {
        $staffRole = $ro['role_id'];
    }
    if ($ro['role_name'] === 'Manager') {
        $mgrRole = $ro['role_id'];
    }
}
[$c, $r] = call('POST', '/master/role/update-role.php', ['role_id' => $staffRole, 'role_name' => 'Staff', 'max_discount_percent' => 150]);
check('limit over 100% rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/role/update-role.php', ['role_id' => $staffRole, 'role_name' => 'Staff', 'max_discount_percent' => 10]);
[, $r2] = call('GET', "/master/role/get-role-data-by-id.php?role_id=$staffRole");
check('Staff discount limit 10%', $c === 200 && $r2['data']['max_discount_percent'] == 10, $r2);
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'Cashier', 'email' => 'cashier@test.com', 'password' => 'Cashier@123', 'role_id' => $staffRole, 'store_id' => $storeId]);
check('create cashier', $c === 201, $r);
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'Manager', 'email' => 'manager@test.com', 'password' => 'Manager@123', 'role_id' => $mgrRole, 'store_id' => $storeId, 'approval_pin' => '1111']);
check('obvious PIN 1111 rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'Manager', 'email' => 'manager@test.com', 'password' => 'Manager@123', 'role_id' => $mgrRole, 'store_id' => $storeId, 'approval_pin' => '4826']);
check('create manager with approval PIN', $c === 201, $r);
$cashier = login('cashier@test.com', 'Cashier@123');
$manager = login('manager@test.com', 'Manager@123');
check('cashier + manager login', $cashier && $manager);
$disc = fn($pct) => ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $pIncl, 'quantity' => 2, 'discount_amount' => 200 * $pct / 100]]];
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $disc(5), $cashier);
check('cashier: 5% discount allowed', $c === 201, $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', $disc(20), $cashier);
check('quote says approval needed for 20%', $c === 200 && $r['data']['approval_required'] === true && $r['data']['discount_limit'] == 10, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $disc(20), $cashier);
check('cashier: 20% needs manager PIN (403 approval_required)', $c === 403 && ($r['code'] ?? '') === 'approval_required', $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $disc(20) + ['approval_pin' => '0000'], $cashier);
check('wrong PIN rejected', $c === 403 && ($r['code'] ?? '') === 'approval_wrong_pin', $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $disc(20) + ['approval_pin' => '4826'], $cashier);
check('manager PIN approves 20%', $c === 201 && !empty($r['data']['approved_by']), $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $pIncl, 'quantity' => 1, 'unit_price' => 70]]], $cashier);
check('selling below product price counts as discount (30%)', $c === 403 && ($r['code'] ?? '') === 'approval_required', $r);
[$c, $r] = call('POST', '/auth/set-approval-pin.php', ['current_password' => 'wrong', 'pin' => '5739'], $manager);
check('changing own PIN needs password', $c === 422, $r);
[$c, $r] = call('POST', '/auth/set-approval-pin.php', ['current_password' => 'Manager@123', 'pin' => '5739'], $manager);
check('manager changes own PIN', $c === 200, $r);

echo "Permissions\n";
[$c, $r] = call('GET', "/master/pos/pos-settings.php?store_id=$storeId", null, $cashier);
check('cashier reads counter settings', $c === 200 && $r['data']['discount_limit'] == 10, $r);
[$c, $r] = call('POST', '/master/pos/update-pos-settings.php', ['store_id' => $storeId, 'table_count' => 1], $cashier);
check('cashier cannot change counter settings (403)', $c === 403, $r);
[$c, $r] = call('POST', '/master/held/add-held.php', ['store_id' => $storeId, 'total' => 100, 'payload' => ['items' => [['product_id' => $pIncl, 'quantity' => 1]]]], $cashier);
check('cashier can hold a bill', $c === 201, $r);
[$c, $r] = call('POST', '/master/held/delete-held.php', ['store_id' => $storeId, 'held_id' => $r['held_id'] ?? 0], $cashier);
check('cashier can discard a held bill', $c === 200, $r);
[$c, $r] = call('POST', '/master/loyalty/adjust-points.php', ['customer_id' => $cust, 'points' => 10, 'note' => 'x'], $cashier);
check('cashier cannot adjust points (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/activity/activity-log.php?page=1&limit=5', null, $cashier);
check('cashier cannot see activity log (403)', $c === 403, $r);
[$c, $r] = call('GET', '/master/activity/activity-log.php?page=1&limit=5', null, $manager);
check('manager sees activity log', $c === 200, $r);

echo "Credit / udhaar\n";
[, $r] = call('GET', "/master/credit/dues.php?store_id=$storeId&page=1&limit=20");
$before = 0.0;
foreach ($r['data'] ?? [] as $x) {
    if ($x['customer_id'] === $cust) {
        $before = $x['due'];
    }
}
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'customer_id' => $cust, 'items' => [$item($pIncl, 3)]]);
$due1 = $r['data']['invoice_id'] ?? null;
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'customer_id' => $cust, 'items' => [$item($pIncl, 2)], 'payment' => ['amount' => 50, 'payment_method' => 'Cash']]);
$due2 = $r['data']['invoice_id'] ?? null;
[$c, $r] = call('GET', "/master/credit/dues.php?store_id=$storeId&page=1&limit=20");
$row = null;
foreach ($r['data'] ?? [] as $x) {
    if ($x['customer_id'] === $cust) {
        $row = $x;
    }
}
check('dues list: Riya owes earlier due + 300 + 150', $c === 200 && $row && $near($row['due'], $before + 450), $r);
check('aging bucket 0-30 days', $row && $near($row['d0_30'], $before + 450), $row);
[$c, $r] = call('POST', '/master/credit/add-payment.php', ['customer_id' => $cust, 'amount' => $before + 1000]);
check('more than due rejected', $c === 422, $r);
[$c, $r] = call('POST', '/master/credit/add-payment.php', ['customer_id' => $cust, 'amount' => $before + 350, 'payment_method' => 'UPI']);
$appliedIds = array_column($r['applied'] ?? [], 'invoice_id');
check('payment applied to the oldest bills first', $c === 201 && end($appliedIds) === $due2 && in_array($due1, $appliedIds, true) && $near($r['remaining_due'], 100), $r);
[$c, $r] = call('GET', "/master/credit/customer-statement.php?customer_id=$cust");
check('statement with running balance', $c === 200 && $near($r['data']['total_due'], 100) && $near(end($r['data']['entries'])['balance'], $r['data']['closing_balance']), $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/credit/send-reminders.php', ['store_id' => $storeId, 'customer_ids' => [$cust], 'channels' => ['sms']]);
check('reminder without a ready channel explains why (422)', $c === 422, $r);

echo "Automatic messages\n";
[$c, $r] = call('GET', "/master/automation/automation-settings.php?store_id=$storeId");
check('automation settings', $c === 200 && $r['data']['settings']['summary']['enabled'] === false, $r);
[$c, $r] = call('POST', '/master/automation/update-automation-settings.php', ['store_id' => $storeId, 'summary' => ['enabled' => true, 'time' => '22:30']]);
check('summary needs owner contact', $c === 400, $r);
[$c, $r] = call('POST', '/master/automation/update-automation-settings.php', ['store_id' => $storeId, 'summary' => ['enabled' => true, 'time' => '25:00'], 'owner_phones' => '9825012345']);
check('bad time rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/automation/update-automation-settings.php', ['store_id' => $storeId, 'owner_phones' => '98250 12345, 9898989898', 'owner_emails' => 'owner@example.com',
    'owner_channels' => ['email', 'sms'], 'summary' => ['enabled' => true, 'time' => '22:30'], 'birthday' => ['enabled' => true, 'coupon' => ['value' => 15]]]);
check('save automation (phones normalised)', $c === 200 && $r['data']['owner_phones'] === '919825012345,919898989898' && $r['data']['birthday']['coupon']['value'] == 15, $r);
[$c, $r] = call('POST', '/master/automation/run-now.php', ['store_id' => $storeId, 'job' => 'summary']);
check('run summary now (no channel ready -> clear message)', $c === 200 && str_contains($r['message'], 'ready channel'), $r);
[$c, $r] = call('POST', '/master/automation/run-now.php', ['store_id' => $storeId, 'job' => 'birthday']);
check('birthday run explains missing channel', $c === 200, $r);
[$c, $r] = call('POST', '/master/automation/run-now.php', ['store_id' => $storeId, 'job' => 'birthday']);
check('birthday wishes run once a day (409 second time)', $c === 409, $r);
[$c, $r] = call('POST', '/jobs/tick.php', [], $cashier);
check('scheduler tick works for any signed-in user', $c === 200, $r);
[$c, $r] = call('POST', '/master/automation/run-now.php', ['store_id' => $storeId, 'job' => 'nope']);
check('unknown job rejected', $c === 400, $r);

echo "Close the day\n";
[$c, $r] = call('POST', '/master/cash/close-session.php', ['session_id' => $session, 'denominations' => ['500' => 1, '100' => 1]]);
check('big difference needs a note', $c === 422 && ($r['code'] ?? '') === 'note_required', $r);
[$c, $r] = call('GET', "/master/cash/current.php?store_id=$storeId");
$expected = $r['data']['open']['report']['expected_cash'] ?? 0;
[$c, $r] = call('POST', '/master/cash/close-session.php', ['session_id' => $session, 'counted_cash' => $expected - 20, 'notes' => 'Short by 20']);
check('close day: counted vs expected, difference -20', $c === 200 && $r['data']['status'] === 'Closed' && $near($r['data']['difference'], -20), $r);
check('Z report snapshot saved', !empty($r['data']['report']['bills']['count']), $r['data']['report'] ?? null);
[$c, $r] = call('POST', '/master/cash/close-session.php', ['session_id' => $session, 'counted_cash' => 1]);
check('closing twice rejected', $c === 409, $r);
[$c, $r] = call('GET', '/master/cash/sessions.php?page=1&limit=10');
check('day history', $c === 200 && $r['total'] === 1 && $near($r['data'][0]['difference'], -20), $r);

echo "Activity log\n";
[$c, $r] = call('GET', '/master/activity/activity-log.php?page=1&limit=200');
$actions = array_column($r['data'] ?? [], 'action');
foreach (['invoice.create', 'invoice.cancel', 'cash.open', 'cash.close', 'discount.approved', 'approval.failed', 'coupon.create', 'loyalty.adjust', 'credit.payment', 'login'] as $a) {
    check("logged: $a", in_array($a, $actions, true));
}
[$c, $r] = call('GET', '/master/activity/activity-log.php?page=1&limit=50&important=1');
check('important filter', $c === 200 && !in_array('login', array_column($r['data'], 'action'), true), $r);

echo "Wrong PIN lockout\n";
call('POST', '/master/pos/update-pos-settings.php', ['store_id' => $storeId, 'require_day_open' => false]);
for ($i = 0; $i < 4; $i++) {
    call('POST', '/master/invoice/create-invoice.php', $disc(20) + ['approval_pin' => '9999'], $cashier);
}
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', $disc(20) + ['approval_pin' => '5739'], $cashier);
check('5 wrong PINs lock approvals for 15 minutes (429)', $c === 429, $r);

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
