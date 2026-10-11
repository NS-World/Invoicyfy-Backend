<?php
/**
 * Demo data for testing — an ice-cream parlour with walk-in, delivery and B2B (caterer) sales.
 * CLI only:
 *
 *   php database/seed-demo.php            # adds demo data to the first store
 *   php database/seed-demo.php --days=120 # history length (default 90 days)
 *   php database/seed-demo.php --force    # run even if invoices already exist
 *
 * Uses the same billing code as the API (GST maths, numbering, stock, payments), so the data is
 * consistent with what the app creates. Never run it on a live business database.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['days::', 'force', 'store-id::']);
require_once __DIR__ . '/../lib/bootstrap.php';
set_exception_handler(null);
mt_srand(42);

$days = max(7, min(365, (int) ($opts['days'] ?? 90)));
$storeId = (int) ($opts['store-id'] ?? db_value("SELECT MIN(store_id) FROM stores"));
if (!$storeId) {
    fwrite(STDERR, "No store found. Run php database/migrate.php first.\n");
    exit(1);
}
if (!isset($opts['force']) && (int) db_value("SELECT COUNT(*) FROM invoices WHERE store_id = ?", [$storeId]) > 0) {
    fwrite(STDERR, "Store $storeId already has invoices. Use --force to add demo data anyway (only on a test database!).\n");
    exit(1);
}

function out(string $m): void
{
    echo $m . PHP_EOL;
}

function pick_one(array $a)
{
    return $a[mt_rand(0, count($a) - 1)];
}

/* ---------------- Store profile ---------------- */
$store = get_store($storeId);
if (!$store['gstin']) {
    db_query(
        "UPDATE stores SET name = ?, tagline = ?, legal_name = ?, address = ?, city = ?, state = ?, state_code = ?, pincode = ?,
                phone = ?, email = ?, gstin = ?, pan = ?, fssai_no = ?, invoice_prefix = ?, bank_name = ?, bank_account_name = ?,
                bank_account_no = ?, bank_ifsc = ?, bank_branch = ?, upi_id = ?, invoice_terms = ?, receipt_footer = ?
         WHERE store_id = ?",
        ["Scoopie'z Icecream", "soopie'z", "Scoopiez Foods LLP", "Shop 4, Lake View Complex, Vesu", 'Surat', 'Gujarat', '24', '395007',
         '9723423345', 'hello@scoopiez.in', '24ABCDE1234F1Z5', 'ABCDE1234F', '20724011000376', 'SZ', 'HDFC Bank', 'Scoopiez Foods LLP',
         '50100012345678', 'HDFC0001234', 'Vesu, Surat', 'scoopiez@upi',
         "1. Goods once sold will not be taken back.\n2. Payment due within 15 days.\n3. Subject to Surat jurisdiction.", 'Thanks', $storeId]
    );
    out('~ store profile filled (GSTIN, FSSAI, bank, UPI)');
}
$store = get_store($storeId);

/* ---------------- Users ---------------- */
$roleId = fn($n) => (int) db_value("SELECT role_id FROM roles WHERE role_name = ?", [$n]);
$users = [];
foreach ([['biller', 'biller@demo.com', 'Staff'], ['Manager Mehul', 'manager@demo.com', 'Manager']] as [$name, $email, $role]) {
    $id = db_value("SELECT user_id FROM users WHERE email = ?", [$email]);
    if (!$id && $roleId($role)) {
        $id = insert_row('users', ['name' => $name, 'email' => $email, 'password' => password_hash('Demo@1234', PASSWORD_BCRYPT),
                                   'phone' => null, 'role_id' => $roleId($role), 'store_id' => $storeId]);
        out("+ user $email / Demo@1234 ($role)");
    }
    if ($id) {
        $users[] = load_user((int) $id);
    }
}
if (!$users) {
    $users[] = load_user((int) db_value("SELECT MIN(user_id) FROM users"));
}

