<?php
/** Shared by the Khanak SaaS endpoints (sign-up, payments, super admin). They run on the control database. */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/saas.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if (!saas_mode()) {
    json_out(['status' => 'error', 'message' => 'Not found'], 404);
}

/** At most $max calls per IP address in $minutes for this action (sign-up, login, payment checks). */
function saas_rate_limit(string $action, int $max, int $minutes = 60): void
{
    $ip = client_ip() ?? 'unknown';
    $key = "rl|$action|$ip";
    $n = (int) saas_q('SELECT COUNT(*) FROM saas_events WHERE type = ? AND message = ? AND created_at > NOW() - INTERVAL ? MINUTE', ['rate', $key, $minutes])->fetchColumn();
    if ($n >= $max) {
        fail('Too many tries. Please wait a few minutes and try again.', 429);
    }
    saas_event(null, 'rate', $key);
}

function saas_admin_key(): string
{
    return saas_secret('admin-jwt', 'control');
}

function saas_admin_token(array $admin): string
{
    return JWT::encode(['sub' => (int) $admin['admin_id'], 'email' => $admin['email'], 'typ' => 'saas-admin', 'iat' => time(), 'exp' => time() + 8 * 3600],
        saas_admin_key(), 'HS256');
}

/** The signed-in Khanak super admin, or a 401. */
function require_saas_admin(): array
{
    $token = bearer_token();
    try {
        $d = $token ? JWT::decode($token, new Key(saas_admin_key(), 'HS256')) : null;
    } catch (Throwable $e) {
        $d = null;
    }
    if (!$d || ($d->typ ?? '') !== 'saas-admin') {
        json_out(['status' => 'error', 'message' => 'Please log in to the Khanak admin'], 401);
    }
    $a = saas_q('SELECT admin_id, email, name FROM saas_admins WHERE admin_id = ?', [(int) $d->sub])->fetch();
    if (!$a) {
        json_out(['status' => 'error', 'message' => 'Admin not found'], 401);
    }
    return $a;
}
