<?php
/** POST — work on running jobs for a few seconds (the screen calls this while a job runs) and return the status. */
require_once __DIR__ . '/_common.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
data_jobs_run(8);
json_out(['status' => 'success', 'data' => archive_overview()]);
