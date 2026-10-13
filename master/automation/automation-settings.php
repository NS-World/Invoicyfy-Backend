<?php
/** GET ?store_id= — automatic messages setup + which channels are ready + last runs. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/automation.php';
allow_methods('GET');
$user = require_auth();

$store = get_store(write_store($user, $_GET['store_id'] ?? null));
$cs = channel_settings_public($store);
$runs = db_all('SELECT job, run_date, ran_at, result FROM job_runs WHERE store_id = ? ORDER BY ran_at DESC LIMIT 30', [$store['store_id']]);
json_out(['status' => 'success', 'data' => [
    'store_id' => (int) $store['store_id'],
    'settings' => automation_settings($store),
    'channels_ready' => ['whatsapp' => $cs['whatsapp']['api_ready'], 'sms' => $cs['sms']['ready'], 'email' => $cs['email']['ready']],
    'cron_last_run' => db_value("SELECT MAX(ran_at) FROM job_runs WHERE job = 'cron'"),
    'server_time' => date('Y-m-d H:i'),
    'runs' => $runs,
]]);