/* ---------------- Products ---------------- */
// [name, category, price, cost, gst, inclusive, hsn, unit, opening stock, reorder level]
$catalog = [
    ['Vanilla Scoop', 'Scoops', 60, 22, 18, 1, '2105', 'pcs', 300, 40],
    ['Chocolate Scoop', 'Scoops', 70, 25, 18, 1, '2105', 'pcs', 300, 40],
    ['Butterscotch Scoop', 'Scoops', 70, 25, 18, 1, '2105', 'pcs', 250, 40],
    ['Kesar Pista Scoop', 'Scoops', 90, 34, 18, 1, '2105', 'pcs', 200, 30],
    ['Mango Alphonso Scoop', 'Scoops', 90, 35, 18, 1, '2105', 'pcs', 180, 30],
    ['Belgian Chocolate Sundae', 'Sundaes', 180, 70, 18, 1, '2105', 'pcs', 120, 20],
    ['Brownie Fudge Sundae', 'Sundaes', 210, 85, 18, 1, '2105', 'pcs', 100, 20],
    ['Roasted Dryfruit', 'Sundaes', 230, 95, 18, 1, '2105', 'pcs', 120, 20],
    ['Falooda Royal', 'Shakes', 160, 60, 18, 1, '2105', 'pcs', 150, 25],
    ['Cold Coffee Shake', 'Shakes', 140, 45, 18, 1, '2202', 'pcs', 150, 25],
    ['Oreo Thick Shake', 'Shakes', 170, 60, 18, 1, '2202', 'pcs', 140, 25],
    ['Kulfi Stick Malai', 'Kulfi', 50, 18, 18, 1, '2105', 'pcs', 200, 40],
    ['Family Pack Vanilla 1L', 'Family Packs', 250, 140, 18, 1, '2105', 'pcs', 60, 15],
    ['Family Pack Chocolate 1L', 'Family Packs', 280, 155, 18, 1, '2105', 'pcs', 60, 15],
    ['Ice Cream Tub 4L (Bulk)', 'Bulk', 900, 560, 18, 0, '2105', 'pcs', 40, 8],
    ['Ice Cream Tub 4L Kesar (Bulk)', 'Bulk', 1150, 720, 18, 0, '2105', 'pcs', 30, 8],
    ['Waffle Cone', 'Add-ons', 20, 6, 18, 1, '1905', 'pcs', 400, 80],
    ['Dry Fruits Topping', 'Add-ons', 50, 22, 5, 1, '0813', 'pcs', 300, 50],
    ['Chocolate Sauce Topping', 'Add-ons', 30, 9, 18, 1, '1806', 'pcs', 300, 50],
    ['Party Cups (pack of 50)', 'Packaging', 120, 70, 18, 0, '4823', 'pack', 25, 10],
];
$products = [];
$admin = $users[0];
foreach ($catalog as [$name, $cat, $price, $cost, $gst, $incl, $hsn, $unit, $stock, $reorder]) {
    $id = db_value("SELECT product_id FROM products WHERE name = ? AND store_id = ?", [$name, $storeId]);
    if (!$id) {
        $pdo->beginTransaction();
        $id = insert_row('products', ['store_id' => $storeId, 'name' => $name, 'category' => $cat, 'price' => $price, 'cost_price' => $cost,
            'gst_rate' => $gst, 'price_includes_tax' => $incl, 'hsn_code' => $hsn, 'unit' => $unit, 'track_inventory' => 1,
            'low_stock_threshold' => $reorder, 'status' => 'Active', 'sku' => 'SZ' . str_pad((string) (count($products) + 1), 3, '0', STR_PAD_LEFT)]);
        move_stock((int) $id, $stock, 'opening', null, null, 'Opening stock (demo)', (int) $admin['user_id']);
        $pdo->commit();
    }
    $products[] = db_one("SELECT * FROM products WHERE product_id = ?", [$id]);
}
out('+ ' . count($products) . ' products');

/* ---------------- Customers ---------------- */
$b2c = [['Ravi Patel', '9723423345'], ['Priya Shah', '9825012345'], ['Amit Desai', '9898123456'], ['Neha Mehta', '9427012345'],
        ['Karan Joshi', '9909912345'], ['Sneha Modi', '9824498765'], ['Rahul Trivedi', '9712345678'], ['Pooja Vora', '9601234567'],
        ['Jay Bhatt', '9879876543'], ['Hetal Parikh', '9586012345']];
