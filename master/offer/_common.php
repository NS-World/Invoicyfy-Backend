<?php
/** Combo / happy-hour offer from the screen, validated. */
function offer_payload(array $d, int $storeId): array
{
    $name = str_field($d, 'name');
    if (!$name || mb_strlen($name) > 100) {
        fail('Offer name is required (max 100 characters)');
    }
    $type = str_field($d, 'offer_type', 'combo');
    if (!in_array($type, ['combo', 'happy_hour'], true)) {
        fail('Offer type must be combo or happy_hour');
    }
    $days = preg_replace('/[^0-6]/', '', (string) ($d['days'] ?? '0123456'));
    $days = implode('', array_unique(str_split($days)));
    if ($days === '') {
        fail('Choose at least one day');
    }
    $time = function ($v, string $label) {
        if ($v === null || $v === '') {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v)) {
            fail("$label must be a time like 15:00");
        }
        return (string) $v;
    };
    $start = $time($d['start_time'] ?? null, 'Start time');
    $end = $time($d['end_time'] ?? null, 'End time');
    if (($start === null) !== ($end === null)) {
        fail('Enter both start and end time (or leave both empty for the whole day)');
    }
    if ($start !== null && $start === $end) {
        fail('Start and end time cannot be the same');
    }
    $from = date_field($d, 'valid_from');
    $to = date_field($d, 'valid_to');
    if ($from && $to && $to < $from) {
        fail('"Valid till" cannot be before "Valid from"');
    }
    $out = [
        'name' => $name, 'offer_type' => $type, 'days' => $days, 'start_time' => $start, 'end_time' => $end,
        'valid_from' => $from, 'valid_to' => $to, 'min_bill' => round(max(0, num_field($d, 'min_bill', 0)), 2),
        'is_active' => bool_field($d, 'is_active', true) ? 1 : 0,
        'combo_items' => null, 'combo_price' => null, 'discount_percent' => null, 'applies_to' => 'all', 'target_ids' => null,
    ];
    if ($type === 'combo') {
        $items = [];
        foreach ((array) ($d['combo_items'] ?? []) as $i => $ci) {
            $pid = is_array($ci) ? int_field($ci, 'product_id') : null;
            $qty = is_array($ci) ? num_field($ci, 'qty', 1) : 0;
            if (!$pid || $qty <= 0 || $qty > 1000) {
                fail('Each combo item needs a product and a quantity (row ' . ($i + 1) . ')');
            }
            if (isset($items[$pid])) {
                fail('The same product is added twice in the combo');
            }
            $items[$pid] = ['product_id' => $pid, 'qty' => round($qty, 3)];
        }
        if (count($items) < 1 || count($items) > 10) {
            fail('A combo needs 1 to 10 products');
        }
        $marks = implode(',', array_fill(0, count($items), '?'));
        $found = db_all("SELECT product_id, name, price, gst_rate, price_includes_tax FROM products WHERE product_id IN ($marks) AND (store_id = ? OR store_id IS NULL)", array_merge(array_keys($items), [$storeId]));
        if (count($found) !== count($items)) {
            fail('A combo product was not found in this store');
        }
        $regular = 0.0;
        foreach ($found as $p) {
            $unit = (int) $p['price_includes_tax'] ? (float) $p['price'] : (float) $p['price'] * (1 + (float) $p['gst_rate'] / 100);
            $regular += $unit * $items[(int) $p['product_id']]['qty'];
        }
        $price = num_field($d, 'combo_price');
        if ($price === null || $price <= 0) {
            fail('Enter the combo price (what the customer pays, incl. GST)');
        }
        if (count($items) === 1 && array_values($items)[0]['qty'] <= 1) {
            fail('A combo needs at least 2 pieces (e.g. 2 of the same item, or 2 different items)');
        }
        if ($price >= round($regular, 2)) {
            fail('Combo price must be less than the normal price of these items (' . rupees($regular) . ')');
        }
        $out['combo_items'] = json_encode(array_values($items));
        $out['combo_price'] = round($price, 2);
    } else {
        $pct = num_field($d, 'discount_percent');
        if ($pct === null || $pct <= 0 || $pct > 90) {
            fail('Discount % must be between 0 and 90');
        }
        $applies = str_field($d, 'applies_to', 'all');
        if (!in_array($applies, ['all', 'category', 'product'], true)) {
            fail('Applies to must be all, category or product');
        }
        $targets = array_values(array_unique(array_filter(array_map(fn($t) => is_scalar($t) ? trim((string) $t) : '', (array) ($d['target_ids'] ?? [])), 'strlen')));
        if ($applies !== 'all' && !$targets) {
            fail($applies === 'category' ? 'Choose the categories' : 'Choose the products');
        }
        if (count($targets) > 100) {
            fail('Choose at most 100 items');
        }
        if ($applies === 'product') {
            $targets = array_map('intval', $targets);
        }
        $out['discount_percent'] = round($pct, 2);
        $out['applies_to'] = $applies;
        $out['target_ids'] = $applies === 'all' ? null : json_encode($targets, JSON_UNESCAPED_UNICODE);
    }
    return $out;
}

function load_offer(array $user, int $id): array
{
    $o = db_one('SELECT * FROM offers WHERE offer_id = ?', [$id]);
    if (!$o) {
        fail('Offer not found', 404);
    }
    assert_store_access($user, $o['store_id']);
    return $o;
}

/** Offer for the screen: decoded lists, product names and whether it is running right now. */
function offer_out(array $o): array
{
    $row = offer_row($o);
    $row['running_now'] = offer_running($o, time());
    if ($row['combo_items']) {
        $ids = array_column($row['combo_items'], 'product_id');
        $names = [];
        foreach (db_all('SELECT product_id, name FROM products WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p) {
            $names[(int) $p['product_id']] = $p['name'];
        }
        foreach ($row['combo_items'] as &$ci) {
            $ci['name'] = $names[(int) $ci['product_id']] ?? '?';
        }
        unset($ci);
    }
    return $row;
}
