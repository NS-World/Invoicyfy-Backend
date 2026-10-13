<?php
function role_row(array $r): array
{
    $r['role_id'] = (int) $r['role_id'];
    $r['permissions'] = role_permissions($r['role_name'], $r['permissions'] ?? null);
    $r['is_system'] = strcasecmp($r['role_name'], 'Admin') === 0;
    $r['user_count'] = (int) db_value("SELECT COUNT(*) FROM users WHERE role_id = ?", [$r['role_id']]);
    $r['max_discount_percent'] = isset($r['max_discount_percent']) && $r['max_discount_percent'] !== null ? (float) $r['max_discount_percent'] : null;
    return $r;
}

/** Discount limit from the role form: "" / null = no limit. Only an Admin may change limits. */
function role_discount_input(array $me, array $data): array
{
    if (!array_key_exists('max_discount_percent', $data) || !is_admin($me)) {
        return [];
    }
    $v = $data['max_discount_percent'];
    if ($v === null || $v === '') {
        return ['max_discount_percent' => null];
    }
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
        fail('Maximum discount must be between 0 and 100 %');
    }
    return ['max_discount_percent' => round((float) $v, 2)];
}

/** Non-admins may not edit their own role or hand out permissions they don't have. */
function guard_role_permissions(array $me, ?int $roleId, array $perms): array
{
    $perms = sanitize_permissions($perms);
    if (is_admin($me)) {
        return $perms;
    }
    if ($roleId && (int) $me['role_id'] === $roleId) {
        fail('You cannot change the permissions of your own role', 403);
    }
    foreach ($perms as $module => $actions) {
        foreach ($actions as $a) {
            if (!can($me, $module, $a)) {
                fail("You cannot grant a permission you don't have ($module: $a)", 403);
            }
        }
    }
    return $perms;
}
