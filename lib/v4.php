<?php
/**
 * V4 rules shared by billing, purchases and stock:
 *  - recipes: selling a sundae uses up milk, cone, topping (and shows the real cost / profit)
 *  - batches + expiry: stock received with an expiry date is used first-expiry-first (FEFO)
 *  - combo and happy-hour offers applied to the bill automatically
 *  - bill link for WhatsApp / SMS / Email
 *
 * Every function checks that "Update database" has added the V4 tables, so bills keep working before that.
 */

const WASTAGE_REASONS = ['Expired', 'Melted', 'Damaged', 'Spilled', 'Staff use', 'Sample', 'Other'];

/** True when the V4 tables exist (checked once per request). */
function v4_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db_value('SELECT 1 FROM recipe_items LIMIT 1');
            db_value('SELECT 1 FROM stock_batches LIMIT 1');
            db_value('SELECT 1 FROM offers LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function qty_text($v): string
{
    return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
}

/* ---------------------------------------------------------------- recipes */

/** Recipe lines for several products: [product_id => [[ingredient_id, qty, name, unit, cost_price], ...]]. */
function recipes_for(array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (!$ids || !v4_ready()) {
        return [];
    }
    $rows = db_all(
        'SELECT r.product_id, r.ingredient_id, r.quantity, p.name, p.unit, p.cost_price
         FROM recipe_items r JOIN products p ON p.product_id = r.ingredient_id
         WHERE r.product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.recipe_item_id',
        $ids
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['product_id']][] = $r;
    }
    return $out;
}

/** Cost of making one unit from its recipe (null when the product has no recipe). */
function recipe_cost_of(array $lines): float
{
    $cost = 0.0;
    foreach ($lines as $l) {
        $cost += (float) $l['quantity'] * (float) $l['cost_price'];
    }
    return round($cost, 2);
}

/** Bill lines of products with a recipe get the recipe cost, so profit reports show the real margin. */
function apply_recipe_costs(array $items): array
{
    $recipes = recipes_for(array_column($items, 'product_id'));
    if (!$recipes) {
        return $items;
    }
    foreach ($items as &$it) {
        if ($it['product_id'] && isset($recipes[(int) $it['product_id']])) {
            $it['cost_price'] = recipe_cost_of($recipes[(int) $it['product_id']]);
        }
    }
    unset($it);
    return $items;
}

/** Take the ingredients of a sold item out of stock (inside the bill's transaction). */
function consume_recipe(int $productId, float $qty, int $invoiceId, string $number, ?int $userId): void
{
    $recipe = recipes_for([$productId])[$productId] ?? [];
    foreach ($recipe as $l) {
        move_stock((int) $l['ingredient_id'], -round((float) $l['quantity'] * $qty, 3), 'recipe', 'invoice', $invoiceId, "Used in bill $number", $userId);
    }
}

/** Cancelled bill: give back exactly the ingredients that bill used. */
function return_recipe_stock(int $invoiceId, string $number, ?int $userId): void
{
    if (!v4_ready()) {
        return;
    }
    $used = db_all("SELECT product_id, SUM(quantity) AS qty FROM stock_movements WHERE reference_type = 'invoice' AND reference_id = ? AND movement_type = 'recipe' GROUP BY product_id", [$invoiceId]);
    foreach ($used as $u) {
        move_stock((int) $u['product_id'], -(float) $u['qty'], 'cancel', 'invoice', $invoiceId, "Cancelled $number (ingredients back)", $userId);
    }
}

/* ---------------------------------------------------------------- batches & expiry */

/** New batch from a purchase / stock-in with a batch number or expiry date. */
function add_batch(int $productId, ?int $storeId, float $qty, ?string $batchNo, ?string $expiry, ?float $unitCost, ?int $purchaseId): ?int
{
    if (!v4_ready() || $qty <= 0 || ($batchNo === null && $expiry === null)) {
        return null;
    }
    return insert_row('stock_batches', [
        'product_id' => $productId, 'store_id' => $storeId, 'batch_no' => $batchNo, 'expiry_date' => $expiry,
        'qty_received' => round($qty, 3), 'qty_left' => round($qty, 3), 'unit_cost' => $unitCost, 'purchase_id' => $purchaseId,
    ]);
}

