<?php
/**
 * Deleting a product or a customer together with everything linked to it.
 *
 *   *_delete_plan($id)  what will happen, row counts per kind of record (shown in red before deleting)
 *   *_delete_run($id)   does it, in one transaction
 *
 * Plan row: ['key' => ..., 'label' => ..., 'count' => n, 'effect' => 'delete' | 'unlink' | 'off'].
 */

function cascade_count(string $sql, array $params = []): int
{
    try {
        return (int) db_value($sql, $params);
    } catch (Throwable $e) {
        return 0; // table not there yet (database not updated)
    }
}

function cascade_exec(string $sql, array $params = []): int
{
    try {
        return db_query($sql, $params)->rowCount();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Unknown column')) {
            return 0;
        }
        throw $e;
    }
}

/** Offers that mention the product: combos that contain it, and "selected products" offers. */
function cascade_product_offers(int $productId): array
{
    if (!v4_ready()) {
        return [[], []];
    }
    $combos = [];
    $targets = [];
    foreach (db_all("SELECT offer_id, offer_type, applies_to, combo_items, target_ids FROM offers") as $o) {
        foreach ((array) json_decode((string) $o['combo_items'], true) as $ci) {
            if ((int) ($ci['product_id'] ?? 0) === $productId) {
                $combos[] = (int) $o['offer_id'];
                continue 2;
            }
        }
        if ($o['applies_to'] === 'product' && in_array((string) $productId, array_map('strval', (array) json_decode((string) $o['target_ids'], true)), true)) {
            $targets[] = $o;
        }
    }
    return [$combos, $targets];
}

