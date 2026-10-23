<?php
/** POST {code, name, tagline, price_year, price_month, max_users, max_stores, features[], is_active} — change a plan. */
require_once __DIR__ . '/../_common.php';
allow_methods('POST');
$admin = require_saas_admin();
$d = input();
$plan = saas_plan((string) ($d['code'] ?? ''));
if (!$plan) {
    fail('Plan not found', 404);
}
$features = array_values(array_intersect(array_keys(SAAS_FEATURES), (array) ($d['features'] ?? $plan['features'])));
$name = trim((string) ($d['name'] ?? $plan['name']));
if ($name === '' || mb_strlen($name) > 60) {
    fail('Plan name is required');
}
$num = fn($k, $max) => max(0, min($max, (int) ($d[$k] ?? $plan[$k])));
saas_q('UPDATE saas_plans SET name = ?, tagline = ?, price_year = ?, price_month = ?, max_users = ?, max_stores = ?, features = ?, is_active = ? WHERE code = ?',
    [$name, mb_substr(trim((string) ($d['tagline'] ?? $plan['tagline'])), 0, 120), $num('price_year', 10000000), $num('price_month', 1000000),
     $num('max_users', 100000), $num('max_stores', 100000), json_encode(array_values(array_diff($features, SAAS_BASE_FEATURES))),
     array_key_exists('is_active', $d) ? (int) (bool) $d['is_active'] : (int) $plan['is_active'], $plan['code']]);
foreach (saas_q('SELECT slug FROM saas_tenants WHERE plan_code = ?', [$plan['code']])->fetchAll() as $t) {
    saas_forget_tenant($t['slug']);
}
saas_event(null, 'admin', "Plan {$plan['code']} changed by {$admin['email']}");
json_out(['status' => 'success', 'message' => 'Plan saved', 'data' => saas_plan($plan['code'])]);
