<?php
/**
 * V3 billing rules shared by quote-bill (preview), create-invoice and orders:
 *  - coupon codes and loyalty points become a bill-level discount spread over the lines, so GST stays correct
 *  - manual discounts above the role limit need a manager's approval PIN
 *  - day-open check, split payments and the cash session a payment belongs to
 * The server always recomputes; amounts sent by the screen are never trusted.
 */

/* ---------------------------------------------------------------- bill-level discount */

/** What the customer pays for a line before round-off (incl. GST). */
function line_payable(array $it): float
{
    $net = round($it['quantity'] * $it['unit_price'], 2) - $it['discount_amount'];
    return $it['price_includes_tax'] ? $net : $net * (1 + $it['gst_rate'] / 100);
}

/**
 * Spread a discount (₹, in "amount the customer pays" terms) over the lines in proportion to each line.
 * For GST-exclusive lines the share is converted to the pre-tax amount, so the bill drops by the discount itself.
 */
function spread_bill_discount(array $items, float $discount): array
{
    $discount = round($discount, 2);
    if ($discount <= 0) {
        return $items;
    }
    $payables = array_map('line_payable', $items);
    $total = array_sum($payables);
    if ($total <= 0) {
        return $items;
    }
    $discount = min($discount, round($total, 2));
    $shares = [];
    $given = 0.0;
    foreach ($payables as $i => $p) {
        $shares[$i] = floor($discount * $p / $total * 100) / 100;
        $given += $shares[$i];
    }
    // Paise left by rounding go to the biggest lines.
    $left = (int) round(($discount - $given) * 100);
    arsort($payables);
    foreach (array_keys($payables) as $i) {
        if ($left <= 0) {
            break;
        }
        $shares[$i] += 0.01;
        $left--;
    }
    foreach ($items as $i => &$it) {
        $share = round($shares[$i], 2);
        $extra = $it['price_includes_tax'] ? $share : round($share / (1 + $it['gst_rate'] / 100), 2);
        $net = round($it['quantity'] * $it['unit_price'], 2) - $it['discount_amount'];
        $it['discount_amount'] = round($it['discount_amount'] + min($extra, max(0, $net)), 2);
    }
    unset($it);
    return $items;
}

/* ---------------------------------------------------------------- coupons */

function normalize_coupon_code(?string $code): string
{
    return strtoupper(preg_replace('/\s+/', '', (string) $code));
}

function coupon_discount_for(array $c, float $bill): float
{
    $d = $c['discount_type'] === 'percent' ? $bill * (float) $c['value'] / 100 : (float) $c['value'];
    if ($c['max_discount'] !== null && $c['max_discount'] !== '' && (float) $c['max_discount'] > 0) {
        $d = min($d, (float) $c['max_discount']);
    }
    return round(min($d, $bill), 2);
}

/**
 * Check a coupon for this bill. Returns [coupon row, discount]. $lock = true inside the saving transaction.
 * Every failure gives a message the cashier can read to the customer.
 */
function validate_coupon(array $store, string $code, ?int $customerId, float $bill, bool $lock = false): array
{
    $code = normalize_coupon_code($code);
    if ($code === '') {
        fail('Enter a coupon code');
    }
    $c = db_one('SELECT * FROM coupons WHERE store_id = ? AND code = ?' . ($lock ? ' FOR UPDATE' : ''), [(int) $store['store_id'], $code]);
    if (!$c) {
        fail("Coupon $code not found", 422);
    }
    if (!(int) $c['is_active']) {
        fail("Coupon $code is switched off", 422);
    }
    $today = date('Y-m-d');
    if ($c['valid_from'] && $today < $c['valid_from']) {
        fail("Coupon $code starts on " . date('d M Y', strtotime($c['valid_from'])), 422);
    }
    if ($c['valid_to'] && $today > $c['valid_to']) {
        fail("Coupon $code expired on " . date('d M Y', strtotime($c['valid_to'])), 422);
    }
    if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) {
        fail("Coupon $code has been fully used", 422);
    }
    if ($c['customer_id'] !== null) {
        if (!$customerId) {
            fail("Coupon $code is for a particular customer. Select the customer first.", 422);
        }
        if ((int) $c['customer_id'] !== $customerId) {
            fail("Coupon $code belongs to another customer", 422);
        }
    }
    if ($c['per_customer_limit'] !== null) {
        if (!$customerId) {
            fail("Select the customer to use coupon $code (it can be used {$c['per_customer_limit']} time(s) per customer)", 422);
        }
        $used = (int) db_value("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND customer_id = ? AND status = 'Used'", [$c['coupon_id'], $customerId]);
        if ($used >= (int) $c['per_customer_limit']) {
            fail("This customer has already used coupon $code", 422);
        }
    }
    if ($bill < (float) $c['min_bill']) {
        fail("Coupon $code needs a bill of at least " . rupees($c['min_bill']), 422);
    }
    $discount = coupon_discount_for($c, $bill);
    if ($discount <= 0) {
        fail("Coupon $code gives no discount on this bill", 422);
    }
    return [$c, $discount];
}

