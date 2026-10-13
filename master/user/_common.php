<?php
function user_payload(array $d, bool $creating): array
{
    $name = str_field($d, 'name');
    $email = strtolower((string) str_field($d, 'email', ''));
    if (!$name) {
        fail('Name is required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address');
    }
    $roleId = int_field($d, 'role_id');
    $storeId = int_field($d, 'store_id');
    if (!$roleId || !db_value("SELECT 1 FROM roles WHERE role_id = ?", [$roleId])) {
        fail('Select a valid role');
    }
    if (!$storeId || !db_value("SELECT 1 FROM stores WHERE store_id = ?", [$storeId])) {
        fail('Select a valid store');
    }
    $p = [
        'name' => $name,
        'email' => $email,
        'phone' => str_field($d, 'phone'),
        'city' => str_field($d, 'city'),
        'role_id' => $roleId,
        'store_id' => $storeId,
        'is_active' => bool_field($d, 'is_active', true) ? 1 : 0,
    ];
    $password = (string) ($d['password'] ?? '');
    if ($creating || $password !== '') {
        if (strlen($password) < 8) {
            fail('Password must be at least 8 characters');
        }
        $p['password'] = password_hash($password, PASSWORD_BCRYPT);
    }
    // V3: manager approval PIN for discounts above a cashier's limit (4-8 digits, stored hashed).
    $pin = (string) ($d['approval_pin'] ?? '');
    if ($pin !== '') {
        $p['approval_pin'] = approval_pin_hash($pin);
    } elseif (bool_field($d, 'clear_approval_pin')) {
        $p['approval_pin'] = null;
    }
    return $p;
}

function approval_pin_hash(string $pin): string
{
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        fail('Approval PIN must be 4 to 8 digits');
    }
    if (preg_match('/^(\d)\1+$/', $pin) || in_array($pin, ['1234', '12345', '123456', '1234567', '12345678', '0123', '4321'], true)) {
        fail('Choose a less obvious PIN (not 1111 or 1234)');
    }
    return password_hash($pin, PASSWORD_BCRYPT);
}

/** Only an Admin may create, edit or assign the Admin role (prevents privilege escalation). */
function guard_admin_role(array $me, int $roleId, ?int $targetUserId = null): void
{
    if (is_admin($me)) {
        return;
    }
    if (strcasecmp((string) db_value("SELECT role_name FROM roles WHERE role_id = ?", [$roleId]), 'Admin') === 0) {
        fail('Only an Admin can give someone the Admin role', 403);
    }
    if ($targetUserId) {
        $target = load_user($targetUserId);
        if ($target && is_admin($target)) {
            fail('Only an Admin can change an Admin account', 403);
        }
        if ($targetUserId === (int) $me['user_id']) {
            fail('You cannot change your own account here. Use Settings > Change password.', 403);
        }
    }
}

function user_duplicate_check(array $p, int $excludeId = 0): void
{
    if (db_value("SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1", [$p['email'], $excludeId])) {
        fail('A user with this email already exists', 409);
    }
    if ($p['phone'] && db_value("SELECT 1 FROM users WHERE phone = ? AND user_id <> ? LIMIT 1", [$p['phone'], $excludeId])) {
        fail('A user with this phone already exists', 409);
    }
}

const USER_COLUMNS = "u.user_id, u.name, u.email, u.phone, u.city, u.store_id, u.role_id, u.is_active, u.last_login_at, u.created_at,
                      (u.approval_pin IS NOT NULL) AS has_approval_pin, r.role_name, s.name AS store_name";
