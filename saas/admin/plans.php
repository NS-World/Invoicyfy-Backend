<?php
/** GET — all plans (also hidden ones) for the super admin. */
require_once __DIR__ . '/../_common.php';
allow_methods('GET');
require_saas_admin();
json_out(['status' => 'success', 'data' => saas_plans(false), 'features' => SAAS_FEATURES]);