// [contact, company, gstin, city, state code]
$b2b = [['Mehul Shah', 'Royal Caterers', '24AAKFR1234C1Z9', 'Surat', '24'],
        ['Anita Rao', 'Grand Banquets Pvt Ltd', '24AABCG5678D1Z3', 'Ahmedabad', '24'],
        ['Sameer Kulkarni', 'Pune Party Planners', '27AAGFP9012E1Z7', 'Pune', '27'],
        ['Vikas Jain', 'Hotel Sunrise', '24AADCH3456F1ZX', 'Vadodara', '24']];
$customers = ['b2c' => [], 'b2b' => []];
foreach ($b2c as [$name, $phone]) {
    $id = db_value("SELECT customer_id FROM customers WHERE phone = ?", [$phone])
        ?: insert_row('customers', ['customer_type' => 'B2C', 'name' => $name, 'phone' => $phone, 'city' => 'Surat', 'state' => 'Gujarat', 'state_code' => '24']);
    $customers['b2c'][] = (int) $id;
}
foreach ($b2b as [$contact, $company, $gstin, $city, $sc]) {
    $id = db_value("SELECT customer_id FROM customers WHERE gstin = ?", [$gstin])
        ?: insert_row('customers', ['customer_type' => 'B2B', 'name' => $contact, 'company_name' => $company, 'gstin' => $gstin,
            'phone' => '98' . mt_rand(10000000, 99999999), 'email' => strtolower(str_replace(' ', '', $company)) . '@example.com',
            'address' => 'Plot ' . mt_rand(1, 99) . ', Industrial Area', 'city' => $city, 'state' => state_name($sc), 'state_code' => $sc,
            'pincode' => $sc === '27' ? '411001' : '39' . mt_rand(1000, 9999)]);
    $customers['b2b'][] = (int) $id;
}
out('+ ' . count($b2c) . ' consumer + ' . count($b2b) . ' business customers');

/* ---------------- Sales ---------------- */
$retailProducts = array_values(array_filter($products, fn($p) => !in_array($p['category'], ['Bulk', 'Packaging'], true)));
$bulkProducts = array_values(array_filter($products, fn($p) => in_array($p['category'], ['Bulk', 'Packaging', 'Family Packs'], true)));
$notes = [null, null, null, 'Dry Fruits: Pista - 1x50 = 50', 'Less sugar', 'Extra chocolate sauce', 'Cone instead of cup'];
$methods = ['Cash', 'Cash', 'UPI', 'UPI', 'UPI', 'Card'];
$counts = ['retail' => 0, 'tax' => 0, 'orders' => 0, 'cancelled' => 0];

$restock = function (array $p, string $date) use ($admin, $storeId) {
    $qty = max(50, (float) $p['low_stock_threshold'] * 6);
    move_stock((int) $p['product_id'], $qty, 'purchase', 'manual', null, 'Restock (demo)', (int) $admin['user_id']);
    insert_row('expenses', ['store_id' => $storeId, 'user_id' => $admin['user_id'], 'expense_date' => $date, 'category' => 'Purchase / Raw Material',
        'description' => "Stock purchase: {$p['name']} x $qty", 'vendor' => 'Havmor Distributors', 'amount' => round($qty * $p['cost_price'], 2),
        'tax_amount' => round($qty * $p['cost_price'] * 18 / 118, 2), 'payment_method' => 'Bank Transfer']);
};

