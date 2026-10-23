<?php
/** GET — plans for the pricing page, with every feature's name. */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
json_out(['status' => 'success', 'data' => saas_plans(), 'features' => SAAS_FEATURES, 'gst_rate' => saas_gst_rate()]);
