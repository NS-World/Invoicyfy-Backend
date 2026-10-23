<?php
/** GET — the numbers the business runs on: shops by status, recurring revenue, this month's payments, recent events. */
require_once __DIR__ . '/../_common.php';
allow_methods('GET');
require_saas_admin();
$counts = array_fill_keys(['trial', 'active', 'grace', 'expired', 'suspended'], 0);
$mrr = 0;
$plans = array_column(saas_plans(false), null, 'code');
foreach (saas_q('SELECT * FROM saas_tenants')->fetchAll() as $t) {
    $s = saas_effective_status($t);
    $counts[$s]++;
    if (in_array($s, ['active', 'grace'], true) && isset($plans[$t['plan_code']])) {
        $p = $plans[$t['plan_code']];
        $mult = $p['per_store'] ? max(1, (int) $t['store_quota']) : 1;
        $mrr += $t['billing_cycle'] === 'month' ? $p['price_month'] * $mult : $p['price_year'] * $mult / 12;
    }
}
$month = saas_q("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM saas_orders WHERE status = 'paid' AND paid_at >= ?", [date('Y-m-01')])->fetch();
$trialsMonth = (int) saas_q("SELECT COUNT(*) FROM saas_tenants WHERE created_at >= ? AND trial_ends_at IS NOT NULL", [date('Y-m-01')])->fetchColumn();
$events = saas_q("SELECT e.*, t.slug, t.business_name FROM saas_events e LEFT JOIN saas_tenants t ON t.tenant_id = e.tenant_id
                  WHERE e.type NOT IN ('rate') ORDER BY e.event_id DESC LIMIT 25")->fetchAll();
json_out(['status' => 'success', 'data' => [
    'shops' => $counts, 'total_shops' => array_sum($counts),
    'mrr' => (int) round($mrr), 'arr' => (int) round($mrr * 12),
    'this_month' => ['payments' => (int) $month['n'], 'collected' => (int) $month['total'], 'new_trials' => $trialsMonth],
    'events' => $events,
]]);