/* ---------------------------------------------------------------- loyalty */

function points_round(float $p): float
{
    return floor($p * 100 + 1e-6) / 100;
}

/** Points a bill earns (whole points). */
function points_to_earn(array $ls, float $total): float
{
    if (!$ls['enabled'] || $total < (float) $ls['min_bill_to_earn'] || $total <= 0) {
        return 0.0;
    }
    return (float) floor($total / 100 * (float) $ls['earn_per_100'] + 1e-6);
}

/**
 * Add / remove points and write the ledger. Must run inside a transaction.
 * Returns the new balance. Balance never goes below zero.
 */
function loyalty_post(int $customerId, ?int $storeId, ?int $invoiceId, string $type, float $points, string $note, ?int $userId): float
{
    $cur = db_one('SELECT loyalty_points FROM customers WHERE customer_id = ? FOR UPDATE', [$customerId]);
    if (!$cur) {
        fail('Customer not found', 404);
    }
    $balance = round(max(0, (float) $cur['loyalty_points'] + $points), 2);
    $applied = round($balance - (float) $cur['loyalty_points'], 2);
    if ($applied == 0.0 && $points != 0.0 && $type !== 'reverse') {
        return $balance;
    }
    db_query('UPDATE customers SET loyalty_points = ? WHERE customer_id = ?', [$balance, $customerId]);
    db_query(
        'INSERT INTO loyalty_ledger (customer_id, store_id, invoice_id, type, points, balance_after, note, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$customerId, $storeId, $invoiceId, $type, $applied, $balance, mb_substr($note, 0, 255), $userId]
    );
    return $balance;
}

/* ---------------------------------------------------------------- discount approval */

