<?php
/** POST {plan, cycle, stores} — Admin starts a renewal / upgrade payment for this shop. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
$t = current_tenant();
$d = input();
$plan = saas_plan((string) ($d['plan'] ?? $t['plan_code']));
if (!$plan || !$plan['is_active']) {
    fail('Choose a plan');
}
$stores = (int) ($d['stores'] ?? $t['store_quota']);
$order = saas_create_order('renewal', $plan, (string) ($d['cycle'] ?? $t['billing_cycle']), $stores, $t, null);
json_out(['status' => 'success', 'order' => $order]);
