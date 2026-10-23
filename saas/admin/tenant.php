<?php
/** GET ?tenant_id= — one shop: details, payments, invoices and its history. */
require_once __DIR__ . '/../_common.php';
allow_methods('GET');
require_saas_admin();
$t = saas_tenant_by_id((int) ($_GET['tenant_id'] ?? 0));
if (!$t) {
    fail('Shop not found', 404);
}
$orders = saas_q('SELECT order_id, kind, plan_code, billing_cycle, quantity, amount, gst, total, gateway, gateway_order_id, gateway_payment_id, status, error, created_at, paid_at
                  FROM saas_orders WHERE tenant_id = ? ORDER BY order_id DESC LIMIT 100', [$t['tenant_id']])->fetchAll();
$invoices = array_map(fn($i) => $i + ['url' => saas_invoice_url($i['invoice_no'])],
    saas_q('SELECT invoice_no, amount, gst, total, created_at FROM saas_invoices WHERE tenant_id = ? ORDER BY invoice_id DESC', [$t['tenant_id']])->fetchAll());
$events = saas_q("SELECT type, message, created_at FROM saas_events WHERE tenant_id = ? AND type <> 'rate' ORDER BY event_id DESC LIMIT 100", [$t['tenant_id']])->fetchAll();
json_out(['status' => 'success', 'data' => ['tenant_id' => (int) $t['tenant_id'], 'city' => $t['city'], 'gstin' => $t['gstin'], 'notes' => $t['notes'],
    'db_name' => $t['db_name'], 'created_at' => $t['created_at'], 'plan_code' => $t['plan_code']] + saas_tenant_public($t)
    + ['orders' => $orders, 'invoices' => $invoices, 'events' => $events]]);
