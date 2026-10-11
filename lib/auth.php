<?php
require_once __DIR__ . '/../auth/jwt_config.php';
require_once __DIR__ . '/permissions.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** Works on Apache (incl. CGI/FastCGI where getallheaders() drops Authorization) and nginx. */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if ($header && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
        return $m[1];
    }
    return null;
}

function issue_tokens(array $user): array
{
    global $accessKey, $refreshKey, $jwt_issuer, $jwt_audience, $access_token_exp, $refresh_token_exp;
    $now = time();
    $claims = [
        "iss" => $jwt_issuer,
        "aud" => $jwt_audience,
        "iat" => $now,
        "sub" => (int) $user['user_id'],
        "name" => $user['name'],
        "roleId" => $user['role_id'] !== null ? (int) $user['role_id'] : null,
        "role" => $user['role_name'] ?? null,
        "storeId" => $user['store_id'] !== null ? (int) $user['store_id'] : null,
    ];
    $access = JWT::encode($claims + ["exp" => $now + $access_token_exp, "typ" => "access", "jti" => bin2hex(random_bytes(8))], $accessKey, 'HS256');
    $refresh = JWT::encode(
        ["iss" => $jwt_issuer, "aud" => $jwt_audience, "iat" => $now, "sub" => (int) $user['user_id'],
         "exp" => $now + $refresh_token_exp, "typ" => "refresh", "jti" => bin2hex(random_bytes(8))],
        $refreshKey,
        'HS256'
    );
    return [$access, $refresh];
}

function public_user(array $u): array
{
    return [
        "user_id" => (int) $u['user_id'],
        "name" => $u['name'],
        "email" => $u['email'],
        "phone" => $u['phone'] ?? null,
        "role_id" => $u['role_id'] !== null ? (int) $u['role_id'] : null,
        "role_name" => $u['role_name'] ?? null,
        "store_id" => $u['store_id'] !== null ? (int) $u['store_id'] : null,
        "store_name" => $u['store_name'] ?? null,
        "permissions" => $u['permissions'] ?? role_permissions($u['role_name'] ?? null, $u['role_permissions'] ?? null),
    ];
}

function load_user(int $userId): ?array
{
    $user = db_one(
        "SELECT u.user_id, u.name, u.email, u.phone, u.role_id, u.store_id, u.is_active, r.role_name, r.permissions AS role_permissions,
                s.name AS store_name
         FROM users u
         LEFT JOIN roles r ON u.role_id = r.role_id
         LEFT JOIN stores s ON u.store_id = s.store_id
         WHERE u.user_id = ?",
        [$userId]
    );
    if ($user) {
        $user['permissions'] = role_permissions($user['role_name'], $user['role_permissions']);
    }
    return $user;
}

/** Validates the access token and returns the current user row. Exits with 401 otherwise. */
function require_auth(): array
{
    global $accessKey, $user_id, $authUser;
    if (isset($authUser)) {
        return $authUser;
    }
    $token = bearer_token();
    if (!$token) {
        json_out(["status" => "error", "message" => "Authorization header missing"], 401);
    }
    try {
        $decoded = JWT::decode($token, new Key($accessKey, 'HS256'));
    } catch (Throwable $e) {
        json_out(["status" => "error", "message" => "Access token invalid or expired"], 401);
    }
    if (isset($decoded->typ) && $decoded->typ !== 'access') {
        json_out(["status" => "error", "message" => "Invalid token type"], 401);
    }
    $user = load_user((int) $decoded->sub);
    if (!$user || (isset($user['is_active']) && !(int) $user['is_active'])) {
        json_out(["status" => "error", "message" => "User not found or disabled"], 401);
    }
    $user_id = (int) $user['user_id'];
    $authUser = $user;

    // Role permission for this endpoint (derived from its path), e.g. master/invoice/cancel-invoice.php => invoices.delete
    $needed = endpoint_permission((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($needed) {
        require_permission($user, $needed[0], $needed[1]);
    }
    return $user;
}

function is_admin(array $user): bool
{
    return strcasecmp((string) ($user['role_name'] ?? ''), 'Admin') === 0;
}

function require_admin(array $user): void
{
    if (!is_admin($user)) {
        fail('Only an Admin can perform this action', 403);
    }
}

/**
 * Store visibility: Admins see every store (optionally filtered with ?store_id=),
 * everyone else only sees their own store. Returns null for "all stores".
 */
function scope_store(array $user, $requested = null): ?int
{
    $requested = ($requested === null || $requested === '') ? null : (int) $requested;
    if (is_admin($user)) {
        return $requested;
    }
    return $user['store_id'] !== null ? (int) $user['store_id'] : -1;
}

/** For writes: which store does this record belong to? Non-admins are pinned to their store. */
function write_store(array $user, $requested): int
{
    if (!is_admin($user) && $user['store_id'] !== null) {
        return (int) $user['store_id'];
    }
    $id = ($requested === null || $requested === '') ? ($user['store_id'] ?? null) : (int) $requested;
    if (!$id && is_admin($user)) {
        // Admin without a home store (e.g. store field left empty on the user): use the first store.
        $id = db_value('SELECT MIN(store_id) FROM stores');
    }
    if (!$id) {
        fail('store_id is required');
    }
    return (int) $id;
}

function assert_store_access(array $user, $storeId): void
{
    if (!is_admin($user) && (int) $storeId !== (int) $user['store_id']) {
        fail('You do not have access to this store', 403);
    }
}
