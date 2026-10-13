<?php
/**
 * Activity log: who did what and when (bills, cancellations, discounts, price changes, day close...).
 * Writing the log must never break the action itself, so errors are only written to the PHP error log.
 */

function client_ip(): ?string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip ? mb_substr($ip, 0, 45) : null;
}

/**
 * log_activity('invoice.cancel', 'invoice', 12, 'Cancelled bill 105 (₹240)', ['reason' => '...'], $storeId)
 * Uses the signed-in user unless $user is given.
 */
function log_activity(string $action, ?string $entity, $entityId, string $summary, array $details = [], $storeId = null, ?array $user = null): void
{
    global $authUser;
    $user = $user ?? ($authUser ?? null);
    try {
        db_query(
            'INSERT INTO activity_log (store_id, user_id, user_name, action, entity, entity_id, summary, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $storeId !== null ? (int) $storeId : (isset($user['store_id']) ? (int) $user['store_id'] : null),
                isset($user['user_id']) ? (int) $user['user_id'] : null,
                $user['name'] ?? null,
                mb_substr($action, 0, 50),
                $entity !== null ? mb_substr($entity, 0, 30) : null,
                $entityId !== null && $entityId !== '' ? (int) $entityId : null,
                mb_substr($summary, 0, 500),
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                client_ip(),
            ]
        );
    } catch (Throwable $e) {
        error_log('[Invoicyfy activity] ' . $e->getMessage());
    }
}

/** "₹1,234.50" for log summaries. */
function rupees($v): string
{
    return '₹' . number_format((float) $v, 2);
}