/**
 * Stock going out is taken from batches, the one expiring first first (FEFO). $firstBatch (wastage of one batch)
 * is used before the others. Products without batches are not affected.
 */
function batches_take(int $productId, float $qty, ?int $firstBatch = null): void
{
    if ($qty <= 0 || !v4_ready()) {
        return;
    }
    $rows = db_all(
        'SELECT batch_id, qty_left FROM stock_batches WHERE product_id = ? AND qty_left > 0
         ORDER BY batch_id = ? DESC, expiry_date IS NULL, expiry_date, batch_id FOR UPDATE',
        [$productId, (int) $firstBatch]
    );
    foreach ($rows as $b) {
        if ($qty <= 0) {
            break;
        }
        $take = min($qty, (float) $b['qty_left']);
        db_query('UPDATE stock_batches SET qty_left = qty_left - ? WHERE batch_id = ?', [$take, $b['batch_id']]);
        $qty = round($qty - $take, 3);
    }
}

/** Stock coming back (cancelled bill, customer return): refill the batches that were used, latest expiry first. */
function batches_return(int $productId, float $qty): void
{
    if ($qty <= 0 || !v4_ready()) {
        return;
    }
    $rows = db_all(
        'SELECT batch_id, qty_received - qty_left AS room FROM stock_batches WHERE product_id = ? AND qty_left < qty_received
         AND (expiry_date IS NULL OR expiry_date >= CURDATE()) ORDER BY expiry_date IS NULL DESC, expiry_date DESC, batch_id DESC FOR UPDATE',
        [$productId]
    );
    foreach ($rows as $b) {
        if ($qty <= 0) {
            break;
        }
        $put = min($qty, (float) $b['room']);
        db_query('UPDATE stock_batches SET qty_left = qty_left + ? WHERE batch_id = ?', [$put, $b['batch_id']]);
        $qty = round($qty - $put, 3);
    }
}

/** Batches with stock left that expire within $days days (already expired ones included). */
function expiring_batches(?int $scope, int $days, int $limit = 200): array
{
    if (!v4_ready()) {
        return [];
    }
    $where = 'b.qty_left > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= ? AND p.status <> \'Inactive\'';
    $params = [date('Y-m-d', strtotime("+$days days"))];
    if ($scope !== null) {
        $where .= ' AND (b.store_id = ? OR b.store_id IS NULL)';
        $params[] = $scope;
    }
    $rows = db_all(
        "SELECT b.batch_id, b.product_id, p.name AS product_name, p.unit, b.batch_no, b.expiry_date, b.qty_left, b.qty_received,
                COALESCE(b.unit_cost, p.cost_price) AS unit_cost, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
         FROM stock_batches b JOIN products p ON p.product_id = b.product_id
         WHERE $where ORDER BY b.expiry_date, b.batch_id LIMIT " . max(1, $limit),
        $params
    );
    return array_map(fn($r) => cast_numbers($r, ['qty_left', 'qty_received', 'unit_cost'], ['batch_id', 'product_id', 'days_left']) + [
        'value' => round((float) $r['qty_left'] * (float) $r['unit_cost'], 2),
    ], $rows);
}

/** Count and stock value of the batches expired or expiring within $days (all of them, not one page). */
function expiring_summary(?int $scope, int $days): array
{
    if (!v4_ready()) {
        return ['batches' => 0, 'value' => 0.0];
    }
    $where = 'b.qty_left > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= ? AND p.status <> \'Inactive\'';
    $params = [date('Y-m-d', strtotime("+$days days"))];
    if ($scope !== null) {
        $where .= ' AND (b.store_id = ? OR b.store_id IS NULL)';
        $params[] = $scope;
    }
    $r = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(b.qty_left * COALESCE(b.unit_cost, p.cost_price), 2)), 0) AS value
         FROM stock_batches b JOIN products p ON p.product_id = b.product_id WHERE $where",
        $params
    );
    return ['batches' => (int) $r['n'], 'value' => money($r['value'])];
}

