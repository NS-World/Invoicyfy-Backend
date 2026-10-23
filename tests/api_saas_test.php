<?php
/**
 * Khanak SaaS test: sign-up (trial and paid), payment checks, a shop per database, isolation between shops, plan
 * features / limits / expiry, renewals, super admin, Razorpay webhook. Uses THROWAWAY databases only.
 *
 *   # API in SaaS mode, test payments (mock) and in Razorpay mode against the mock gateway (tests/mock_v4_router.php on 8091)
 *   ENV="SAAS_MODE=true SAAS_DB_NAME=khanak_control_test SAAS_DB_PREFIX=khanak_tt_ SAAS_MASTER_KEY=test-master-key"
 *   env $ENV SAAS_PAYMENT_MODE=mock php -S 127.0.0.1:8095 tests/saas_router.php
 *   env $ENV SAAS_PAYMENT_MODE=razorpay SAAS_RAZORPAY_KEY_ID=rzp_test_key SAAS_RAZORPAY_KEY_SECRET=rzp_test_secret \
 *       SAAS_RAZORPAY_WEBHOOK_SECRET=whsec_test SAAS_RAZORPAY_API=http://127.0.0.1:8091/razorpay/v1 php -S 127.0.0.1:8096 tests/saas_router.php
 *   env $ENV php saas/cli/setup.php --admin-email=boss@khanak.test --admin-password=Boss@12345
 *   env $ENV php tests/api_saas_test.php
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/tenant.php';
$A = 'http://127.0.0.1:8095';
$R = 'http://127.0.0.1:8096';
$failures = 0;
$checks = 0;

function call(string $base, string $method, string $path, $body = null, array $headers = [], bool $raw = false): array
{
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 120]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $out = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $raw ? $out : (json_decode($out, true) ?? ['_raw' => substr($out, 0, 300)])];
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

/** Shop-side call: X-Tenant + bearer token. */
function shop(string $slug, ?string $token, string $method, string $path, $body = null): array
{
    global $A;
    return call($A, $method, $path, $body, array_filter(['X-Tenant' => $slug, 'Authorization' => $token ? "Bearer $token" : null]));
}

function login(string $slug, string $email, string $password): ?string
{
    [, $r] = shop($slug, null, 'POST', '/auth/signin.php', ['email' => $email, 'password' => $password]);
    return $r['access_token'] ?? null;
}

function ctl(): PDO
{
    return saas_control_pdo();
}

function forget(string $slug): void
{
    @unlink(__DIR__ . "/../storage/saas/tenant-$slug.json");
}

$u = substr(bin2hex(random_bytes(3)), 0, 5);
$person = fn($n, $extra = []) => $extra + ['business_name' => "Scoop Shop $n $u", 'owner_name' => "Owner $n", 'email' => "owner$n.$u@example.com",
    'phone' => '98250' . random_int(10000, 99999), 'city' => 'Surat'];

echo "Public pages\n";
[$c, $r] = call($A, 'GET', '/saas/config.php');
check('config: SaaS on, test payments, 14-day trial', $c === 200 && $r['data']['saas'] === true && $r['data']['payment_mode'] === 'mock' && $r['data']['trial_days'] === 14, $r);
[$c, $r] = call($A, 'GET', '/saas/plans.php');
$plans = array_column($r['data'] ?? [], null, 'code');
check('plans: Starter ₹2,999, Growth ₹5,999, Pro ₹11,999, Chain per store', $c === 200 && ($plans['starter']['price_year'] ?? 0) === 2999 && ($plans['growth']['price_year'] ?? 0) === 5999
    && ($plans['pro']['price_year'] ?? 0) === 11999 && ($plans['chain']['per_store'] ?? false) === true, $r);
