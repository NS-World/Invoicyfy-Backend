<?php
/**
 * Role permissions. Each role stores a JSON map: { "invoices": ["view","add","edit","delete"], ... }.
 * The Admin role always has every permission. Checks run automatically in require_auth() based on the
 * endpoint path and file name, so every endpoint is protected without per-file code.
 */

const PERMISSION_MODULES = [
    'dashboard' => ['label' => 'Dashboard', 'actions' => ['view']],
    'orders' => ['label' => 'Orders', 'actions' => ['view', 'add', 'edit', 'delete']],
    'invoices' => ['label' => 'Invoices & Bills', 'actions' => ['view', 'add', 'edit', 'delete']],
    'payments' => ['label' => 'Payments', 'actions' => ['view', 'add', 'delete']],
    'customers' => ['label' => 'Customers', 'actions' => ['view', 'add', 'edit', 'delete']],
    'products' => ['label' => 'Products', 'actions' => ['view', 'add', 'edit', 'delete']],
    'inventory' => ['label' => 'Inventory', 'actions' => ['view', 'add']],
    'expenses' => ['label' => 'Expenses', 'actions' => ['view', 'add', 'edit', 'delete']],
    'reports' => ['label' => 'Reports', 'actions' => ['view']],
    'settings' => ['label' => 'Business & Invoice Settings', 'actions' => ['view', 'edit']],
    'users' => ['label' => 'Users, Roles & Stores', 'actions' => ['view', 'add', 'edit', 'delete']],
    'marketing' => ['label' => 'Offers & Messages', 'actions' => ['view', 'add', 'edit']],
    'backup' => ['label' => 'Backup & Restore', 'actions' => ['view', 'add']],
    'cash' => ['label' => 'Day Close / Cash Register', 'actions' => ['view', 'add', 'edit']],
    'loyalty' => ['label' => 'Coupons & Loyalty', 'actions' => ['view', 'add', 'edit', 'delete']],
    'activity' => ['label' => 'Activity Log', 'actions' => ['view']],
    'purchases' => ['label' => 'Suppliers & Purchases', 'actions' => ['view', 'add', 'edit', 'delete']],
];

/** Modules added in V3, given to existing roles once so nobody loses access to the new screens after an upgrade. */
const V3_ROLE_DEFAULTS = [
    'Manager' => ['cash' => ['view', 'add', 'edit'], 'loyalty' => ['view', 'add', 'edit', 'delete'], 'activity' => ['view']],
    'Staff' => ['cash' => ['view', 'add', 'edit'], 'loyalty' => ['view']],
];

/** Modules added in V4, given to existing roles once (same idea as V3_ROLE_DEFAULTS). */
const V4_ROLE_DEFAULTS = [
    'Manager' => ['purchases' => ['view', 'add', 'edit', 'delete']],
];

/**
 * Endpoints whose permission is not what the folder/file name would suggest:
 * billing staff must be able to check a coupon, park a bill and read POS settings while billing.
 */
const ENDPOINT_PERMISSION_OVERRIDES = [
    'coupon/check-coupon' => ['invoices', 'add'],
    'invoice/quote-bill' => ['invoices', 'add'],
    'held/held-bills' => ['invoices', 'add'],
    'held/add-held' => ['invoices', 'add'],
    'held/delete-held' => ['invoices', 'add'],
    'pos/pos-settings' => null,
    'pos/update-pos-settings' => ['settings', 'edit'],
    'loyalty/customer-points' => ['invoices', 'add'],
    'credit/dues' => ['payments', 'view'],
    'credit/customer-statement' => ['payments', 'view'],
    'credit/add-payment' => ['payments', 'add'],
    'credit/send-reminders' => ['payments', 'add'],
    'automation/automation-settings' => ['settings', 'view'],
    'automation/update-automation-settings' => ['settings', 'edit'],
    'automation/run-now' => ['settings', 'edit'],
    'automation/job-history' => ['settings', 'view'],
    // V4
    'offer/active-offers' => ['invoices', 'add'],
    'online/mark-settled' => ['invoices', 'edit'],
    'invoice/send-bill' => ['invoices', 'view'],
    'backup/update-drive-settings' => ['backup', 'add'],
    'backup/drive-disconnect' => ['backup', 'add'],
];

const DEFAULT_ROLE_PERMISSIONS = [
    'Manager' => [
        'dashboard' => ['view'], 'orders' => ['view', 'add', 'edit', 'delete'], 'invoices' => ['view', 'add', 'edit', 'delete'],
        'payments' => ['view', 'add', 'delete'], 'customers' => ['view', 'add', 'edit', 'delete'], 'products' => ['view', 'add', 'edit', 'delete'],
        'inventory' => ['view', 'add'], 'expenses' => ['view', 'add', 'edit', 'delete'], 'reports' => ['view'], 'settings' => ['view', 'edit'],
        'marketing' => ['view', 'add', 'edit'], 'cash' => ['view', 'add', 'edit'], 'loyalty' => ['view', 'add', 'edit', 'delete'], 'activity' => ['view'],
        'purchases' => ['view', 'add', 'edit', 'delete'],
    ],
    'Staff' => [
        'dashboard' => ['view'], 'orders' => ['view', 'add', 'edit'], 'invoices' => ['view', 'add'], 'payments' => ['view', 'add'],
        'customers' => ['view', 'add', 'edit'], 'products' => ['view'], 'inventory' => ['view'], 'cash' => ['view', 'add', 'edit'], 'loyalty' => ['view'],
    ],
];

