<?php
/** GET ?search=&status= — every shop with its plan, status and validity. */
require_once __DIR__ . '/../_common.php';
allow_methods('GET');
require_saas_admin();
$where = '1=1';
$p = [];
$q = trim((string) ($_GET['search'] ?? ''));
if ($q !== '') {
    $where .= ' AND (slug LIKE ? OR business_name LIKE ? OR email LIKE ? OR phone LIKE ? OR city LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($p, $like, $like, $like, $like, $like);
}
$rows = [];
foreach (saas_q("SELECT * FROM saas_tenants WHERE $where ORDER BY tenant_id DESC LIMIT 500", $p)->fetchAll() as $t) {
    $pub = saas_tenant_public($t);
    if (!empty($_GET['status']) && $pub['status'] !== $_GET['status']) {
        continue;
    }
    $rows[] = ['tenant_id' => (int) $t['tenant_id'], 'city' => $t['city'], 'created_at' => $t['created_at'], 'db_name' => $t['db_name'],
               'plan_code' => $t['plan_code'], 'plan_name' => $pub['plan']['name'] ?? $t['plan_code']] + $pub;
}
json_out(['status' => 'success', 'total' => count($rows), 'data' => $rows]);
