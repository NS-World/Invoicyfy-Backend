<?php
/**
 * Khanak SaaS: plans, the features each plan unlocks and which API folders belong to which feature.
 * Prices are in rupees per year / month before GST. The super admin can change prices and limits later
 * (stored in the control database); these are the starting values.
 */

/** Feature key => what the shop owner sees. */
const SAAS_FEATURES = [
    'billing' => 'Billing, GST invoices, POS, Quick Bill, thermal print, offline billing',
    'inventory' => 'Products, categories, stock',
    'reports' => 'Dashboard and reports with Excel export',
    'backup' => 'Backup, restore, Google Drive backup, Data & Archive',
    'orders' => 'Orders, KOT, hold bills, tables',
    'customer_loyalty' => 'Udhaar (credit), loyalty points, coupons',
    'marketing' => 'WhatsApp / SMS / email offers and automatic wishes',
    'purchases' => 'Suppliers, purchase bills, batches and expiry',
    'pro_tools' => 'Recipes, combo and happy-hour offers, online orders',
    'pro_reports' => 'Owner dashboard, staff report, GSTR-1',
];

/** API path prefix (relative to the API root) => feature it needs. Paths not listed are part of every plan. */
const SAAS_FEATURE_PATHS = [
    'master/order/' => 'orders', 'master/order_items/' => 'orders', 'master/held/' => 'orders',
    'master/credit/' => 'customer_loyalty', 'master/loyalty/' => 'customer_loyalty', 'master/coupon/' => 'customer_loyalty',
    'master/campaign/' => 'marketing', 'master/automation/' => 'marketing',
    'master/supplier/' => 'purchases', 'master/purchase/' => 'purchases', 'master/batch/' => 'purchases',
    'master/recipe/' => 'pro_tools', 'master/offer/' => 'pro_tools', 'master/online/' => 'pro_tools',
    'reports/dashboard/owner.php' => 'pro_reports', 'reports/staff.php' => 'pro_reports', 'reports/gstr1.php' => 'pro_reports',
];

const SAAS_BASE_FEATURES = ['billing', 'inventory', 'reports', 'backup'];

/** Starting plans (max_users / max_stores 0 = unlimited). Chain is priced per store. */
const SAAS_DEFAULT_PLANS = [
    ['code' => 'starter', 'name' => 'Starter', 'price_year' => 2999, 'price_month' => 299, 'per_store' => 0, 'max_users' => 2, 'max_stores' => 1,
     'features' => [], 'tagline' => 'One counter, simple billing', 'sort' => 1],
    ['code' => 'growth', 'name' => 'Growth', 'price_year' => 5999, 'price_month' => 599, 'per_store' => 0, 'max_users' => 5, 'max_stores' => 1,
     'features' => ['orders', 'customer_loyalty', 'marketing', 'purchases'], 'tagline' => 'Most shops choose this', 'sort' => 2],
    ['code' => 'pro', 'name' => 'Pro', 'price_year' => 11999, 'price_month' => 1199, 'per_store' => 0, 'max_users' => 15, 'max_stores' => 3,
     'features' => ['orders', 'customer_loyalty', 'marketing', 'purchases', 'pro_tools', 'pro_reports'], 'tagline' => 'Up to 3 outlets, every tool', 'sort' => 3],
    ['code' => 'chain', 'name' => 'Chain', 'price_year' => 9999, 'price_month' => 0, 'per_store' => 1, 'max_users' => 0, 'max_stores' => 0,
     'features' => ['orders', 'customer_loyalty', 'marketing', 'purchases', 'pro_tools', 'pro_reports'], 'tagline' => 'Franchise and chains, per store', 'sort' => 4],
];

/** Feature needed by an API script (path relative to the API root), or null when every plan has it. */
function saas_feature_for_path(string $rel): ?string
{
    foreach (SAAS_FEATURE_PATHS as $prefix => $feature) {
        if (strncmp($rel, $prefix, strlen($prefix)) === 0) {
            return $feature;
        }
    }
    return null;
}