for ($d = $days; $d >= 0; $d--) {
    $date = date('Y-m-d', strtotime("-$d days"));
    $weekend = in_array((int) date('N', strtotime($date)), [6, 7], true);
    $bills = mt_rand(6, 10) + ($weekend ? mt_rand(5, 9) : 0);

    for ($b = 0; $b < $bills; $b++) {
        $user = pick_one($users);
        $items = [];
        foreach ((array) array_rand($retailProducts, mt_rand(1, 3)) as $i) {
            $p = $retailProducts[$i];
            $items[] = ['product_id' => $p['product_id'], 'quantity' => mt_rand(1, 3), 'item_note' => pick_one($notes)];
        }
        $walkIn = mt_rand(1, 3) === 1;
        $d0 = ['customer_id' => $walkIn ? null : pick_one($customers['b2c'])];
        $type = pick_one(['Counter', 'Counter', 'Counter', 'Takeaway', 'Delivery']);

        $pdo->beginTransaction();
        $party = resolve_party($d0, $store);
        [$lines, $totals] = gst_calculate(prepare_items($items), (bool) $party['is_interstate']);
        foreach ($lines as $l) {
            $p = db_one("SELECT * FROM products WHERE product_id = ?", [$l['product_id']]);
            if ((float) $p['stock_qty'] < 10) {
                $restock($p, $date);
            }
        }
        $invoiceId = create_invoice_record($store, $user, $party + ['invoice_type' => 'retail', 'invoice_date' => $date,
            'order_type' => $type], $lines, $totals);
        $time = sprintf('%s %02d:%02d:00', $date, mt_rand(12, 22), mt_rand(0, 59));
        db_query("UPDATE invoices SET created_at = ? WHERE invoice_id = ?", [$time, $invoiceId]);
        record_payment(db_one("SELECT * FROM invoices WHERE invoice_id = ?", [$invoiceId]), $user,
            ['amount' => $totals['total_amount'], 'payment_method' => pick_one($methods), 'payment_date' => $date]);
        $pdo->commit();
        $counts['retail']++;
    }

    // Roughly twice a week: a B2B order from a caterer, invoiced as a GST tax invoice.
    if (mt_rand(1, 7) <= 2) {
        $user = $users[count($users) - 1];
        $custId = pick_one($customers['b2b']);
        $items = [];
        foreach ((array) array_rand($bulkProducts, mt_rand(1, 3)) as $i) {
            $items[] = ['product_id' => $bulkProducts[$i]['product_id'], 'quantity' => mt_rand(2, 8),
                        'discount_amount' => mt_rand(0, 1) ? 0 : mt_rand(1, 5) * 50];
        }
        if (mt_rand(0, 1)) {
            $items[] = ['product_name' => 'Delivery charges', 'quantity' => 1, 'unit_price' => 200, 'gst_rate' => 18, 'hsn_code' => '996813'];
        }
        $pdo->beginTransaction();
        $party = resolve_party(['customer_id' => $custId], $store);
        [$lines, $totals] = gst_calculate(prepare_items($items), (bool) $party['is_interstate']);
        foreach ($lines as $l) {
            if ($l['product_id']) {
                $p = db_one("SELECT * FROM products WHERE product_id = ?", [$l['product_id']]);
                if ((float) $p['stock_qty'] < $l['quantity'] + 5) {
                    $restock($p, $date);
                }
            }
        }
        $fy = financial_year($date);
        $orderId = insert_row('orders', [
            'order_number' => sprintf('ORD/%s/%04d', $fy, next_sequence($storeId, 'order', $fy)),
            'customer_id' => $custId, 'customer_name' => $party['customer_name'], 'customer_phone' => $party['customer_phone'],
            'user_id' => $user['user_id'], 'store_id' => $storeId, 'order_date' => $date, 'order_type' => 'B2B',
            'is_interstate' => $party['is_interstate'], 'status' => 'Pending',
        ] + pick($totals, ['subtotal', 'discount_amount', 'taxable_amount', 'tax_amount', 'round_off', 'total_amount']));
        write_order_items($orderId, $lines);
        $counts['orders']++;

        if ($d > 2) { // recent ones stay open so the Orders screen has work in it
            $invoiceId = create_invoice_record($store, $user, $party + ['invoice_type' => 'tax', 'order_id' => $orderId, 'invoice_date' => $date,
                'due_date' => date('Y-m-d', strtotime("$date +15 days")), 'order_type' => 'B2B', 'terms' => $store['invoice_terms']], $lines, $totals);
            db_query("UPDATE orders SET status = 'Invoiced' WHERE order_id = ?", [$orderId]);
            db_query("UPDATE invoices SET created_at = ? WHERE invoice_id = ?", ["$date 11:30:00", $invoiceId]);
            $inv = db_one("SELECT * FROM invoices WHERE invoice_id = ?", [$invoiceId]);
            // Older invoices mostly paid, some partly, recent ones still due (creates outstanding / overdue).
            $r = mt_rand(1, 10);
            if ($d > 20 && $r <= 7) {
                record_payment($inv, $user, ['amount' => $inv['total_amount'], 'payment_method' => 'Bank Transfer',
                    'payment_date' => date('Y-m-d', strtotime("$date +" . mt_rand(3, 14) . ' days')), 'reference_no' => 'UTR' . mt_rand(100000000, 999999999)]);
            } elseif ($d > 10 && $r <= 9) {
                record_payment($inv, $user, ['amount' => round($inv['total_amount'] / 2), 'payment_method' => 'UPI',
                    'payment_date' => date('Y-m-d', strtotime("$date +2 days"))]);
            }
            $counts['tax']++;
        } elseif (mt_rand(0, 1)) {
            db_query("UPDATE orders SET status = 'Approved' WHERE order_id = ?", [$orderId]);
        }
        $pdo->commit();
    }

    // Monthly fixed expenses on the 1st, small daily running costs.
    if (date('j', strtotime($date)) === '1' || $d === $days) {
        foreach ([['Rent', 18000, 'Bank Transfer', 'Lake View Estates'], ['Salary & Wages', 30000, 'Bank Transfer', 'Staff salaries'],
                  ['Electricity', mt_rand(7000, 11000), 'UPI', 'DGVCL'], ['Internet & Phone', 1199, 'UPI', 'Airtel']] as [$cat, $amt, $m, $v]) {
            insert_row('expenses', ['store_id' => $storeId, 'user_id' => $admin['user_id'], 'expense_date' => $date, 'category' => $cat,
                'description' => "$cat - " . date('M Y', strtotime($date)), 'vendor' => $v, 'amount' => $amt,
                'tax_amount' => $cat === 'Internet & Phone' ? round($amt * 18 / 118, 2) : 0, 'payment_method' => $m]);
        }
    }
    if (mt_rand(1, 4) === 1) {
        [$cat, $desc, $amt] = pick_one([['Packaging', 'Cups & spoons', mt_rand(400, 1200)], ['Transport & Delivery', 'Delivery boy fuel', mt_rand(150, 500)],
            ['Repairs & Maintenance', 'Freezer service', mt_rand(800, 2500)], ['Miscellaneous', 'Cleaning supplies', mt_rand(100, 400)],
            ['Marketing', 'Instagram promotion', mt_rand(500, 2000)]]);
        insert_row('expenses', ['store_id' => $storeId, 'user_id' => $admin['user_id'], 'expense_date' => $date, 'category' => $cat,
            'description' => $desc, 'amount' => $amt, 'payment_method' => pick_one(['Cash', 'UPI'])]);
    }
}

