<?php
/** GET ?status= — latest payments (paid, waiting, failed) across all shops. */
require_once __DIR__ . '/../_common.php';
allow_methods('GET');
require_saas_admin();
$where = '1=1';
$p = [];
if (in_array($_GET['status'] ?? '', ['created', 'paid'], true)) {
    $where .= ' AND o.status = ?';
    $p[] = $_GET['status'];
}
$rows = saas_q("SELECT o.order_id, o.kind, o.plan_code, o.billing_cycle, o.quantity, o.amount, o.gst, o.total, o.gateway, o.gateway_order_id,
                       o.gateway_payment_id, o.status, o.error, o.created_at, o.paid_at, o.signup, t.slug, t.business_name, i.invoice_no
                FROM saas_orders o LEFT JOIN saas_tenants t ON t.tenant_id = o.tenant_id LEFT JOIN saas_invoices i ON i.order_id = o.order_id
                WHERE $where ORDER BY o.order_id DESC LIMIT 300", $p)->fetchAll();
foreach ($rows as &$r) {
    $s = json_decode((string) $r['signup'], true) ?: [];
    $r['business_name'] = $r['business_name'] ?? ($s['business_name'] ?? null);
    $r['slug'] = $r['slug'] ?? ($s['slug'] ?? null);
    $r['invoice_url'] = $r['invoice_no'] ? saas_invoice_url($r['invoice_no']) : null;
    unset($r['signup']);
}
json_out(['status' => 'success', 'data' => $rows]);