/** Manual discount on the bill as % of the full price: line discounts + selling below the product's price. */
function manual_discount_percent(array $items): float
{
    $ids = array_values(array_filter(array_unique(array_map(fn($i) => (int) ($i['product_id'] ?? 0), $items))));
    $prices = [];
    if ($ids) {
        foreach (db_all('SELECT product_id, price FROM products WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p) {
            $prices[(int) $p['product_id']] = (float) $p['price'];
        }
    }
    $full = 0.0;
    $off = 0.0;
    foreach ($items as $it) {
        $list = max($it['unit_price'], $prices[(int) ($it['product_id'] ?? 0)] ?? $it['unit_price']);
        $full += $list * $it['quantity'];
        $off += ($list - $it['unit_price']) * $it['quantity'] + $it['discount_amount'];
    }
    return $full > 0 ? round($off / $full * 100, 2) : 0.0;
}

/** null = no limit (Admin, or a role without a limit). */
function role_discount_limit(array $user): ?float
{
    if (is_admin($user) || !$user['role_id']) {
        return null;
    }
    $v = db_value('SELECT max_discount_percent FROM roles WHERE role_id = ?', [(int) $user['role_id']]);
    return $v === null ? null : (float) $v;
}

/**
 * Returns the approver user id when a PIN was needed, null when the discount is within the user's limit.
 * Fails with code "approval_required" (403) so the screen can ask for a manager PIN, and locks out after 5 wrong PINs.
 */
function check_discount_approval(array $user, int $storeId, float $percent, ?string $pin): ?int
{
    $limit = role_discount_limit($user);
    if ($limit === null || $percent <= $limit + 0.001) {
        return null;
    }
    $extra = ['code' => 'approval_required', 'discount_percent' => $percent, 'limit' => $limit];
    if ($pin === null || $pin === '') {
        fail(sprintf('Discount %.2f%% is above your limit of %.2f%%. A manager must approve it with their PIN.', $percent, $limit), 403, $extra);
    }
    $wrong = (int) db_value(
        "SELECT COUNT(*) FROM activity_log WHERE action = 'approval.failed' AND user_id = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)",
        [(int) $user['user_id']]
    );
    if ($wrong >= 5) {
        fail('Too many wrong PINs. Try again after 15 minutes.', 429, ['code' => 'approval_locked']);
    }
    // Anyone active in this store (or an Admin) whose own limit covers this discount can approve.
    $candidates = db_all(
        "SELECT u.user_id, u.name, u.approval_pin, r.role_name, r.max_discount_percent
         FROM users u LEFT JOIN roles r ON r.role_id = u.role_id
         WHERE u.is_active = 1 AND u.approval_pin IS NOT NULL AND (u.store_id = ? OR r.role_name = 'Admin')",
        [$storeId]
    );
    foreach ($candidates as $c) {
        $covers = strcasecmp((string) $c['role_name'], 'Admin') === 0 || $c['max_discount_percent'] === null || (float) $c['max_discount_percent'] + 0.001 >= $percent;
        if ($covers && password_verify($pin, $c['approval_pin'])) {
            return (int) $c['user_id'];
        }
    }
    log_activity('approval.failed', null, null, sprintf('Wrong approval PIN for a %.2f%% discount', $percent), [], $storeId);
    fail('Wrong PIN, or this person cannot approve such a big discount', 403, ['code' => 'approval_wrong_pin'] + $extra);
    return null;
}

/* ---------------------------------------------------------------- cash session */

function open_cash_session(int $storeId): ?array
{
    try {
        return db_one("SELECT * FROM cash_sessions WHERE store_id = ? AND status = 'Open' ORDER BY session_id DESC LIMIT 1", [$storeId]);
    } catch (PDOException $e) {
        return null; // database not updated to V3 yet
    }
}

/* ---------------------------------------------------------------- the whole bill */

/**
 * Full calculation of a bill with coupon / loyalty. $opts: coupon_code, redeem_points, round_off, lock (inside transaction).
 * Returns lines, totals and what was applied, without saving anything.
 */
function quote_bill(array $store, array $party, array $items, array $opts = []): array
{
    $interstate = (bool) $party['is_interstate'];
    $lock = !empty($opts['lock']);
    // V4: combo / happy-hour offers running now (or when an offline bill was made) come off first.
    $original = $items;
    [$items, $offers, $offerDiscount] = empty($opts['no_offers'])
        ? apply_offers((int) $store['store_id'], $items, $opts['at'] ?? null)
        : [$items, [], 0.0];
    $customerId = $party['customer_id'] ? (int) $party['customer_id'] : null;
    [, $base] = gst_calculate($items, $interstate, false);
    $payable = round($base['taxable_amount'] + $base['tax_amount'], 2);

    $coupon = null;
    $couponDiscount = 0.0;
    $code = normalize_coupon_code($opts['coupon_code'] ?? '');
    if ($code !== '') {
        [$coupon, $couponDiscount] = validate_coupon($store, $code, $customerId, $payable, $lock);
    }

    $ls = loyalty_settings($store);
    $redeem = round(max(0, (float) ($opts['redeem_points'] ?? 0)), 2);
    $loyaltyDiscount = 0.0;
    $balance = null;
    if ($customerId) {
        $balance = (float) db_value('SELECT loyalty_points FROM customers WHERE customer_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$customerId]);
    }
    if ($redeem > 0) {
        if (!$ls['enabled']) {
            fail('Loyalty points are switched off for this store', 422);
        }
        if (!$customerId) {
            fail('Select the customer to redeem points', 422);
        }
        if ($redeem > $balance + 0.001) {
            fail('Customer has only ' . rtrim(rtrim(number_format($balance, 2, '.', ''), '0'), '.') . ' points', 422);
        }
        if ($redeem < (float) $ls['min_redeem_points']) {
            fail("At least {$ls['min_redeem_points']} points are needed to redeem", 422);
        }
        $cap = round(($payable - $couponDiscount) * (float) $ls['max_redeem_percent'] / 100, 2);
        $maxPoints = points_round($cap / (float) $ls['point_value']);
        $redeem = min($redeem, $maxPoints);
        $loyaltyDiscount = round($redeem * (float) $ls['point_value'], 2);
    }

    $priced = spread_bill_discount($items, $couponDiscount + $loyaltyDiscount);
    [$lines, $totals] = gst_calculate($priced, $interstate, $opts['round_off'] ?? true);
    $earn = $customerId ? points_to_earn($ls, $totals['total_amount']) : 0.0;

    return [
        'lines' => $lines,
        'totals' => $totals,
        'offers' => $offers,
        'offer_discount' => $offerDiscount,
        'coupon' => $coupon ? ['coupon_id' => (int) $coupon['coupon_id'], 'code' => $coupon['code'], 'description' => $coupon['description'], 'discount' => $couponDiscount] : null,
        'loyalty' => [
            'enabled' => (bool) $ls['enabled'],
            'balance' => $balance,
            'redeemed_points' => $redeem,
            'discount' => $loyaltyDiscount,
            'earn_points' => $earn,
            'point_value' => (float) $ls['point_value'],
            'min_redeem_points' => (int) $ls['min_redeem_points'],
            'max_redeemable_points' => $customerId && $ls['enabled']
                ? min((float) $balance, points_round(round(($payable - $couponDiscount) * (float) $ls['max_redeem_percent'] / 100, 2) / (float) $ls['point_value']))
                : 0,
        ],
        'manual_discount_percent' => manual_discount_percent($original),
    ];
}

/** Split payments from the screen: [{amount, payment_method, reference_no}], or the old single "payment". */
function payments_from_input(array $d): array
{
    $list = [];
    if (!empty($d['payments']) && is_array($d['payments'])) {
        foreach ($d['payments'] as $i => $p) {
            if (!is_array($p)) {
                fail('Invalid payment at row ' . ($i + 1));
            }
            if ((float) ($p['amount'] ?? 0) > 0) {
                $list[] = $p;
            }
        }
    } elseif (!empty($d['payment']) && is_array($d['payment']) && (float) ($d['payment']['amount'] ?? 0) > 0) {
        $list[] = $d['payment'];
    }
    if (count($list) > 6) {
        fail('A bill can be split into at most 6 payments');
    }
    return $list;
}