// A couple of cancelled bills so the cancelled / GST register views have examples.
foreach (db_all("SELECT invoice_id, invoice_number FROM invoices WHERE store_id = ? AND invoice_type = 'retail' ORDER BY RAND(42) LIMIT 2", [$storeId]) as $inv) {
    $pdo->beginTransaction();
    db_query("DELETE FROM payments WHERE invoice_id = ?", [$inv['invoice_id']]);
    db_query("UPDATE invoices SET status = 'Cancelled', amount_paid = 0, balance_due = 0, cancel_reason = 'Customer changed order (demo)', cancelled_at = NOW() WHERE invoice_id = ?", [$inv['invoice_id']]);
    foreach (db_all("SELECT product_id, quantity FROM invoice_items WHERE invoice_id = ? AND product_id IS NOT NULL", [$inv['invoice_id']]) as $it) {
        move_stock((int) $it['product_id'], (float) $it['quantity'], 'cancel', 'invoice', (int) $inv['invoice_id'], "Cancelled {$inv['invoice_number']}", (int) $admin['user_id']);
    }
    $pdo->commit();
    $counts['cancelled']++;
}

// Leave a few products low on stock so alerts show on the dashboard.
foreach (array_slice($products, 3, 3) as $p) {
    $current = (float) db_value("SELECT stock_qty FROM products WHERE product_id = ?", [$p['product_id']]);
    $target = max(0, (float) $p['low_stock_threshold'] - mt_rand(2, 10));
    if ($current > $target) {
        $pdo->beginTransaction();
        move_stock((int) $p['product_id'], $target - $current, 'adjustment', 'manual', null, 'Stock count correction (demo)', (int) $admin['user_id']);
        $pdo->commit();
    }
}

out("+ {$counts['retail']} retail bills, {$counts['orders']} B2B orders ({$counts['tax']} GST tax invoices), {$counts['cancelled']} cancelled");
out('+ ' . db_value("SELECT COUNT(*) FROM expenses WHERE store_id = ?", [$storeId]) . ' expenses');
out("Done. Login with your admin account, or biller@demo.com / manager@demo.com (password Demo@1234).");
