<?php
/** GET — Data & Archive screen: years with data, archive / purge status, settings, running jobs and the log of past jobs. */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);
json_out(['status' => 'success', 'data' => archive_overview()]);