function product_delete_plan(int $id): array
{
    [$combos, $targets] = cascade_product_offers($id);
    $rows = [
        ['key' => 'stock_movements', 'label' => 'Stock history entries (stock in / out, wastage)', 'count' => cascade_count('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$id]), 'effect' => 'delete'],
        ['key' => 'batches', 'label' => 'Stock batches / expiry entries', 'count' => cascade_count('SELECT COUNT(*) FROM stock_batches WHERE product_id = ?', [$id]), 'effect' => 'delete'],
        ['key' => 'recipe', 'label' => 'Recipe lines (its own recipe and recipes that use it)', 'count' => cascade_count('SELECT COUNT(*) FROM recipe_items WHERE product_id = ? OR ingredient_id = ?', [$id, $id]), 'effect' => 'delete'],
        ['key' => 'offers', 'label' => 'Combo / happy-hour offers with this item (switched off)', 'count' => count($combos) + count($targets), 'effect' => 'off'],
        ['key' => 'invoice_items', 'label' => 'Bill lines — bills stay as they are, the item name stays on them', 'count' => cascade_count('SELECT COUNT(*) FROM invoice_items WHERE product_id = ?', [$id]), 'effect' => 'unlink'],
        ['key' => 'order_items', 'label' => 'Order lines — orders stay, the item name stays on them', 'count' => cascade_count('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id]), 'effect' => 'unlink'],
        ['key' => 'purchase_items', 'label' => 'Purchase bill lines — supplier bills stay, the item name stays', 'count' => cascade_count('SELECT COUNT(*) FROM purchase_items WHERE product_id = ?', [$id]), 'effect' => 'unlink'],
    ];
    return array_values(array_filter($rows, fn($r) => $r['count'] > 0));
}

function product_delete_run(int $id): array
{
    $plan = product_delete_plan($id);
    [$combos, $targets] = cascade_product_offers($id);
    foreach ($combos as $offerId) {
        cascade_exec('UPDATE offers SET is_active = 0 WHERE offer_id = ?', [$offerId]);
    }
    foreach ($targets as $o) {
        $left = array_values(array_filter((array) json_decode((string) $o['target_ids'], true), fn($t) => (string) $t !== (string) $id));
        cascade_exec('UPDATE offers SET target_ids = ?, is_active = IF(? = 0, 0, is_active) WHERE offer_id = ?', [json_encode($left), count($left), $o['offer_id']]);
    }
    // Bill / order / purchase lines keep the item name and amounts (product link becomes empty);
    // stock history, batches and recipe lines go with the product (database foreign keys).
    cascade_exec('DELETE FROM recipe_items WHERE product_id = ? OR ingredient_id = ?', [$id, $id]);
    cascade_exec('DELETE FROM stock_batches WHERE product_id = ?', [$id]);
    cascade_exec('DELETE FROM stock_movements WHERE product_id = ?', [$id]);
    db_query('DELETE FROM products WHERE product_id = ?', [$id]);
    return $plan;
}

function customer_delete_plan(int $id): array
{
    $inv = 'SELECT invoice_id FROM invoices WHERE customer_id = ?';
    $rows = [
        ['key' => 'invoices', 'label' => 'Bills / invoices (with all their items)', 'count' => cascade_count('SELECT COUNT(*) FROM invoices WHERE customer_id = ?', [$id]), 'effect' => 'delete'],
        ['key' => 'invoice_items', 'label' => 'Bill item lines', 'count' => cascade_count("SELECT COUNT(*) FROM invoice_items WHERE invoice_id IN ($inv)", [$id]), 'effect' => 'delete'],
        ['key' => 'payments', 'label' => 'Payments received (on these bills and udhaar payments)', 'count' => cascade_count("SELECT COUNT(*) FROM payments WHERE customer_id = ? OR invoice_id IN ($inv)", [$id, $id]), 'effect' => 'delete'],
        ['key' => 'orders', 'label' => 'Orders (with all their items)', 'count' => cascade_count('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$id]), 'effect' => 'delete'],
        ['key' => 'loyalty', 'label' => 'Loyalty points history', 'count' => cascade_count('SELECT COUNT(*) FROM loyalty_ledger WHERE customer_id = ?', [$id]), 'effect' => 'delete'],
        ['key' => 'coupons', 'label' => 'Personal coupons and coupon uses', 'count' => cascade_count('SELECT COUNT(*) FROM coupons WHERE customer_id = ?', [$id])
            + cascade_count('SELECT COUNT(*) FROM coupon_redemptions WHERE customer_id = ?', [$id]), 'effect' => 'delete'],
        ['key' => 'messages', 'label' => 'Offer / reminder messages sent to them', 'count' => cascade_count('SELECT COUNT(*) FROM campaign_recipients WHERE customer_id = ?', [$id]), 'effect' => 'delete'],
    ];
    return array_values(array_filter($rows, fn($r) => $r['count'] > 0));
}

function customer_delete_run(int $id): array
{
    $plan = customer_delete_plan($id);
    // Reports: the days of these bills and payments are recomputed on the next report.
    cascade_exec(
        "INSERT IGNORE INTO stat_dirty (store_id, day)
         SELECT store_id, invoice_date FROM invoices WHERE customer_id = ?
         UNION SELECT COALESCE(p.store_id, 0), p.payment_date FROM payments p
               WHERE p.customer_id = ? OR p.invoice_id IN (SELECT invoice_id FROM invoices WHERE customer_id = ?)",
        [$id, $id, $id]
    );
    cascade_exec('DELETE FROM payments WHERE customer_id = ? OR invoice_id IN (SELECT invoice_id FROM invoices WHERE customer_id = ?)', [$id, $id]);
    cascade_exec('DELETE FROM coupon_redemptions WHERE customer_id = ? OR invoice_id IN (SELECT invoice_id FROM invoices WHERE customer_id = ?)', [$id, $id]);
    cascade_exec('DELETE FROM loyalty_ledger WHERE customer_id = ?', [$id]);
    cascade_exec('DELETE FROM invoice_items WHERE invoice_id IN (SELECT invoice_id FROM invoices WHERE customer_id = ?)', [$id]);
    cascade_exec('DELETE FROM invoices WHERE customer_id = ?', [$id]);
    cascade_exec('DELETE FROM payments WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = ?)', [$id]);
    cascade_exec('UPDATE invoices SET order_id = NULL WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = ?)', [$id]);
    cascade_exec('DELETE FROM order_items WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = ?)', [$id]);
    cascade_exec('DELETE FROM orders WHERE customer_id = ?', [$id]);
    cascade_exec('DELETE FROM coupon_redemptions WHERE coupon_id IN (SELECT coupon_id FROM coupons WHERE customer_id = ?)', [$id]);
    cascade_exec('DELETE FROM coupons WHERE customer_id = ?', [$id]);
    cascade_exec('DELETE FROM campaign_recipients WHERE customer_id = ?', [$id]);
    cascade_exec('DELETE FROM stat_customers_monthly WHERE customer_id = ?', [$id]);
    db_query('DELETE FROM customers WHERE customer_id = ?', [$id]);
    return $plan;
}

/** "3 bills, 5 payments" for the activity log. */
function cascade_summary(array $plan): string
{
    $parts = [];
    foreach ($plan as $r) {
        $parts[] = $r['count'] . ' ' . strtolower(explode(' (', explode(' — ', $r['label'])[0])[0]);
    }
    return $parts ? ' with ' . implode(', ', $parts) : '';
}
