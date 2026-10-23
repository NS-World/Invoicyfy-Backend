<?php
/** Plan & billing inside a shop (SaaS): the shop's plan, renewals and upgrades. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/saas.php';

/** The shop of this request (control database row), or 404 when the app is not running as SaaS. */
function current_tenant(): array
{
    if (!saas_mode() || empty($GLOBALS['khanak_tenant']['slug'])) {
        json_out(['status' => 'error', 'message' => 'Plans are not used on this installation', 'code' => 'not_saas'], 404);
    }
    $t = saas_tenant_by_slug($GLOBALS['khanak_tenant']['slug']);
    if (!$t) {
        fail('Shop not found', 404);
    }
    return $t;
}
