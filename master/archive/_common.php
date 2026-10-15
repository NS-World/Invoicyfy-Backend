<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cascade.php';
require_once __DIR__ . '/../../lib/datajobs.php';

/** Everything the Data & Archive screen shows. */
function archive_overview(): array
{
    $s = retention_settings();
    $ds = drive_settings();
    $cut = purge_cutoff_fy($s);
    return [
        'settings' => $s,
        'current_fy' => fy_label(fy_current()),
        'purge_before' => $cut !== null ? fy_range($cut)[0] : null,
        'drive' => ['connected' => $ds['refresh_token'] !== '', 'email' => $ds['email'], 'nightly' => (bool) $ds['enabled'], 'time' => $ds['time'],
            'keep_daily' => (int) $ds['keep'], 'keep_monthly' => (int) $ds['keep_monthly'], 'last_result' => $ds['last_result'], 'last_ok' => $ds['last_ok'], 'last_run_at' => $ds['last_run_at']],
        'years' => data_years_overview(),
        'open_jobs' => data_jobs_open(),
        'jobs' => array_map('data_job_row', db_all('SELECT * FROM data_jobs ORDER BY job_id DESC LIMIT 50')),
        'logs' => logs_preview(),
    ];
}