/** Full permission map for a role row (Admin = everything). */
function role_permissions(?string $roleName, ?string $json): array
{
    if (strcasecmp((string) $roleName, 'Admin') === 0) {
        return array_map(fn($m) => $m['actions'], PERMISSION_MODULES);
    }
    $raw = $json ? json_decode($json, true) : null;
    if (!is_array($raw)) {
        $raw = DEFAULT_ROLE_PERMISSIONS[$roleName] ?? [];
    }
    return sanitize_permissions($raw);
}

/** Keep only known modules/actions; "view" is implied by any other action. */
function sanitize_permissions(array $raw): array
{
    $out = [];
    foreach (PERMISSION_MODULES as $module => $def) {
        $actions = array_values(array_intersect($def['actions'], (array) ($raw[$module] ?? [])));
        if ($actions && !in_array('view', $actions, true)) {
            array_unshift($actions, 'view');
        }
        if ($actions) {
            $out[$module] = $actions;
        }
    }
    return $out;
}

function can(array $user, string $module, string $action = 'view'): bool
{
    $perms = $user['permissions'] ?? [];
    return in_array($action, $perms[$module] ?? [], true);
}

function require_permission(array $user, string $module, string $action = 'view'): void
{
    if (!can($user, $module, $action)) {
        $label = PERMISSION_MODULES[$module]['label'] ?? $module;
        fail("You don't have permission to $action $label. Ask your admin to update your role.", 403);
    }
}

/**
 * Work out which permission the current endpoint needs.
 * Returns [module, action] or null when any signed-in user may call it.
 */
function endpoint_permission(string $script, string $method): ?array
{
    $script = str_replace('\\', '/', $script);
    if (!preg_match('#/(master|reports)/([^/]+)/?([^/]*)\.php$#', $script, $m)) {
        return null; // auth/* etc.
    }
    [, $area, $folder, $file] = $m;
    if ($area === 'master' && array_key_exists("$folder/$file", ENDPOINT_PERMISSION_OVERRIDES)) {
        return ENDPOINT_PERMISSION_OVERRIDES["$folder/$file"];
    }
    if ($area === 'master' && $folder === 'archive') {
        // Data & Archive (year archives, purge): Backup & Restore permission; every endpoint is Admin-only as well.
        return ['backup', $method === 'GET' ? 'view' : 'add'];
    }
    if ($area === 'reports') {
        return [$folder === 'dashboard' ? 'dashboard' : 'reports', 'view'];
    }
    // Lookups used by billing forms are open to every signed-in user; writes still need permission.
    $openReads = ['customer', 'product', 'category', 'store', 'settings', 'role'];
    $module = [
        'order' => 'orders', 'order_items' => 'orders', 'invoice' => 'invoices', 'payment' => 'payments',
        'customer' => 'customers', 'product' => 'products', 'category' => 'products', 'inventory' => 'inventory', 'expense' => 'expenses',
        'store' => 'settings', 'settings' => 'settings', 'user' => 'users', 'role' => 'users',
        'campaign' => 'marketing', 'backup' => 'backup',
        'cash' => 'cash', 'coupon' => 'loyalty', 'loyalty' => 'loyalty', 'activity' => 'activity',
        'credit' => 'payments', 'automation' => 'settings', 'pos' => 'settings', 'held' => 'invoices',
        'supplier' => 'purchases', 'purchase' => 'purchases', 'recipe' => 'products', 'batch' => 'inventory',
        'offer' => 'loyalty', 'online' => 'invoices',
    ][$folder] ?? null;
    if (!$module) {
        return null;
    }
    $isRead = $method === 'GET' || preg_match('/^(get|find|.*s$|categories|meta|stock|stock-movements)/', $file) && !preg_match('/^(add|create|update|delete|cancel|adjust|import|send|test|mark|stop)/', $file);
    if ($isRead) {
        return in_array($folder, $openReads, true) ? null : [$module, 'view'];
    }
    if (preg_match('/^(add|create|adjust|import|send|test|mark)/', $file)) {
        $action = 'add';
    } elseif (preg_match('/^(delete|cancel)/', $file)) {
        $action = 'delete';
    } else {
        $action = 'edit';
    }
    // Store records: creating/deleting stores is user administration, editing is settings.
    if ($folder === 'store' && $action !== 'edit') {
        $module = 'users';
    }
    if ($module === 'inventory') {
        $action = 'add';
    }
    return [$module, $action];
}