function expiry_text(array $store, int $days): ?string
{
    $rows = expiring_batches((int) $store['store_id'], $days, 30);
    if (!$rows) {
        return null;
    }
    $lines = ["⏰ Expiring stock at {$store['name']} (" . count($rows) . ' batches)'];
    foreach ($rows as $r) {
        $when = $r['days_left'] < 0 ? 'EXPIRED ' . date('d M', strtotime($r['expiry_date'])) : ($r['days_left'] === 0 ? 'expires TODAY' : 'expires ' . date('d M', strtotime($r['expiry_date'])));
        $lines[] = "• {$r['product_name']}" . ($r['batch_no'] ? " ({$r['batch_no']})" : '') . ': ' . qty_text($r['qty_left']) . " {$r['unit']} — $when";
    }
    return implode("\n", $lines);
}

/* ---------------------------------------------------------------- combo & happy-hour offers */

function offer_row(array $o): array
{
    $o = cast_numbers($o, ['combo_price', 'discount_percent', 'min_bill'], ['offer_id', 'store_id', 'is_active', 'used_count', 'created_by']);
    $o['combo_items'] = $o['combo_items'] ? (json_decode($o['combo_items'], true) ?: []) : [];
    $o['target_ids'] = $o['target_ids'] ? (json_decode($o['target_ids'], true) ?: []) : [];
    return $o;
}

/** Is the offer running at this moment (date range, weekday, time window; a window like 22:00-02:00 crosses midnight)? */
function offer_running(array $o, int $at): bool
{
    if (!(int) $o['is_active']) {
        return false;
    }
    $day = date('Y-m-d', $at);
    if (($o['valid_from'] && $day < $o['valid_from']) || ($o['valid_to'] && $day > $o['valid_to'])) {
        return false;
    }
    $now = date('H:i', $at);
    $start = $o['start_time'] ?: null;
    $end = $o['end_time'] ?: null;
    $weekday = (int) date('w', $at);
    if ($start && $end && $end < $start && $now < $end) {
        $weekday = ($weekday + 6) % 7; // after midnight: the window started yesterday
    }
    if (strpos((string) $o['days'], (string) $weekday) === false) {
        return false;
    }
    if ($start && $end) {
        return $end >= $start ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }
    return !($start && $now < $start) && !($end && $now >= $end);
}

/** Offers running now for a store. */
function running_offers(int $storeId, ?int $at = null): array
{
    if (!v4_ready()) {
        return [];
    }
    $at = $at ?? time();
    $rows = db_all('SELECT * FROM offers WHERE store_id = ? AND is_active = 1 ORDER BY offer_id', [$storeId]);
    return array_values(array_map('offer_row', array_filter($rows, fn($o) => offer_running($o, $at))));
}

/**
 * Apply running offers to the bill lines (before coupon / points). Happy-hour % comes first (line discount on the
 * eligible items), then combos (fixed combo price; the saving is spread over the combo's own lines).
 * Returns [items, applied offers [{offer_id, name, type, discount}], total saving in rupees the customer pays].
 */
