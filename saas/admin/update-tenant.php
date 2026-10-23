<?php
/**
 * POST {tenant_id, action, ...} — super admin actions on a shop:
 *   extend {days}            add free days (goodwill, offline payment)
 *   set_plan {plan, cycle}   change the plan (limits and features follow at once)
 *   suspend / activate       put the shop on hold (nobody can log in) or back
 *   offline_payment {plan, cycle, amount, reference}  record a cash / bank payment: extends the plan and makes the invoice
 *   notes {notes}
 */
require_once __DIR__ . '/../_common.php';
allow_methods('POST');
$admin = require_saas_admin();
$d = input();
$t = saas_tenant_by_id((int) ($d['tenant_id'] ?? 0));
if (!$t) {
    fail('Shop not found', 404);
}
$id = (int) $t['tenant_id'];
$action = (string) ($d['action'] ?? '');
switch ($action) {
    case 'extend':
        $days = (int) ($d['days'] ?? 0);
        if ($days < 1 || $days > 730) {
            fail('Days must be 1 to 730');
        }
        $col = $t['status'] === 'trial' ? 'trial_ends_at' : 'current_period_end';
        $from = max(date('Y-m-d'), (string) ($t[$col] ?? date('Y-m-d')));
        saas_q("UPDATE saas_tenants SET $col = ? WHERE tenant_id = ?", [date('Y-m-d', strtotime("$from +$days days")), $id]);
        $msg = "$days days added by {$admin['email']}";
        break;
    case 'set_plan':
        $plan = saas_plan((string) ($d['plan'] ?? ''));
        if (!$plan) {
            fail('Choose a plan');
        }
        saas_q('UPDATE saas_tenants SET plan_code = ?, billing_cycle = ?, store_quota = ? WHERE tenant_id = ?',
            [$plan['code'], ($d['cycle'] ?? $t['billing_cycle']) === 'month' ? 'month' : 'year', max(1, (int) ($d['stores'] ?? $t['store_quota'])), $id]);
        $msg = "Plan set to {$plan['name']} by {$admin['email']}";
        break;
    case 'suspend':
    case 'activate':
        $new = $action === 'suspend' ? 'suspended' : ($t['current_period_end'] ? 'active' : 'trial');
        saas_q('UPDATE saas_tenants SET status = ? WHERE tenant_id = ?', [$new, $id]);
        $msg = ($action === 'suspend' ? 'Put on hold' : 'Activated') . " by {$admin['email']}";
        break;
    case 'offline_payment':
        $plan = saas_plan((string) ($d['plan'] ?? $t['plan_code']));
        if (!$plan) {
            fail('Choose a plan');
        }
        $cycle = ($d['cycle'] ?? 'year') === 'month' ? 'month' : 'year';
        $qty = max(1, (int) ($d['stores'] ?? $t['store_quota']));
        [$amount, $gst, $total] = saas_price($plan, $cycle, $qty);
        $ref = mb_substr(trim((string) ($d['reference'] ?? '')), 0, 60);
        if ($ref === '') {
            fail('Enter the receipt / UTR number of the payment');
        }
        $gw = 'offline_' . bin2hex(random_bytes(6));
        saas_q("INSERT INTO saas_orders (tenant_id, kind, plan_code, billing_cycle, quantity, amount, gst, total, gateway, gateway_order_id) VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$id, 'renewal', $plan['code'], $cycle, $qty, $amount, $gst, $total, 'offline', $gw]);
        saas_complete_order($gw, "offline:$ref");
        $msg = "Offline payment ₹$total ($ref) recorded by {$admin['email']}";
        break;
    case 'notes':
        saas_q('UPDATE saas_tenants SET notes = ? WHERE tenant_id = ?', [mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null, $id]);
        $msg = 'Notes updated';
        break;
    default:
        fail('Unknown action');
}
saas_forget_tenant($t['slug']);
saas_event($id, 'admin', $msg);
json_out(['status' => 'success', 'message' => $msg, 'data' => saas_tenant_public(saas_tenant_by_id($id))]);