check('Starter has no orders; Growth has orders', !in_array('orders', $plans['starter']['features'] ?? ['orders'], true) && in_array('orders', $plans['growth']['features'] ?? [], true));
[$c, $r] = call($A, 'GET', '/saas/check-shop-code.php?name=' . rawurlencode("Scoopie'z Ice Cream"));
check('shop code suggested from the name', $c === 200 && preg_match('/^scoopie-z-ice-cream/', $r['code'] ?? ''), $r);
[$c, $r] = call($A, 'GET', '/saas/check-shop-code.php?code=admin');
check('reserved shop code is not available', $c === 200 && $r['available'] === false, $r);
[$c] = call($A, 'GET', '/auth/me.php');
check('shop API without a shop code -> 400 shop_required', $c === 400);
[$c] = call($A, 'OPTIONS', '/auth/signin.php', null, ['Origin' => 'http://127.0.0.1:4190', 'Access-Control-Request-Headers' => 'content-type,x-tenant']);
check('browser preflight (no shop code sent) -> 204', $c === 204);
[$c, $r] = shop('no-such-shop', null, 'POST', '/auth/signin.php', ['email' => 'x@y.z', 'password' => 'x']);
check('unknown shop code -> 404', $c === 404 && ($r['code'] ?? '') === 'shop_not_found', $r);

echo "Free trial sign-up\n";
$codeA = "growth-$u";
[$c, $r] = call($A, 'POST', '/saas/signup.php', $person('A', ['plan' => 'growth', 'trial' => true, 'shop_code' => $codeA, 'gstin' => '24ABCDE1234F1Z5']));
$credA = $r['credentials'] ?? [];
check('trial: shop ready with login details', $c === 200 && ($credA['shop_code'] ?? '') === $codeA && strlen($credA['password'] ?? '') >= 10 && ($credA['status'] ?? '') === 'trial', $r);
check('trial ends in 14 days', ($credA['valid_till'] ?? '') === date('Y-m-d', strtotime('+14 days')), $credA);
$tA = ctl()->query("SELECT * FROM saas_tenants WHERE slug = '$codeA'")->fetch();
check('shop has its own database', $tA && $tA['db_name'] === 'khanak_tt_' . str_replace('-', '_', $codeA) && ctl()->query("SHOW DATABASES LIKE '{$tA['db_name']}'")->fetch(), $tA);
[$c, $r] = call($A, 'POST', '/saas/signup.php', $person('A', ['plan' => 'growth', 'trial' => true, 'shop_code' => $codeA]));
check('same shop code again -> 409', $c === 409, $r);
[$c, $r] = call($A, 'POST', '/saas/signup.php', $person('A', ['plan' => 'growth', 'trial' => true, 'shop_code' => "other-$u"]));
check('second free trial with the same email -> 409', $c === 409 && ($r['code'] ?? '') === 'trial_used', $r);
[$c, $r] = call($A, 'POST', '/saas/signup.php', $person('X', ['plan' => 'growth', 'trial' => true, 'email' => 'not-an-email']));
check('bad email refused', $c === 400, $r);