function apply_offers(int $storeId, array $items, ?int $at = null): array
{
    $offers = running_offers($storeId, $at);
    if (!$offers || !$items) {
        return [$items, [], 0.0];
    }
    $before = array_sum(array_map('line_payable', $items));
    $applied = [];
    $categories = [];
    $ids = array_values(array_unique(array_filter(array_map(fn($i) => (int) ($i['product_id'] ?? 0), $items))));
    if ($ids) {
        foreach (db_all('SELECT product_id, category FROM products WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p) {
            $categories[(int) $p['product_id']] = mb_strtolower((string) $p['category']);
        }
    }

    foreach ($offers as $o) {
        if ($o['offer_type'] !== 'happy_hour' || $before < (float) $o['min_bill']) {
            continue;
        }
        $targets = array_map(fn($t) => is_numeric($t) ? (int) $t : mb_strtolower((string) $t), $o['target_ids']);
        $saving = 0.0;
        foreach ($items as &$it) {
            $pid = (int) ($it['product_id'] ?? 0);
            $eligible = $o['applies_to'] === 'all'
                || ($o['applies_to'] === 'product' && $pid && in_array($pid, $targets, true))
                || ($o['applies_to'] === 'category' && $pid && in_array($categories[$pid] ?? '', $targets, true));
            if (!$eligible) {
                continue;
            }
            $was = line_payable($it);
            $net = round($it['quantity'] * $it['unit_price'], 2) - $it['discount_amount'];
            $it['discount_amount'] = round($it['discount_amount'] + max(0, round($net * (float) $o['discount_percent'] / 100, 2)), 2);
            $saving += $was - line_payable($it);
        }
        unset($it);
        if ($saving > 0.004) {
            $applied[] = ['offer_id' => $o['offer_id'], 'name' => $o['name'], 'type' => 'happy_hour', 'discount' => round($saving, 2)];
        }
    }

    // Combos: how many full sets are in the cart, using each item only once across combos.
    $left = [];
    foreach ($items as $it) {
        if ($pid = (int) ($it['product_id'] ?? 0)) {
            $left[$pid] = ($left[$pid] ?? 0) + (float) $it['quantity'];
        }
    }
    foreach ($offers as $o) {
        if ($o['offer_type'] !== 'combo' || !$o['combo_items'] || $before < (float) $o['min_bill']) {
            continue;
        }
        $sets = PHP_INT_MAX;
        foreach ($o['combo_items'] as $ci) {
            $need = (float) ($ci['qty'] ?? 1);
            $sets = $need > 0 ? min($sets, (int) floor((($left[(int) $ci['product_id']] ?? 0) + 1e-9) / $need)) : 0;
        }
        if ($sets < 1 || $sets === PHP_INT_MAX) {
            continue;
        }
        $regular = 0.0;
        $lineIdx = [];
        foreach ($o['combo_items'] as $ci) {
            foreach ($items as $i => $it) {
                if ((int) ($it['product_id'] ?? 0) === (int) $ci['product_id'] && $it['quantity'] > 0) {
                    $regular += line_payable($it) / $it['quantity'] * (float) $ci['qty'] * $sets;
                    $lineIdx[$i] = true;
                    break;
                }
            }
        }
        $saving = round($regular - (float) $o['combo_price'] * $sets, 2);
        if ($saving <= 0) {
            continue;
        }
        $subset = array_intersect_key($items, $lineIdx);
        $keys = array_keys($subset);
        $spread = spread_bill_discount(array_values($subset), $saving);
        foreach ($keys as $n => $i) {
            $items[$i] = $spread[$n];
        }
        foreach ($o['combo_items'] as $ci) {
            $left[(int) $ci['product_id']] -= (float) $ci['qty'] * $sets;
        }
        $applied[] = ['offer_id' => $o['offer_id'], 'name' => $o['name'] . ($sets > 1 ? " x$sets" : ''), 'type' => 'combo', 'discount' => $saving];
    }
    $after = array_sum(array_map('line_payable', $items));
    return [$items, $applied, round(max(0, $before - $after), 2)];
}

/* ---------------------------------------------------------------- bill link (WhatsApp / SMS / Email) */

function bill_signature(int $invoiceId): string
{
    require_once __DIR__ . '/whatsapp.php';
    return substr(hash_hmac('sha256', "bill:$invoiceId", wa_key()), 0, 24);
}

/** Public link to one bill (no login; the signature makes it unguessable). */
function bill_link(int $invoiceId): string
{
    require_once __DIR__ . '/channels.php';
    return api_public_base() . '/bill.php?i=' . $invoiceId . '&s=' . bill_signature($invoiceId);
}

/** Message variables for a bill: {bill_no} {amount} {date} {link} plus {name} {shop}. */
function bill_vars(array $inv): array
{
    return [
        'bill_no' => $inv['invoice_number'],
        'amount' => number_format((float) $inv['total_amount'], 2),
        'date' => date('d M Y', strtotime($inv['invoice_date'])),
        'link' => bill_link((int) $inv['invoice_id']),
    ];
}

/**
 * Queue the bill message (one campaign per store per day collects them, so the Offers screen does not fill up).
 * Returns the campaign id, or null when the customer has no usable number / email or no channel is ready.
 */
function queue_bill_message(array $store, array $inv, array $channels, string $message, ?int $userId): ?int
{
    require_once __DIR__ . '/automation.php';
    $ready = automatic_channels($store, $channels);
    if (!$ready) {
        return null;
    }
    $people = [['customer_id' => $inv['customer_id'] ?: null, 'name' => $inv['customer_name'] ?: 'Customer', 'phone' => $inv['customer_phone'] ?? null, 'email' => $inv['customer_email'] ?? null, 'vars' => bill_vars($inv)]];
    $recipients = build_recipients($people, $ready);
    if (!$recipients) {
        return null;
    }
    $name = 'Bills sent ' . date('d M Y');
    $id = db_value("SELECT campaign_id FROM campaigns WHERE store_id = ? AND kind = 'bill' AND name = ? ORDER BY campaign_id DESC LIMIT 1", [(int) $store['store_id'], $name]);
    if (!$id) {
        $id = insert_row('campaigns', [
            'store_id' => (int) $store['store_id'], 'user_id' => $userId, 'name' => $name, 'kind' => 'bill', 'message' => $message,
            'subject' => 'Your bill from ' . $store['name'], 'channel' => 'api', 'channels' => implode(',', $ready), 'total' => 0, 'status' => 'Sending',
        ]);
    }
    foreach ($recipients as $r) {
        db_query('INSERT INTO campaign_recipients (campaign_id, customer_id, name, channel, phone, email, vars) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $r['customer_id'], $r['name'], $r['channel'], $r['phone'], $r['email'], json_encode($r['vars'], JSON_UNESCAPED_UNICODE)]);
    }
    // The message text is kept per campaign; today's campaign uses the text that is set now.
    db_query("UPDATE campaigns SET total = total + ?, status = 'Sending', completed_at = NULL, message = ? WHERE campaign_id = ?", [count($recipients), $message, $id]);
    db_query('UPDATE invoices SET bill_sent_at = NOW() WHERE invoice_id = ?', [(int) $inv['invoice_id']]);
    return (int) $id;
}

/**
 * Finish the HTTP response first, then run $work (sending messages, a backup) so the screen never waits for it.
 * Where the server cannot finish early, the work is left for the next scheduler tick / cron run — unless $always,
 * then it runs anyway once the answer is flushed (used for the daily backup, which must not be skipped).
 */
function after_response(callable $work, bool $always = false): void
{
    register_shutdown_function(function () use ($work, $always) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (PHP_SAPI !== 'cli' && !$always) {
            return;
        } else {
            ignore_user_abort(true);
            @flush();
        }
        try {
            $work();
        } catch (Throwable $e) {
            error_log('[Invoicyfy after-response] ' . $e->getMessage());
        }
    });
}

/* ---------------------------------------------------------------- app-wide settings */

function app_setting(string $key): array
{
    try {
        $v = db_value('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$key]);
    } catch (Throwable $e) {
        return [];
    }
    $d = $v ? json_decode((string) $v, true) : null;
    return is_array($d) ? $d : [];
}

function save_app_setting(string $key, array $value): void
{
    db_query('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)', [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
