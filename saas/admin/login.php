<?php
/** POST {email, password} — Khanak super admin login (accounts are created with saas/cli/setup.php). */
require_once __DIR__ . '/../_common.php';
allow_methods('POST');
saas_rate_limit('admin-login', 20, 15);
$d = input();
$a = saas_q('SELECT * FROM saas_admins WHERE email = ?', [strtolower(trim((string) ($d['email'] ?? '')))])->fetch();
if (!$a || !password_verify((string) ($d['password'] ?? ''), $a['password'])) {
    fail('Wrong email or password', 401);
}
saas_q('UPDATE saas_admins SET last_login_at = NOW() WHERE admin_id = ?', [$a['admin_id']]);
json_out(['status' => 'success', 'token' => saas_admin_token($a), 'admin' => ['name' => $a['name'], 'email' => $a['email']]]);