echo "Login and billing in the new shop\n";
$tokA = login($codeA, $credA['email'], $credA['password']);
check('owner logs in with shop code + emailed password', (bool) $tokA);
[$c, $r] = shop($codeA, $tokA, 'GET', '/auth/me.php');
check('me: Admin of the new shop', $c === 200 && ($r['data']['role_name'] ?? $r['user']['role_name'] ?? '') === 'Admin', $r);
[$c, $r] = shop($codeA, $tokA, 'GET', '/master/store/stores.php?limit=10');
check('store name = business name from sign-up', $c === 200 && ($r['data'][0]['name'] ?? '') === "Scoop Shop A $u", $r['data'][0] ?? $r);
[$c, $r] = shop($codeA, $tokA, 'POST', '/master/product/create-product.php', ['name' => 'Kesar Scoop', 'price' => 80, 'unit' => 'pcs', 'gst_rate' => 5]);
$pid = $r['product_id'] ?? $r['data']['product_id'] ?? null;
check('add product', $c === 200 || $c === 201, $r);
[$c, $r] = shop($codeA, $tokA, 'POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'items' => [['product_id' => $pid, 'quantity' => 2]], 'payment' => ['amount' => 160, 'payment_method' => 'Cash']]);
$invA = $r['invoice_id'] ?? $r['data']['invoice_id'] ?? null;
check('make a bill', ($c === 200 || $c === 201) && $invA, $r);
[$c, $r] = shop($codeA, $tokA, 'GET', '/master/order/orders.php?page=1&limit=5');
check('Growth trial: orders open', $c === 200, $r);
[$c, $r] = shop($codeA, $tokA, 'POST', '/master/invoice/send-bill.php', ['invoice_id' => $invA, 'channels' => []]);
$link = $r['link'] ?? '';
check('bill link carries the shop: /t/<shop>/api/bill.php', str_contains($link, "/t/$codeA/api/bill.php"), $r);
[$c, $page] = call('', 'GET', $link, null, [], true);
check('public bill link opens without login', $c === 200 && str_contains($page, 'Kesar Scoop'), substr($page, 0, 200));
[$c, $r] = shop($codeA, $tokA, 'GET', '/master/plan/status.php');
check('plan & billing: Growth trial with days left, usage', $c === 200 && $r['data']['status'] === 'trial' && $r['data']['plan']['code'] === 'growth'
    && $r['data']['days_left'] >= 13 && $r['data']['usage']['users'] === 1, $r['data'] ?? $r);

echo "Shops are separate\n";
$codeB = "starter-$u";
[$c, $r] = call($A, 'POST', '/saas/signup.php', $person('B', ['plan' => 'starter', 'trial' => true, 'shop_code' => $codeB]));
$credB = $r['credentials'] ?? [];
$tokB = login($codeB, $credB['email'] ?? '', $credB['password'] ?? '');
check('second shop (Starter) ready', (bool) $tokB, $r);
[$c, $r] = shop($codeB, $tokB, 'GET', '/master/product/products.php?page=1&limit=10');
check('shop B does not see shop A products', $c === 200 && (int) ($r['total'] ?? -1) === 0, $r);
[$c, $r] = shop($codeB, $tokA, 'GET', '/master/product/products.php?page=1&limit=10');
check("shop A's login does not work in shop B (401)", $c === 401, $r);
$noA = login($codeB, $credA['email'], $credA['password']);
check("shop A's password does not log in to shop B", $noA === null);

echo "Plan features and limits\n";
[$c, $r] = shop($codeB, $tokB, 'GET', '/master/order/orders.php?page=1&limit=5');
check('Starter: orders refused with an upgrade message (402)', $c === 402 && ($r['code'] ?? '') === 'plan_feature' && str_contains($r['message'] ?? '', 'Starter'), $r);
[$c, $r] = shop($codeB, $tokB, 'GET', '/reports/gstr1.php?month=' . date('Y-m'));
check('Starter: GSTR-1 (Pro) refused', $c === 402, $r);
[$c, $r] = shop($codeB, $tokB, 'GET', '/master/invoice/invoices.php?page=1&limit=5');
check('Starter: billing open', $c === 200, $r);
$roleId = 3;
[$c, $r] = shop($codeB, $tokB, 'POST', '/master/user/create-user.php', ['name' => 'Staff 1', 'email' => "s1.$u@example.com", 'password' => 'Staff@1234', 'role_id' => $roleId, 'store_id' => 1]);
check('Starter: 2nd user allowed (limit 2)', $c === 200 || $c === 201, $r);
[$c, $r] = shop($codeB, $tokB, 'POST', '/master/user/create-user.php', ['name' => 'Staff 2', 'email' => "s2.$u@example.com", 'password' => 'Staff@1234', 'role_id' => $roleId, 'store_id' => 1]);
check('Starter: 3rd user refused (402 plan_limit)', $c === 402 && ($r['code'] ?? '') === 'plan_limit', $r);
[$c, $r] = shop($codeB, $tokB, 'POST', '/master/store/add-store.php', ['name' => 'Second outlet']);
check('Starter: 2nd store refused', $c === 402, $r);

echo "Paid sign-up (test payment)\n";
$codeC = "paid-$u";
$pc = $person('C', ['plan' => 'pro', 'cycle' => 'year', 'shop_code' => $codeC]);
[$c, $r] = call($A, 'POST', '/saas/signup.php', $pc);
$order = $r['order'] ?? [];
check('paid: order ₹11,999 + 18% GST = ₹14,159', $c === 200 && ($order['amount'] ?? 0) === 11999 && ($order['gst'] ?? 0) === 2160 && ($order['total'] ?? 0) === 14159 && ($order['amount_paise'] ?? 0) === 1415900, $r);
check('no shop until paid', !ctl()->query("SELECT 1 FROM saas_tenants WHERE slug = '$codeC'")->fetch());
[$c, $r] = call($A, 'GET', '/saas/check-shop-code.php?code=' . $codeC);
check('shop code held while paying', ($r['available'] ?? true) === false, $r);
[$c, $r] = call($A, 'POST', '/saas/verify-payment.php', ['order_id' => $order['order_id'] ?? '', 'payment_id' => 'pay_fake', 'signature' => 'bad']);
check('wrong payment signature refused', $c === 400, $r);
[$c, $pay] = call($A, 'POST', '/saas/mock-pay.php', ['order_id' => $order['order_id'] ?? '']);
[$c, $r] = call($A, 'POST', '/saas/verify-payment.php', ['order_id' => $pay['order_id'] ?? '', 'payment_id' => $pay['payment_id'] ?? '', 'signature' => $pay['signature'] ?? '']);
$credC = $r['credentials'] ?? [];
check('paid: shop set up, login details given', $c === 200 && ($credC['shop_code'] ?? '') === $codeC && ($credC['status'] ?? '') === 'active'
    && ($credC['valid_till'] ?? '') === date('Y-m-d', strtotime('+1 year')), $r);
[$c, $r] = call($A, 'POST', '/saas/verify-payment.php', ['order_id' => $pay['order_id'] ?? '', 'payment_id' => $pay['payment_id'] ?? '', 'signature' => $pay['signature'] ?? '']);
check('verify again: still OK, password not shown twice, no second shop', $c === 200 && $r['credentials'] === null
    && (int) ctl()->query("SELECT COUNT(*) FROM saas_tenants WHERE slug = '$codeC'")->fetchColumn() === 1, $r);
[$c, $r] = call($A, 'GET', '/saas/order-status.php?order_id=' . rawurlencode($order['order_id'] ?? '') . '&email=' . rawurlencode($pc['email']));
check('order status: paid', $c === 200 && $r['paid'] === true && $r['credentials'] === null, $r);
$inv = ctl()->query("SELECT i.* FROM saas_invoices i JOIN saas_tenants t ON t.tenant_id = i.tenant_id WHERE t.slug = '$codeC'")->fetch();
check('Khanak GST invoice made (KH/yy-yy/0001 series)', $inv && preg_match('#^KH/\d\d-\d\d/\d{4}$#', $inv['invoice_no']) && (int) $inv['total'] === 14159, $inv);
$tokC = login($codeC, $credC['email'] ?? '', $credC['password'] ?? '');
check('paid shop owner logs in', (bool) $tokC);
[$c, $r] = shop($codeC, $tokC, 'GET', '/master/plan/status.php');
$invUrl = $r['data']['invoices'][0]['url'] ?? '';
check('plan & billing shows Pro, active, invoice link', $c === 200 && $r['data']['plan']['code'] === 'pro' && $r['data']['status'] === 'active' && $invUrl !== '', $r['data'] ?? $r);
[$c, $html] = call($A, 'GET', preg_replace('#^.*?/saas/#', '/saas/', $invUrl), null, [], true);
check('invoice page opens (signed link)', $c === 200 && str_contains($html, $inv['invoice_no'] ?? '#') && str_contains($html, 'CGST'), substr($html, 0, 200));
[$c] = call($A, 'GET', '/saas/invoice.php?no=' . rawurlencode($inv['invoice_no'] ?? '') . '&s=wrong');
check('invoice with a wrong signature -> 404', $c === 404);
[$c, $r] = shop($codeC, $tokC, 'GET', '/reports/gstr1.php?month=' . date('Y-m'));
check('Pro: GSTR-1 open', $c === 200, $r);

echo "Plan over, renewal\n";
ctl()->exec("UPDATE saas_tenants SET current_period_end = '" . date('Y-m-d', strtotime('-3 days')) . "' WHERE slug = '$codeC'");
forget($codeC);
[$c, $r] = shop($codeC, $tokC, 'GET', '/master/plan/status.php');
check('3 days after the end: grace (still works)', $r['data']['status'] === 'grace', $r['data'] ?? $r);
[$c, $r] = shop($codeC, $tokC, 'POST', '/master/product/create-product.php', ['name' => 'Grace Cone', 'price' => 40, 'unit' => 'pcs']);
check('grace: saving still allowed', $c === 200 || $c === 201, $r);
ctl()->exec("UPDATE saas_tenants SET current_period_end = '" . date('Y-m-d', strtotime('-10 days')) . "' WHERE slug = '$codeC'");
forget($codeC);
[$c, $r] = shop($codeC, $tokC, 'POST', '/master/product/create-product.php', ['name' => 'Late Cone', 'price' => 40, 'unit' => 'pcs']);
check('expired: saving refused (402 plan_expired)', $c === 402 && ($r['code'] ?? '') === 'plan_expired', $r);
[$c, $r] = shop($codeC, $tokC, 'GET', '/master/product/products.php?page=1&limit=5');
check('expired: data can still be seen', $c === 200 && (int) $r['total'] >= 1, $r);
$tokC2 = login($codeC, $credC['email'], $credC['password']);
check('expired: login still works (to renew)', (bool) $tokC2);
[$c, $r] = shop($codeC, $tokC, 'POST', '/master/plan/create-order.php', ['plan' => 'pro', 'cycle' => 'month']);
$ro = $r['order'] ?? [];
check('renewal order (Pro monthly ₹1,199 + GST)', $c === 200 && ($ro['amount'] ?? 0) === 1199 && ($ro['total'] ?? 0) === 1415, $r);
[$c, $pay] = shop($codeC, $tokC, 'POST', '/master/plan/mock-pay.php', ['order_id' => $ro['order_id'] ?? '']);
[$c, $r] = shop($codeC, $tokC, 'POST', '/master/plan/verify-payment.php', ['order_id' => $pay['order_id'] ?? '', 'payment_id' => $pay['payment_id'] ?? '', 'signature' => $pay['signature'] ?? '']);
check('renewed: active again till +1 month', $c === 200 && $r['data']['status'] === 'active' && $r['data']['valid_till'] === date('Y-m-d', strtotime('+1 month')), $r);
[$c, $r] = shop($codeC, $tokC, 'POST', '/master/product/create-product.php', ['name' => 'Renewed Cone', 'price' => 40, 'unit' => 'pcs']);
check('after renewal: saving works', $c === 200 || $c === 201, $r);
[$c, $r] = shop($codeA, $tokA, 'POST', '/master/plan/verify-payment.php', ['order_id' => $pay['order_id'] ?? '', 'payment_id' => $pay['payment_id'] ?? '', 'signature' => $pay['signature'] ?? '']);
check("shop A cannot use shop C's payment", $c === 404, $r);
$staffTok = login($codeB, "s1.$u@example.com", 'Staff@1234');
[$c, $r] = shop($codeB, $staffTok, 'POST', '/master/plan/create-order.php', ['plan' => 'growth', 'cycle' => 'year']);
check('staff cannot start a plan payment (Admin only)', $c === 403, $r);

echo "Super admin\n";
[$c] = call($A, 'POST', '/saas/admin/login.php', ['email' => 'boss@khanak.test', 'password' => 'wrong']);
check('admin: wrong password -> 401', $c === 401);
[$c, $r] = call($A, 'POST', '/saas/admin/login.php', ['email' => 'boss@khanak.test', 'password' => 'Boss@12345']);
$adm = ['Authorization' => 'Bearer ' . ($r['token'] ?? '')];
check('admin: login', $c === 200 && !empty($r['token']), $r);
[$c] = call($A, 'GET', '/saas/admin/overview.php', null, ['Authorization' => "Bearer $tokA"]);
check("a shop's token is not an admin token (401)", $c === 401);
[$c, $r] = call($A, 'GET', '/saas/admin/overview.php', null, $adm);
check('overview: shops by status, recurring revenue, this month', $c === 200 && $r['data']['total_shops'] >= 3 && $r['data']['shops']['trial'] >= 2 && $r['data']['mrr'] > 0
    && $r['data']['this_month']['collected'] >= 14159 + 1415, $r['data'] ?? $r);
[$c, $r] = call($A, 'GET', '/saas/admin/tenants.php?search=' . $u, null, $adm);
check('tenants list', $c === 200 && $r['total'] >= 3, $r);
$idB = (int) ctl()->query("SELECT tenant_id FROM saas_tenants WHERE slug = '$codeB'")->fetchColumn();
[$c, $r] = call($A, 'GET', "/saas/admin/tenant.php?tenant_id=$idB", null, $adm);
check('tenant detail with history', $c === 200 && $r['data']['shop_code'] === $codeB && count($r['data']['events']) >= 1, $r);
[$c, $r] = call($A, 'POST', '/saas/admin/update-tenant.php', ['tenant_id' => $idB, 'action' => 'extend', 'days' => 10], $adm);
check('extend trial by 10 days', $c === 200 && $r['data']['valid_till'] === date('Y-m-d', strtotime('+24 days')), $r);
[$c, $r] = call($A, 'POST', '/saas/admin/update-tenant.php', ['tenant_id' => $idB, 'action' => 'set_plan', 'plan' => 'growth'], $adm);
[$c2, $r2] = shop($codeB, $tokB, 'GET', '/master/order/orders.php?page=1&limit=5');
check('upgrade to Growth: orders open at once', $c === 200 && $c2 === 200, [$r, $r2]);
[$c, $r] = call($A, 'POST', '/saas/admin/update-tenant.php', ['tenant_id' => $idB, 'action' => 'suspend'], $adm);
[$c2, $r2] = shop($codeB, $tokB, 'GET', '/auth/me.php');
check('suspended shop: everything refused (403)', $c === 200 && $c2 === 403, [$r, $r2]);
call($A, 'POST', '/saas/admin/update-tenant.php', ['tenant_id' => $idB, 'action' => 'activate'], $adm);
[$c2] = shop($codeB, $tokB, 'GET', '/auth/me.php');
check('activated again', $c2 === 200);
[$c, $r] = call($A, 'POST', '/saas/admin/update-tenant.php', ['tenant_id' => $idB, 'action' => 'offline_payment', 'plan' => 'growth', 'cycle' => 'year', 'reference' => 'UTR123456'], $adm);
$tb = ctl()->query("SELECT * FROM saas_tenants WHERE tenant_id = $idB")->fetch();
check('offline payment: trial -> active 1 year, invoice made', $c === 200 && $tb['status'] === 'active' && $tb['current_period_end'] === date('Y-m-d', strtotime('+1 year'))
    && ctl()->query("SELECT COUNT(*) FROM saas_invoices WHERE tenant_id = $idB")->fetchColumn() == 1, [$r, $tb]);
[$c, $r] = call($A, 'POST', '/saas/admin/update-plan.php', ['code' => 'starter', 'price_year' => 3499], $adm);
[, $pl] = call($A, 'GET', '/saas/plans.php');
check('plan price change shows on the pricing page', $c === 200 && array_column($pl['data'], null, 'code')['starter']['price_year'] === 3499, $r);
call($A, 'POST', '/saas/admin/update-plan.php', ['code' => 'starter', 'price_year' => 2999], $adm);
[$c, $r] = call($A, 'GET', '/saas/admin/orders.php?status=paid', null, $adm);
check('orders list with invoice links', $c === 200 && count(array_filter($r['data'], fn($o) => $o['invoice_url'])) >= 3, $r);

echo "Razorpay mode (mock gateway)\n";
[$c, $r] = call($R, 'GET', '/saas/config.php');
check('config: Razorpay with the public key id only', $c === 200 && $r['data']['payment_mode'] === 'razorpay' && $r['data']['razorpay_key_id'] === 'rzp_test_key', $r);
$codeD = "rzp-$u";
[$c, $r] = call($R, 'POST', '/saas/signup.php', $person('D', ['plan' => 'growth', 'cycle' => 'month', 'shop_code' => $codeD]));
$od = $r['order'] ?? [];
check('order created at the gateway', $c === 200 && preg_match('/^order_[0-9a-f]{14}$/', $od['order_id'] ?? '') && ($od['key_id'] ?? '') === 'rzp_test_key', $r);
$payId = 'pay_' . bin2hex(random_bytes(6));
$goodSig = hash_hmac('sha256', ($od['order_id'] ?? '') . '|' . $payId, 'rzp_test_secret');
[$c, $r] = call($R, 'POST', '/saas/verify-payment.php', ['order_id' => $od['order_id'] ?? '', 'payment_id' => $payId, 'signature' => hash_hmac('sha256', 'x', 'rzp_test_secret')]);
check('Razorpay: wrong signature refused', $c === 400, $r);
[$c, $r] = call($R, 'POST', '/saas/verify-payment.php', ['order_id' => $od['order_id'] ?? '', 'payment_id' => $payId, 'signature' => $goodSig]);
check('Razorpay: correct signature -> shop ready', $c === 200 && ($r['credentials']['shop_code'] ?? '') === $codeD, $r);
$codeE = "hook-$u";
$pe = $person('E', ['plan' => 'starter', 'cycle' => 'year', 'shop_code' => $codeE]);
[, $r] = call($R, 'POST', '/saas/signup.php', $pe);
$oe = $r['order']['order_id'] ?? '';
$event = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_hook1', 'order_id' => $oe, 'amount' => 353882]]]]);
[$c] = call($R, 'POST', '/saas/webhook.php', $event, ['X-Razorpay-Signature' => 'bad']);
check('webhook with a wrong signature refused', $c === 400);
[$c] = call($R, 'POST', '/saas/webhook.php', $event, ['X-Razorpay-Signature' => hash_hmac('sha256', $event, 'whsec_test')]);
check('webhook (customer closed the page): shop set up', $c === 200 && ctl()->query("SELECT 1 FROM saas_tenants WHERE slug = '$codeE'")->fetch());
[$c] = call($R, 'POST', '/saas/webhook.php', $event, ['X-Razorpay-Signature' => hash_hmac('sha256', $event, 'whsec_test')]);
check('same webhook again: no second shop or invoice', $c === 200 && (int) ctl()->query("SELECT COUNT(*) FROM saas_invoices i JOIN saas_tenants t ON t.tenant_id = i.tenant_id WHERE t.slug = '$codeE'")->fetchColumn() === 1);
[$c, $r] = call($R, 'GET', '/saas/order-status.php?order_id=' . rawurlencode($oe) . '&email=wrong@example.com');
check('order status with another email: no login details', $c === 200 && $r['paid'] === true && $r['credentials'] === null, $r);
[$c, $r] = call($R, 'GET', '/saas/order-status.php?order_id=' . rawurlencode($oe) . '&email=' . rawurlencode($pe['email']));
check('order status with the sign-up email: login details once', $c === 200 && ($r['credentials']['shop_code'] ?? '') === $codeE, $r);

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
