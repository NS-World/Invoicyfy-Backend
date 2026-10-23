<?php
/** GET — this shop's plan, validity, limits and (for Admins) payments and invoices. Every signed-in user may read it. */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();
$t = current_tenant();
$pub = saas_tenant_public($t);
$pub['usage'] = [
    'users' => (int) db_value('SELECT COUNT(*) FROM users WHERE is_active = 1'),
    'stores' => (int) db_value('SELECT COUNT(*) FROM stores'),
];
if (is_admin($user)) {
    $pub['invoices'] = array_map(fn($i) => $i + ['url' => saas_invoice_url($i['invoice_no'])],
        saas_q('SELECT invoice_no, amount, gst, total, created_at FROM saas_invoices WHERE tenant_id = ? ORDER BY invoice_id DESC LIMIT 50', [$t['tenant_id']])->fetchAll());
    $pub['plans'] = saas_plans();
    $pub['features'] = SAAS_FEATURES;
    $pub['payment_mode'] = saas_payment_mode();
    $pub['gst_rate'] = saas_gst_rate();
}
json_out(['status' => 'success', 'data' => $pub]);
