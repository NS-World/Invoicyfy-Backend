<?php
/**
 * V4 API test: suppliers & purchases, recipes, batches / expiry / wastage, combo & happy-hour offers,
 * online platform orders, bill link (WhatsApp / SMS / Email), staff report, owner dashboard, GSTR-1,
 * automatic backup to Google Drive, permissions.
 * Run against a FRESH throwaway database, with the mock server (SMS + Google) on port 8091:
 *
 *   DB_NAME=invoicyfy_v4 php database/migrate.php --admin-email=admin@test.com --admin-password=Admin@1234
 *   php -S 127.0.0.1:8091 tests/mock_v4_router.php          (see the router for what it fakes)
 *   DB_NAME=invoicyfy_v4 GOOGLE_AUTH_URL=http://127.0.0.1:8091/auth GOOGLE_TOKEN_URL=http://127.0.0.1:8091/token \
 *     GOOGLE_DRIVE_API=http://127.0.0.1:8091/drive GOOGLE_DRIVE_UPLOAD=http://127.0.0.1:8091/upload php -S 127.0.0.1:8085 -t .
 *   php tests/api_v4_test.php http://127.0.0.1:8085 admin@test.com Admin@1234 http://127.0.0.1:8091
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
[$_, $base, $email, $password, $mock] = $argv + [null, 'http://127.0.0.1:8085', 'admin@test.com', 'Admin@1234', 'http://127.0.0.1:8091'];
$base = rtrim($base, '/');
$token = null;
$failures = 0;
$checks = 0;

function call(string $method, string $path, ?array $body = null, ?string $as = null, bool $raw = false): array
{
    global $base, $token;
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $base . $path);
    $headers = ['Content-Type: application/json'];
    $t = $as ?? $token;
    if ($t) {
        $headers[] = "Authorization: Bearer $t";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => $raw]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw) {
        return [$code, (string) $res];
    }
    $json = json_decode((string) $res, true);
    return [$code, is_array($json) ? $json : ['_raw' => $res]];
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
        echo '      ' . substr(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 900) . "\n";
    }
}

function login(string $email, string $password): ?string
{
    [, $r] = call('POST', '/auth/signin.php', ['email' => $email, 'password' => $password], '');
    return $r['access_token'] ?? null;
}

function stock(int $pid): float
{
    [, $r] = call('GET', "/master/product/find-product.php?product_id=$pid");
    return (float) ($r['data']['stock_qty'] ?? $r['data'][0]['stock_qty'] ?? -999);
}

$near = fn($a, $b) => abs((float) $a - (float) $b) < 0.011;
$today = date('Y-m-d');

echo "Setup\n";
$token = login($email, $password);
check('admin login', (bool) $token);
[, $me] = call('GET', '/auth/me.php');
$storeId = $me['data']['store_id'];
[, $st] = call('GET', "/master/store/get-store-by-id.php?store_id=$storeId");
$store = $st['data'];
[$c, $r] = call('POST', '/master/store/update-store.php', array_merge($store, ['store_id' => $storeId, 'gstin' => '24AAACI1234A1Z5', 'state' => 'Gujarat', 'state_code' => '24']));
check('store GSTIN set', $c === 200, $r);
$mk = function (array $p) {
    [$c, $r] = call('POST', '/master/product/create-product.php', $p);
    return $r['product_id'] ?? null;
};
$milk = $mk(['name' => 'Milk', 'price' => 70, 'cost_price' => 55, 'gst_rate' => 5, 'unit' => 'ltr', 'stock_qty' => 0, 'category' => 'Raw', 'is_ingredient' => true, 'hsn_code' => '0401']);
$cone = $mk(['name' => 'Waffle Cone', 'price' => 10, 'cost_price' => 3, 'gst_rate' => 18, 'unit' => 'pcs', 'stock_qty' => 0, 'category' => 'Raw', 'hsn_code' => '1905']);
$topping = $mk(['name' => 'Choco Topping', 'price' => 500, 'cost_price' => 400, 'gst_rate' => 18, 'unit' => 'kg', 'stock_qty' => 5, 'category' => 'Raw', 'hsn_code' => '1806']);
$sundae = $mk(['name' => 'Brownie Sundae', 'price' => 150, 'gst_rate' => 5, 'price_includes_tax' => true, 'unit' => 'pcs', 'track_inventory' => false, 'category' => 'Sundae', 'hsn_code' => '2105']);
$scoop = $mk(['name' => 'Vanilla Scoop', 'price' => 100, 'gst_rate' => 5, 'price_includes_tax' => true, 'unit' => 'pcs', 'track_inventory' => false, 'category' => 'Ice Cream', 'hsn_code' => '2105']);
$shake = $mk(['name' => 'Cold Coffee Shake', 'price' => 120, 'gst_rate' => 5, 'price_includes_tax' => true, 'unit' => 'pcs', 'track_inventory' => false, 'category' => 'Shakes', 'hsn_code' => '2202']);
check('products created', $milk && $cone && $topping && $sundae && $scoop && $shake);
[, $pm] = call('GET', "/master/product/find-product.php?product_id=$milk");
check('raw material flag saved on product', (int) ($pm['data']['is_ingredient'] ?? $pm['data'][0]['is_ingredient'] ?? 0) === 1, $pm);

echo "Suppliers\n";
[$c, $r] = call('POST', '/master/supplier/add-supplier.php', ['name' => 'Amul Distributor', 'phone' => '9876500001', 'gstin' => '24AABCA1234B1Z2', 'opening_balance' => 1000]);
$sup = $r['supplier_id'] ?? null;
check('add supplier', $c === 201 && $sup, $r);
check('supplier state taken from GSTIN', ($r['data']['state_code'] ?? '') === '24', $r);
[$c, $r] = call('POST', '/master/supplier/add-supplier.php', ['name' => 'Amul Distributor']);
check('duplicate supplier name rejected (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/supplier/add-supplier.php', ['name' => 'Bad GST', 'gstin' => '123']);
check('invalid supplier GSTIN rejected (400)', $c === 400, $r);
[$c, $r] = call('POST', '/master/supplier/add-supplier.php', ['name' => 'Cone Factory Mumbai', 'state_code' => '27']);
$sup2 = $r['supplier_id'] ?? null;
check('add out-of-state supplier', $c === 201 && $sup2, $r);
[$c, $r] = call('POST', '/master/supplier/update-supplier.php', ['supplier_id' => $sup, 'name' => 'Amul Distributor', 'phone' => '9876500001', 'gstin' => '24AABCA1234B1Z2', 'opening_balance' => 1000, 'contact_person' => 'Ramesh']);
check('update supplier', $c === 200 && ($r['data']['contact_person'] ?? '') === 'Ramesh', $r);

echo "Purchases (stock in, cost, GST input, supplier balance)\n";
$expSoon = date('Y-m-d', strtotime('+2 days'));
[$c, $r] = call('POST', '/master/purchase/create-purchase.php', [
    'supplier_id' => $sup, 'bill_no' => 'AM-101', 'purchase_date' => $today,
    'items' => [
        ['product_id' => $milk, 'quantity' => 10, 'unit_cost' => 50, 'gst_rate' => 5, 'batch_no' => 'M1', 'expiry_date' => $expSoon],
        ['product_id' => $topping, 'quantity' => 2, 'unit_cost' => 380, 'gst_rate' => 18],
    ],
    'payment' => ['amount' => 200, 'payment_method' => 'UPI'],
]);
$pur1 = $r['purchase_id'] ?? null;
// 10*50 = 500 + 5% = 525; 2*380 = 760 + 18% = 896.80; total 1421.80 -> 1422
check('purchase saved', $c === 201 && $pur1, $r);
check('purchase totals with GST (intra-state CGST+SGST)', $near($r['data']['total_amount'] ?? 0, 1422) && $near($r['data']['tax_amount'] ?? 0, 161.80) && $near($r['data']['cgst_amount'] ?? 0, 80.90), $r['data'] ?? $r);
check('purchase part paid', ($r['data']['status'] ?? '') === 'Partial' && $near($r['data']['balance_due'] ?? 0, 1222), $r['data'] ?? $r);
check('milk stock went up by 10', $near(stock($milk), 10));
check('topping stock 5 + 2 = 7', $near(stock($topping), 7));
[, $pm] = call('GET', "/master/product/find-product.php?product_id=$milk");
check('milk cost price updated to 50', $near($pm['data']['cost_price'] ?? $pm['data'][0]['cost_price'] ?? 0, 50), $pm);
[$c, $r] = call('POST', '/master/purchase/create-purchase.php', ['supplier_id' => $sup, 'bill_no' => 'AM-101', 'items' => [['product_id' => $milk, 'quantity' => 1, 'unit_cost' => 50]]]);
check('same supplier bill number twice rejected (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/purchase/create-purchase.php', ['supplier_id' => $sup, 'purchase_date' => date('Y-m-d', strtotime('+3 days')), 'items' => [['product_id' => $milk, 'quantity' => 1, 'unit_cost' => 50]]]);
check('future purchase date rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/purchase/create-purchase.php', ['supplier_id' => $sup, 'items' => []]);
check('purchase without items rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/purchase/create-purchase.php', ['supplier_id' => $sup2, 'bill_no' => 'CF-9', 'items' => [['product_id' => $cone, 'quantity' => 100, 'unit_cost' => 2.5, 'gst_rate' => 18]]]);
$pur2 = $r['purchase_id'] ?? null;
check('out-of-state purchase uses IGST', $c === 201 && $near($r['data']['igst_amount'] ?? 0, 45) && $near($r['data']['cgst_amount'] ?? 1, 0) && $near($r['data']['total_amount'] ?? 0, 295), $r['data'] ?? $r);
check('cone stock 100', $near(stock($cone), 100));
[, $r] = call('GET', "/master/supplier/get-supplier.php?supplier_id=$sup");
check('supplier balance = opening 1000 + 1422 - 200 = 2222', $near($r['data']['balance'] ?? 0, 2222), $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/supplier/add-payment.php', ['supplier_id' => $sup, 'amount' => 1500, 'payment_method' => 'Bank Transfer', 'reference_no' => 'NEFT1']);
check('supplier payment saved', $c === 201, $r);
[, $p1] = call('GET', "/master/purchase/get-purchase.php?purchase_id=$pur1");
check('payment cleared the open bill first (Paid)', ($p1['data']['status'] ?? '') === 'Paid' && $near($p1['data']['balance_due'] ?? 1, 0), $p1['data'] ?? $p1);
check('supplier balance now 722 (rest of payment is advance against opening)', $near($r['data']['balance'] ?? 0, 722), $r);
$billPayId = $p1['data']['payments'][1]['payment_id'] ?? null;
[$c, $r] = call('POST', '/master/supplier/delete-payment.php', ['payment_id' => $billPayId]);
[, $p1] = call('GET', "/master/purchase/get-purchase.php?purchase_id=$pur1");
check('deleting the payment re-opens the bill (Partial)', $c === 200 && ($p1['data']['status'] ?? '') === 'Partial', $p1['data'] ?? $r);
[, $r] = call('GET', '/master/purchase/purchases.php?status=due');
check('purchases list with due filter + input tax summary', ($r['total'] ?? 0) >= 2 && $near($r['summary']['input_tax'] ?? 0, 161.80 + 45), $r['summary'] ?? $r);
[, $r] = call('GET', '/master/supplier/suppliers.php');
// Amul: 1000 + 1422 - (200 + 278 advance) = 1944; Cone Factory: 295
check('suppliers list with balance and total owed', ($r['total'] ?? 0) === 2 && $near($r['total_owed'] ?? 0, 1944 + 295), $r);
[$c, $r] = call('POST', '/master/purchase/cancel-purchase.php', ['purchase_id' => $pur1, 'reason' => 'test']);
check('cancel purchase with payments blocked (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/purchase/update-purchase.php', ['purchase_id' => $pur2, 'bill_no' => 'CF-9A', 'notes' => 'checked']);
check('edit purchase bill number / note', $c === 200 && ($r['data']['bill_no'] ?? '') === 'CF-9A', $r);
[$c, $r] = call('POST', '/master/purchase/create-purchase.php', ['supplier_id' => $sup2, 'bill_no' => 'CF-10', 'items' => [['product_id' => $cone, 'quantity' => 20, 'unit_cost' => 2.5, 'gst_rate' => 18]]]);
$pur3 = $r['purchase_id'] ?? null;
[$c, $r] = call('POST', '/master/purchase/cancel-purchase.php', ['purchase_id' => $pur3, 'reason' => 'wrong entry']);
check('cancel purchase takes stock out again', $c === 200 && $near(stock($cone), 100), $r);

echo "Recipes (ingredients used on sale, real cost)\n";
[$c, $r] = call('POST', '/master/recipe/save-recipe.php', ['product_id' => $sundae, 'items' => [
    ['ingredient_id' => $milk, 'quantity' => 0.2], ['ingredient_id' => $cone, 'quantity' => 1], ['ingredient_id' => $topping, 'quantity' => 0.05],
]]);
check('recipe saved', $c === 200, $r);
[$c, $r] = call('POST', '/master/recipe/save-recipe.php', ['product_id' => $sundae, 'items' => [['ingredient_id' => $sundae, 'quantity' => 1]]]);
check('product cannot be its own ingredient', $c === 400, $r);
[$c, $r] = call('POST', '/master/recipe/save-recipe.php', ['product_id' => $shake, 'items' => [['ingredient_id' => $sundae, 'quantity' => 1]]]);
check('nested recipe rejected', $c === 400, $r);
[, $r] = call('GET', "/master/recipe/get-recipe.php?product_id=$sundae");
// 0.2*50 + 1*2.5 + 0.05*380 = 10 + 2.5 + 19 = 31.50 ; price before tax 150/1.05 = 142.86
check('recipe cost from latest purchase rates', $near($r['data']['recipe_cost'] ?? 0, 31.5) && $near($r['data']['profit'] ?? 0, 111.36), $r['data'] ?? $r);
[, $r] = call('GET', '/master/recipe/recipes.php');
check('recipes list with margin', ($r['total'] ?? 0) === 1 && ($r['data'][0]['margin_percent'] ?? 0) > 70, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $sundae, 'quantity' => 2]], 'payment' => ['amount' => 300, 'payment_method' => 'Cash']]);
$sundaeBill = $r['invoice_id'] ?? null;
check('bill 2 sundaes', $c === 201, $r);
check('milk 10 - 0.4 = 9.6', $near(stock($milk), 9.6));
check('cones 100 - 2 = 98', $near(stock($cone), 98));
check('topping 7 - 0.1 = 6.9', $near(stock($topping), 6.9));
check('bill line cost = recipe cost (31.50)', $near($r['data']['items'][0]['cost_price'] ?? 0, 31.5), $r['data']['items'] ?? $r);
[$c, $r] = call('POST', '/master/invoice/cancel-invoice.php', ['invoice_id' => $sundaeBill, 'reason' => 'test']);
check('cancel bill gives ingredients back', $c === 200 && $near(stock($milk), 10) && $near(stock($cone), 100) && $near(stock($topping), 7), $r);

echo "Batches, expiry and wastage\n";
[, $r] = call('GET', "/master/batch/batches.php?product_id=$milk");
$batch = $r['data'][0] ?? null;
check('milk batch from purchase (FEFO used and refilled on cancel)', $batch && $near($batch['qty_left'], 10) && $batch['batch_no'] === 'M1', $r);
[, $r] = call('GET', '/master/batch/expiring.php');
check('expiring list shows the milk batch (alert days 3)', ($r['total'] ?? 0) === 1 && ($r['data'][0]['days_left'] ?? -1) === 2, $r);
[$c, $r] = call('POST', '/master/inventory/add-wastage.php', ['product_id' => $milk, 'quantity' => 1.5, 'reason' => 'Expired', 'batch_id' => $batch['batch_id'] ?? 0, 'notes' => 'smelled sour']);
check('wastage saved with loss', $c === 201 && $near($r['loss'] ?? 0, 75) && $near(stock($milk), 8.5), $r);
[$c, $r] = call('POST', '/master/inventory/add-wastage.php', ['product_id' => $milk, 'quantity' => 1, 'reason' => 'Lost']);
check('unknown wastage reason rejected', $c === 400, $r);
[, $r] = call('GET', "/master/batch/batches.php?product_id=$milk");
check('wastage reduced that batch', $near($r['data'][0]['qty_left'] ?? 0, 8.5), $r);
[, $r] = call('GET', "/master/inventory/wastage.php?from=$today&to=$today");
check('wastage report total and by reason', $near($r['summary']['loss'] ?? 0, 75) && $near($r['summary']['by_reason']['Expired'] ?? 0, 75), $r['summary'] ?? $r);
[$c, $r] = call('POST', '/master/inventory/adjust-stock.php', ['product_id' => $cone, 'type' => 'purchase', 'quantity' => 50, 'unit_cost' => 2.6, 'batch_no' => 'C2', 'expiry_date' => date('Y-m-d', strtotime('+90 days'))]);
[, $r2] = call('GET', "/master/batch/batches.php?product_id=$cone");
check('stock-in with expiry creates a batch', $c === 200 && ($r2['total'] ?? 0) === 1 && $near(stock($cone), 150), [$r, $r2]);

echo "Combo & happy-hour offers\n";
[$c, $r] = call('POST', '/master/offer/add-offer.php', ['name' => 'Shake Happy Hour', 'offer_type' => 'happy_hour', 'discount_percent' => 20, 'applies_to' => 'category', 'target_ids' => ['Shakes']]);
$hh = $r['offer_id'] ?? null;
check('happy-hour offer created (all day)', $c === 201 && ($r['data']['running_now'] ?? false), $r);
[$c, $r] = call('POST', '/master/offer/add-offer.php', ['name' => 'Bad Combo', 'offer_type' => 'combo', 'combo_items' => [['product_id' => $scoop, 'qty' => 2]], 'combo_price' => 250]);
check('combo price above normal price rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/offer/add-offer.php', ['name' => '2 Scoop + Shake', 'offer_type' => 'combo', 'combo_items' => [['product_id' => $scoop, 'qty' => 2], ['product_id' => $shake, 'qty' => 1]], 'combo_price' => 199]);
$combo = $r['offer_id'] ?? null;
check('combo offer created', $c === 201 && $combo, $r);
$tomorrowOnly = (string) ((date('w') + 1) % 7);
[$c, $r] = call('POST', '/master/offer/add-offer.php', ['name' => 'Tomorrow 50%', 'offer_type' => 'happy_hour', 'discount_percent' => 50, 'applies_to' => 'all', 'days' => $tomorrowOnly]);
check('offer for another weekday is not running now', $c === 201 && !($r['data']['running_now'] ?? true), $r);
[, $r] = call('GET', '/master/offer/active-offers.php');
check('active offers for the billing screen (2 running)', count($r['data'] ?? []) === 2, $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', ['store_id' => $storeId, 'items' => [['product_id' => $shake, 'quantity' => 1]]]);
check('happy hour: shake 120 -> 96', $near($r['data']['totals']['total_amount'] ?? 0, 96) && $near($r['data']['offer_discount'] ?? 0, 24), $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/invoice/quote-bill.php', ['store_id' => $storeId, 'items' => [['product_id' => $shake, 'quantity' => 1]], 'no_offers' => true]);
check('offers can be switched off for one bill', $near($r['data']['totals']['total_amount'] ?? 0, 120), $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId,
    'items' => [['product_id' => $scoop, 'quantity' => 2], ['product_id' => $shake, 'quantity' => 1], ['product_id' => $scoop, 'quantity' => 1]], 'payment' => ['amount' => 299, 'payment_method' => 'Cash']]);
// shake 96 after happy hour; combo of 2 scoops (200) + shake (96) = 296 -> 199, saving 97; third scoop stays 100: total 299
check('combo + happy hour applied on the bill (299)', $c === 201 && $near($r['data']['total_amount'] ?? 0, 299), $r['data'] ?? $r);
check('offer discount and names saved on the bill', $near($r['data']['offer_discount'] ?? 0, 121) && str_contains((string) ($r['data']['offer_names'] ?? ''), '2 Scoop + Shake'), $r['data'] ?? $r);
$comboBill = $r['invoice_id'] ?? null;
[, $r] = call('GET', '/master/offer/offers.php');
$used = array_column($r['data'] ?? [], 'used_count', 'offer_id');
check('offer use counted', ($used[$combo] ?? 0) === 1 && ($used[$hh] ?? 0) === 1, $r);

echo "Staff limit is not hit by automatic offers\n";
[, $roles] = call('GET', '/master/role/roles.php?page=1&limit=10');
$staffRole = null;
foreach ($roles['data'] as $ro) {
    if ($ro['role_name'] === 'Staff') {
        $staffRole = $ro['role_id'];
    }
}
call('POST', '/master/role/update-role.php', ['role_id' => $staffRole, 'role_name' => 'Staff', 'max_discount_percent' => 5]);
[$c, $r] = call('POST', '/master/user/create-user.php', ['name' => 'Cashier', 'email' => 'cashier4@test.com', 'password' => 'Cashier@123', 'role_id' => $staffRole, 'store_id' => $storeId]);
$staffTok = login('cashier4@test.com', 'Cashier@123');
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $shake, 'quantity' => 1]], 'payment' => ['amount' => 96, 'payment_method' => 'UPI']], $staffTok);
check('cashier with 5% limit bills a 20% happy-hour item without PIN', $c === 201 && $near($r['data']['total_amount'] ?? 0, 96), $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'items' => [['product_id' => $scoop, 'quantity' => 1, 'discount_amount' => 20]]], $staffTok);
check('manual 20% discount by cashier still needs PIN', $c === 403 && ($r['code'] ?? '') === 'approval_required', $r);
[$c, $r] = call('POST', '/master/offer/update-offer.php', ['offer_id' => $hh, 'name' => 'Shake Happy Hour', 'offer_type' => 'happy_hour', 'discount_percent' => 20, 'applies_to' => 'category', 'target_ids' => ['Shakes'], 'is_active' => false]);
[$c2, $r2] = call('POST', '/master/invoice/quote-bill.php', ['store_id' => $storeId, 'items' => [['product_id' => $shake, 'quantity' => 1]]]);
check('switched-off offer no longer applies', $c === 200 && $near($r2['data']['totals']['total_amount'] ?? 0, 120), $r2['data'] ?? $r2);
[$c, $r] = call('POST', '/master/offer/add-offer.php', ['name' => 'x', 'offer_type' => 'happy_hour', 'discount_percent' => 10], $staffTok);
check('cashier cannot create offers (403)', $c === 403, $r);

echo "Online platform orders\n";
[, $pos] = call('GET', '/master/pos/pos-settings.php');
check('default platforms Swiggy / Zomato at 25%', ($pos['data']['online_platforms'][0]['name'] ?? '') === 'Swiggy', $pos);
[$c, $r] = call('POST', '/master/pos/update-pos-settings.php', ['online_platforms' => [['name' => 'Swiggy', 'commission' => 20], ['name' => 'Zomato', 'commission' => 22], ['name' => 'Own Website', 'commission' => 0]]]);
check('platforms and commission saved', $c === 200 && count($r['data']['online_platforms'] ?? []) === 3, $r);
[$c, $r] = call('POST', '/master/pos/update-pos-settings.php', ['online_platforms' => [['name' => 'A', 'commission' => 80]]]);
check('commission above 60% rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'sales_channel' => 'Swiggy', 'channel_order_id' => 'SW-5001', 'items' => [['product_id' => $sundae, 'quantity' => 2]]]);
$sw = $r['invoice_id'] ?? null;
check('Swiggy order billed: paid by platform, 20% commission noted', $c === 201 && ($r['data']['status'] ?? '') === 'Paid' && ($r['data']['payments'][0]['payment_method'] ?? '') === 'Online Platform' && $near($r['data']['channel_commission'] ?? 0, 60) && ($r['data']['order_type'] ?? '') === 'Online', $r['data'] ?? $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'sales_channel' => 'Swiggy', 'channel_order_id' => 'SW-5001', 'items' => [['product_id' => $scoop, 'quantity' => 1]]]);
check('same Swiggy order number twice rejected (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'sales_channel' => 'Uber Eats', 'items' => [['product_id' => $scoop, 'quantity' => 1]]]);
check('unknown platform rejected (422)', $c === 422, $r);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'sales_channel' => 'zomato', 'channel_order_id' => 'Z77', 'items' => [['product_id' => $scoop, 'quantity' => 2]]]);
$zo = $r['invoice_id'] ?? null;
check('Zomato order (name matched without case)', $c === 201 && ($r['data']['sales_channel'] ?? '') === 'Zomato' && $near($r['data']['channel_commission'] ?? 0, 44), $r['data'] ?? $r);
[, $r] = call('GET', "/master/online/online-orders.php?from=$today&to=$today");
$sum = array_column($r['summary'] ?? [], null, 'platform');
check('online summary per platform', ($sum['Swiggy']['orders'] ?? 0) === 1 && $near($sum['Swiggy']['expected'] ?? 0, 240) && $near($sum['Zomato']['pending'] ?? 0, 156), $r['summary'] ?? $r);
[$c, $r] = call('POST', '/master/online/mark-settled.php', ['invoice_ids' => [$sw], 'amount_received' => 230, 'settled_at' => $today]);
[, $r2] = call('GET', "/master/online/online-orders.php?from=$today&to=$today&platform=Swiggy");
check('payout received: real commission = 300 - 230 = 70', $c === 200 && $near($r2['data'][0]['settled_amount'] ?? 0, 230) && $near($r2['data'][0]['channel_commission'] ?? 0, 70), $r2['data'] ?? $r2);
[$c, $r] = call('POST', '/master/online/mark-settled.php', ['invoice_ids' => [$comboBill]]);
check('counter bill cannot be settled as online (422)', $c === 422, $r);
[$c, $r] = call('POST', '/master/online/mark-settled.php', ['invoice_ids' => [$zo]], $staffTok);
check('cashier without invoice edit cannot settle (403)', $c === 403, $r);
[$c, $r] = call('POST', '/master/online/mark-settled.php', ['invoice_ids' => [$sw], 'undo' => true]);
[, $r2] = call('GET', "/master/online/online-orders.php?from=$today&to=$today&settled=no");
check('undo settlement', $c === 200 && ($r2['total'] ?? 0) === 2, $r2);

echo "Bill link on WhatsApp / SMS\n";
[$c, $r] = call('POST', '/master/invoice/send-bill.php', ['invoice_id' => $comboBill]);
$link = $r['link'] ?? '';
check('bill link and ready text', $c === 200 && str_contains($link, '/bill.php?i=') && str_contains($r['text'] ?? '', $link), $r);
[$c, $html] = call('GET', $link, null, '', true);
check('public bill page opens without login', $c === 200 && str_contains($html, 'Vanilla Scoop') && str_contains($html, 'text/html'), substr($html, 0, 300));
[$c, $html] = call('GET', preg_replace('/s=\w+/', 's=deadbeefdeadbeefdeadbeef', $link), null, '', true);
check('tampered bill link refused (404)', $c === 404, substr($html, 0, 200));
[$c, $html] = call('GET', str_replace("i=$comboBill", 'i=' . ($comboBill - 1), $link), null, '', true);
check('another bill id with this signature refused', $c === 404);
[$c, $r] = call('POST', '/master/campaign/update-channel-settings.php', ['store_id' => $storeId, 'sms' => ['provider' => 'custom', 'custom_url' => "$mock/sms?to={phone}&text={message}", 'custom_method' => 'GET']]);
check('SMS gateway set (mock)', $c === 200, $r);
[$c, $r] = call('POST', '/master/pos/update-pos-settings.php', ['auto_send_bill' => true, 'bill_channels' => ['sms', 'whatsapp'], 'bill_message' => 'Thanks {name}! Bill {bill_no} Rs {amount}: {link}']);
check('auto-send bill switched on', $c === 200 && $r['data']['auto_send_bill'] === true, $r);
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Neha', 'phone' => '9812300099']);
$neha = $r['customer_id'] ?? null;
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'customer_id' => $neha, 'items' => [['product_id' => $scoop, 'quantity' => 1]], 'payment' => ['amount' => 100, 'payment_method' => 'Cash']]);
check('bill saved and message queued (WhatsApp skipped: API not set up)', $c === 201 && ($r['bill_message'] ?? '') === 'queued', $r);
$nehaBill = $r['invoice_number'] ?? '';
call('POST', '/jobs/tick.php', []);
[, $ms] = call('GET', "$mock/state", null, '');
$sms = end($ms['sms']) ?: [];
check('SMS with the bill link reached the gateway', ($sms['to'] ?? '') === '919812300099' && str_contains($sms['text'] ?? '', "Bill $nehaBill") && str_contains($sms['text'] ?? '', '/bill.php?i='), $sms);
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'retail', 'store_id' => $storeId, 'customer_id' => $neha, 'send_bill' => false, 'items' => [['product_id' => $scoop, 'quantity' => 1]], 'payment' => ['amount' => 100, 'payment_method' => 'Cash']]);
check('send_bill=false skips the message', $c === 201 && ($r['bill_message'] ?? null) === null, $r);
[, $r] = call('GET', '/master/campaign/campaigns.php?page=1&limit=20');
$billCamps = array_values(array_filter($r['data'] ?? [], fn($x) => ($x['kind'] ?? '') === 'bill'));
check('bill messages collected in one campaign per day', count($billCamps) === 1, $r['data'] ?? $r);
call('POST', '/master/pos/update-pos-settings.php', ['auto_send_bill' => false]);

echo "B2B bill for GSTR-1\n";
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Hotel Sagar', 'customer_type' => 'B2B', 'gstin' => '24AAFCS1234M1Z9', 'state' => 'Gujarat']);
$b2bCust = $r['customer_id'] ?? null;
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'tax', 'store_id' => $storeId, 'customer_id' => $b2bCust, 'no_offers' => true, 'items' => [['product_id' => $scoop, 'quantity' => 10]]]);
check('B2B tax invoice', $c === 201, $r);
[$c, $r] = call('POST', '/master/customer/add-customer.php', ['name' => 'Delhi Party', 'state' => 'Delhi']);
$delhi = $r['customer_id'] ?? null;
[$c, $r] = call('POST', '/master/invoice/create-invoice.php', ['invoice_type' => 'tax', 'store_id' => $storeId, 'customer_id' => $delhi, 'no_offers' => true, 'items' => [['product_id' => $scoop, 'quantity' => 1100]]]);
check('inter-state B2C bill above 1 lakh (B2C large)', $c === 201 && ($r['data']['is_interstate'] ?? 0) === 1 && $r['data']['total_amount'] > 100000, $r['data'] ?? $r);

echo "GSTR-1\n";
[$c, $g] = call('GET', '/reports/gstr1.php?month=' . date('Y-m'));
check('GSTR-1 generated', $c === 200 && ($g['json']['gstin'] ?? '') === '24AAACI1234A1Z5' && ($g['json']['fp'] ?? '') === date('mY'), $g['warnings'] ?? $g);
check('B2B section has the hotel bill', ($g['json']['b2b'][0]['ctin'] ?? '') === '24AAFCS1234M1Z9' && $near($g['json']['b2b'][0]['inv'][0]['itms'][0]['itm_det']['txval'] ?? 0, 952.38), $g['json']['b2b'] ?? $g);
check('B2C large section (inter-state > 1 lakh) with IGST', ($g['json']['b2cl'][0]['pos'] ?? '') === '07' && ($g['json']['b2cl'][0]['inv'][0]['itms'][0]['itm_det']['iamt'] ?? 0) > 0, $g['json']['b2cl'] ?? $g);
$b2csTx = array_sum(array_column($g['json']['b2cs'] ?? [], 'txval'));
[, $gs] = call('GET', '/reports/gst-summary.php?from=' . date('Y-m-01') . '&to=' . date('Y-m-t'));
check('GSTR-1 taxable total = GST summary taxable', $near($g['summary']['taxable'] ?? 0, $gs['totals']['taxable_value'] ?? -1), [$g['summary'] ?? null, $gs['totals'] ?? null]);
check('B2C small grouped by rate', $b2csTx > 0 && count(array_unique(array_column($g['json']['b2cs'], 'rt'))) >= 1, $g['json']['b2cs'] ?? null);
check('HSN split into B2B and B2C', !empty($g['json']['hsn']['hsn_b2b']) && !empty($g['json']['hsn']['hsn_b2c']) && ($g['json']['hsn']['hsn_b2c'][0]['uqc'] ?? '') !== '', $g['json']['hsn'] ?? null);
check('documents issued counts cancelled bills', ($g['summary']['cancelled'] ?? 0) >= 1 && !empty($g['json']['doc_issue']['doc_det'][0]['docs']), $g['json']['doc_issue'] ?? null);
check('Excel sheets prepared', isset($g['sheets']['b2b'], $g['sheets']['b2cs'], $g['sheets']['hsn(b2c)'], $g['sheets']['docs']), array_keys($g['sheets'] ?? []));
check('GST summary counts purchase GST as input credit', $near($gs['input_tax_purchases'] ?? 0, 206.80), $gs);
[$c, $r] = call('GET', '/reports/gstr1.php?month=2026-13');
check('bad month rejected', $c === 400, $r);
[$c, $r] = call('GET', '/reports/gstr1.php', null, $staffTok);
check('cashier cannot download GSTR-1 (403)', $c === 403, $r);

echo "Staff report & owner dashboard\n";
[$c, $r] = call('GET', "/reports/staff.php?from=$today&to=$today");
$byName = array_column($r['staff'] ?? [], null, 'name');
check('staff report per cashier', $c === 200 && ($byName['Cashier']['bills'] ?? 0) === 1 && $near($byName['Cashier']['sales'] ?? 0, 96) && ($byName['Cashier']['by_method']['UPI'] ?? 0) == 96, $r['staff'] ?? $r);
check('staff report counts who cancelled bills', ($byName['Admin']['cancelled_bills'] ?? 0) >= 1 && $near($byName['Admin']['offer_discount'] ?? 0, 121), $byName['Admin'] ?? $r);
[$c, $r] = call('GET', '/reports/dashboard/owner.php');
check('owner dashboard', $c === 200 && ($r['data']['today']['bills'] ?? 0) >= 5 && count($r['data']['hourly'] ?? []) === 24 && !empty($r['data']['top_items']), $r['data']['today'] ?? $r);
check('owner dashboard: attention items', isset($r['data']['attention']['expiring']['batches'], $r['data']['attention']['supplier_dues'], $r['data']['attention']['online_pending']) && $r['data']['attention']['expiring']['batches'] === 1, $r['data']['attention'] ?? $r);
[$c, $r] = call('GET', '/reports/dashboard/owner.php', null, $staffTok);
check('cashier with dashboard access can open it', $c === 200, $r);

echo "Permissions\n";
[$c] = call('GET', '/master/supplier/suppliers.php', null, $staffTok);
check('cashier cannot see suppliers (403)', $c === 403);
[$c] = call('POST', '/master/purchase/create-purchase.php', ['supplier_id' => $sup], $staffTok);
check('cashier cannot add purchases (403)', $c === 403);
[$c] = call('POST', '/master/recipe/save-recipe.php', ['product_id' => $sundae, 'items' => []], $staffTok);
check('cashier cannot change recipes (403)', $c === 403);
[$c] = call('GET', '/master/offer/active-offers.php', null, $staffTok);
check('cashier sees running offers while billing', $c === 200);
[$c] = call('GET', '/master/backup/drive-settings.php', null, $staffTok);
check('cashier cannot open backup settings (403)', $c === 403);
[$c] = call('GET', '/master/supplier/suppliers.php', null, '');
check('no token = 401', $c === 401);

echo "Automatic backup + Google Drive\n";
[$c, $r] = call('POST', '/master/backup/create-backup-now.php', []);
check('backup on the server without Drive', $c === 200 && !empty($r['data']['local_files']), $r);
$file = $r['data']['local_files'][0]['name'] ?? '';
[$c, $gz] = call('GET', '/master/backup/download-backup.php?file=' . rawurlencode($file), null, null, true);
$bodyGz = substr($gz, strpos($gz, "\r\n\r\n") + 4);
$json = json_decode((string) @gzdecode($bodyGz), true);
check('backup file downloads and holds every table', $c === 200 && ($json['format'] ?? '') === 'invoicyfy-backup' && count($json['tables']['suppliers']['rows'] ?? []) === 2 && count($json['tables']['invoices']['rows'] ?? []) >= 8, array_keys($json['tables'] ?? []));
[$c] = call('GET', '/master/backup/download-backup.php?file=' . rawurlencode('../../.env'));
check('path tricks in file name refused', $c === 404);
[$c, $r] = call('GET', '/master/backup/drive-connect.php?return=' . rawurlencode('http://127.0.0.1:4173/backup'));
check('connect needs client id / secret first', $c === 422, $r);
[$c, $r] = call('POST', '/master/backup/update-drive-settings.php', ['client_id' => 'not-valid']);
check('wrong client id format rejected', $c === 400, $r);
[$c, $r] = call('POST', '/master/backup/update-drive-settings.php', ['enabled' => true, 'time' => '00:00', 'client_id' => '1234567890-abc.apps.googleusercontent.com', 'client_secret' => 'test-secret', 'keep' => 2]);
check('drive settings saved, secret not returned', $c === 200 && ($r['data']['client_secret_set'] ?? false) && !isset($r['data']['client_secret']) && str_contains($r['data']['redirect_uri'] ?? '', '/master/backup/drive-callback.php'), $r);
[$c, $r] = call('GET', '/master/backup/drive-connect.php?return=' . rawurlencode('http://127.0.0.1:4173/backup'));
parse_str((string) parse_url($r['url'] ?? '', PHP_URL_QUERY), $q);
check('Google sign-in link with drive.file scope and offline access', $c === 200 && ($q['scope'] ?? '') === 'https://www.googleapis.com/auth/drive.file' && ($q['access_type'] ?? '') === 'offline' && !empty($q['state']), $r);
[$c, $raw] = call('GET', '/master/backup/drive-callback.php?code=good-code&state=' . rawurlencode(substr($q['state'], 0, -2) . 'xx'), null, '', true);
check('callback with forged state refused', $c === 400);
[$c, $raw] = call('GET', '/master/backup/drive-callback.php?code=good-code&state=' . rawurlencode($q['state']), null, '', true);
check('callback connects Drive and returns to the app', $c === 302 && str_contains($raw, 'Location: http://127.0.0.1:4173/backup?drive=connected'), substr($raw, 0, 400));
[, $r] = call('GET', '/master/backup/drive-settings.php');
check('Drive connected as owner@example.com', ($r['data']['connected'] ?? false) && ($r['data']['email'] ?? '') === 'owner@example.com', $r);
for ($i = 0; $i < 3; $i++) {
    [$c, $r] = call('POST', '/master/backup/create-backup-now.php', []);
    sleep(1);
}
check('backup uploaded to Drive', $c === 200 && str_contains($r['message'] ?? '', 'uploaded to Google Drive'), $r);
[, $ms] = call('GET', "$mock/state", null, '');
$live = array_values(array_filter($ms['files'], fn($f) => empty($f['folder']) && empty($f['deleted'])));
check('only the newest 2 backups kept on Drive', count($live) === 2, $ms['files']);
$up = $live[0] ?? [];
check('uploaded file has the full size', ($up['size'] ?? 0) > 1000, $up);
[$c, $r] = call('POST', '/jobs/tick.php', []);
[, $r2] = call('GET', '/master/backup/drive-settings.php');
check('nightly backup runs from the scheduler once a day', in_array('backup started', $r['ran'] ?? [], true) && ($r2['data']['last_ok'] ?? false), [$r, $r2['data']['last_result'] ?? null]);
[$c, $r] = call('POST', '/jobs/tick.php', []);
check('... and not twice the same day', !in_array('backup started', $r['ran'] ?? [], true), $r);
[$c, $r] = call('POST', '/master/backup/drive-disconnect.php', []);
check('disconnect Drive', $c === 200 && !($r['data']['connected'] ?? true), $r);
[, $r] = call('GET', '/master/backup/tables.php');
check('Backup screen lists the new tables', in_array('suppliers', array_column($r['data']['tables'] ?? [], 'table'), true), $r);

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
